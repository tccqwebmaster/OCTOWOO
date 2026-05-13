<?php
/**
 * SEO migrator.
 *
 * Reads OpenCart SEO URL entries (oc_seo_url) and:
 *  1. Updates WooCommerce product/category slugs to match OC keywords.
 *  2. Writes 301 redirect rules so old OC URLs resolve to new WC URLs.
 *
 * Redirect strategies (both can be active simultaneously):
 *  A) .htaccess – writes rules in a managed block (Apache only).
 *  B) WordPress option-based redirects via a rewrite endpoint that fires
 *     an early redirect when the old path is requested.
 *
 * OpenCart 3.x URL patterns handled:
 *   product_id=X     → /product/{slug}/
 *   category_id=X    → /product-category/{slug}/
 *   information_id=X → /page/{slug}/    (static pages)
 *   (others are logged and skipped)
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class SeoMigrator extends AbstractMigrator {

    private const KEY = 'seo';

    /**
     * In-memory map of old OC path → new WC path (for batch .htaccess write).
     * @var array<string, string>
     */
    private array $redirect_map = [];

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        $pfx        = $this->pfx();
        $resume_id  = $this->checkpoint->getLastId( self::KEY );
        $demo_limit = max( 0, (int) ( $this->config['migration']['demo_limit'] ?? 0 ) );
        $batch_size = max( 1, (int) ( $this->config['migration']['batch_size'] ?? 50 ) );
        $chunk_mode = $this->batch->isChunkMode();

        if ( $resume_id === PHP_INT_MAX ) {
            $this->logger->info( '[seo] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'is_done' => true ];
        }

        // Check if oc_seo_url table exists.
        $table_exists = $this->oc->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            [ $pfx . 'seo_url' ]
        );

        if ( ! $table_exists ) {
            $this->logger->warning( '[seo] oc_seo_url table not found – skipping SEO migration.' );
            $this->checkpoint->init( self::KEY, 0 );
            $this->checkpoint->start( self::KEY );
            $this->checkpoint->complete( self::KEY );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'is_done' => true ];
        }

        $stats = [ 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'is_done' => false ];

        // Count total rows (re-queried each chunk so resumable even after table changes).
        $total = (int) $this->oc->fetchColumn(
            "SELECT COUNT(*) FROM `{$pfx}seo_url`
             WHERE store_id = 0 AND language_id = ? AND keyword != ''",
            [ $this->langId() ]
        );

        if ( $demo_limit > 0 ) {
            $total = min( $total, $demo_limit );
        }

        // Offset from checkpoint.
        $offset = $this->checkpoint->getProcessedCount( self::KEY );

        // First chunk: initialise checkpoint row.
        if ( $offset === 0 ) {
            $this->checkpoint->init( self::KEY, $total );
            $this->checkpoint->start( self::KEY );
            $this->logger->info( "[seo] Starting SEO migration: total={$total}, chunk_mode=" . ( $chunk_mode ? 'yes' : 'no' ) );

            // Critical: warn if WordPress permalink structure is "Plain".
            // When Plain, get_permalink() / get_term_link() return ?post_type=product&p=ID
            // so redirect targets stored in wp_options and .htaccess will be wrong.
            // User MUST go to WP Admin → Settings → Permalinks → Post name → Save first.
            if ( empty( get_option( 'permalink_structure' ) ) ) {
                $this->logger->warning(
                    '[seo] WordPress permalink structure is set to "Plain". ' .
                    'Product URLs will appear as ?post_type=product&p=ID instead of pretty URLs. ' .
                    'Go to WP Admin → Settings → Permalinks → select "Post name" → Save Changes, ' .
                    'then use the "Rerun SEO Migrator" button to rebuild correct redirects.'
                );
            }
        }

        if ( $offset >= $total ) {
            $this->checkpoint->complete( self::KEY );
            $stats['is_done'] = true;
            return $stats;
        }

        // In chunk mode process one page; in full (sync / CLI) mode loop to completion.
        $single_pass = $chunk_mode;

        while ( $offset < $total ) {
            $limit = $single_pass ? min( $batch_size, $total - $offset ) : min( $batch_size, $total - $offset );

            $rows = $this->oc->fetchAll(
                "SELECT seo_url_id, query, keyword
                 FROM `{$pfx}seo_url`
                 WHERE store_id = 0 AND language_id = ?
                   AND keyword != ''
                 ORDER BY seo_url_id ASC
                 LIMIT {$limit} OFFSET {$offset}",
                [ $this->langId() ]
            );

            if ( empty( $rows ) ) {
                break;
            }

            $last_id    = 0;
            $batch_done = 0;

            foreach ( $rows as $row ) {
                $last_id = max( $last_id, (int) ( $row['seo_url_id'] ?? 0 ) );
                $result  = $this->processSeoRow( $row );
                if ( $result === true ) {
                    $stats['processed']++;
                } elseif ( $result === false ) {
                    $stats['failed']++;
                } else {
                    $stats['skipped']++;
                }
                $batch_done++;
            }

            $offset += $batch_done;
            $this->checkpoint->update( self::KEY, $last_id, $batch_done );

            // Persist wp_options redirects after every chunk so partial progress
            // is never lost. The htaccess block is only (re-)written on the final
            // chunk so we don't thrash the filesystem on every batch.
            $this->persistRedirectsWpOnly();

            if ( $single_pass ) {
                break;
            }
        }

        if ( $offset >= $total ) {
            // Final chunk: reload ALL accumulated redirects from wp_options (every
            // previous chunk persisted its rules there via persistRedirectsWpOnly())
            // and merge with the current chunk before writing .htaccess.
            // Without this, only the last chunk's rules appear in the htaccess block.
            $all_saved = get_option( 'octowoo_redirects', [] );
            if ( is_array( $all_saved ) && ! empty( $all_saved ) ) {
                // Current chunk rules take precedence (overwrite any stale entries).
                $this->redirect_map = array_merge( $all_saved, $this->redirect_map );
            }

            $this->writeHtaccessRedirects();

            $this->checkpoint->complete( self::KEY );
            $this->logger->success(
                "[seo] Done. processed={$stats['processed']}, skipped={$stats['skipped']}, failed={$stats['failed']}"
            );
            $stats['is_done'] = true;
        }

        return $stats;
    }

    /**
     * Persist only the wp_options redirect map (safe to call on every chunk).
     * .htaccess is written separately on the final chunk.
     */
    private function persistRedirectsWpOnly(): void {
        if ( empty( $this->redirect_map ) ) {
            return;
        }

        if ( $this->config['seo']['use_wp_redirects'] ?? true ) {
            $this->saveWpRedirects();
        }

        $this->logger->debug( '[seo] Persisted ' . count( $this->redirect_map ) . ' redirect rules to wp_options.' );
    }

    // ── Per-row processing ────────────────────────────────────────────────────

    /**
     * @return bool|null  true = processed, false = failed, null = skipped.
     */
    private function processSeoRow( array $row ): ?bool {
        $query   = trim( $row['query'] );

        // sanitize_title() strips Arabic/Unicode characters entirely.
        // sanitize_title_with_dashes() with context 'save' percent-encodes Unicode
        // (e.g. Arabic منتج → %d9%85%d9%86%d8%aa%d8%ac) which is a valid WP slug
        // and resolves correctly in browsers as the native-script character.
        $keyword = sanitize_title_with_dashes( rawurldecode( trim( $row['keyword'] ) ), '', 'save' );

        if ( empty( $keyword ) ) {
            return null;
        }

        // Match supported query patterns.
        if ( preg_match( '/^product_id=(\d+)$/', $query, $m ) ) {
            return $this->handleProductSeo( (int) $m[1], $keyword );
        }

        if ( preg_match( '/^category_id=(\d+)$/', $query, $m ) ) {
            return $this->handleCategorySeo( (int) $m[1], $keyword );
        }

        if ( preg_match( '/^information_id=(\d+)$/', $query, $m ) ) {
            return $this->handleInformationSeo( (int) $m[1], $keyword );
        }

        // Route, manufacturer, etc. – log and skip.
        $this->logger->debug( "[seo] Unhandled query type: {$query}" );
        return null;
    }

    // ── Entity handlers ───────────────────────────────────────────────────────

    private function handleProductSeo( int $oc_id, string $slug ): ?bool {
        $wc_id = $this->checkpoint->getWcId( 'product', $oc_id );

        if ( ! $wc_id ) {
            $this->logger->debug( "[seo] Product OC #{$oc_id} not migrated yet; queuing slug for later." );
            return null;
        }

        $post = get_post( $wc_id );
        if ( ! $post ) {
            return false;
        }

        // Update WC product slug.
        $old_slug = $post->post_name;
        if ( $old_slug !== $slug ) {
            if ( ! $this->isDry() ) {
                wp_update_post( [ 'ID' => $wc_id, 'post_name' => $slug ] );
            }
            $this->logger->info( "[seo] Updated product #{$wc_id} slug: [{$old_slug}] → [{$slug}]" );
        }

        // Build redirect from old OC URL to new WC URL.
        $old_path = "/index.php?route=product/product&product_id={$oc_id}";

        // Clear per-post cache so get_permalink reflects the just-updated slug.
        clean_post_cache( $wc_id );
        $new_url = get_permalink( $wc_id );

        // Fallback: when WP permalink structure is "Plain", get_permalink() returns
        // ?post_type=product&p=ID instead of a pretty URL. Build the correct URL
        // directly from the slug so redirect targets are always accurate.
        if ( ! $new_url || false !== strpos( (string) $new_url, '?' ) ) {
            $wc_permalinks = function_exists( 'wc_get_permalink_structure' ) ? wc_get_permalink_structure() : [];
            $product_base  = trim( $wc_permalinks['product_base'] ?? '/product', '/' );
            // Strip any %product_cat%/ prefix (category-based product URL structure).
            $product_base  = (string) preg_replace( '#%[^%]+%/?#', '', $product_base );
            $product_base  = trim( $product_base, '/' ) ?: 'product';
            $new_url       = trailingslashit( home_url( '/' . $product_base . '/' . $slug ) );
        }

        if ( $new_url ) {
            // Pattern 1: Old OC query-string URL.
            $this->redirect_map[ $old_path ] = $new_url;
            // Pattern 2: Old OC SEO keyword path (e.g. /my-product).
            $this->redirect_map[ '/' . ltrim( $slug, '/' ) ] = $new_url;
            // Pattern 3: OC 2.x style with route prefix.
            $this->redirect_map[ "/index.php?route=product/product&path=&product_id={$oc_id}" ] = $new_url;
            // Pattern 4: With .html suffix (some OC themes).
            $this->redirect_map[ '/' . ltrim( $slug, '/' ) . '.html' ] = $new_url;
        }

        return true;
    }

    private function handleCategorySeo( int $oc_id, string $slug ): ?bool {
        $wc_term_id = $this->checkpoint->getWcId( 'category', $oc_id );

        if ( ! $wc_term_id ) {
            $this->logger->debug( "[seo] Category OC #{$oc_id} not migrated yet." );
            return null;
        }

        $term = get_term( $wc_term_id, 'product_cat' );
        if ( ! $term || is_wp_error( $term ) ) {
            return false;
        }

        $old_slug = $term->slug;
        if ( $old_slug !== $slug ) {
            if ( ! $this->isDry() ) {
                wp_update_term( $wc_term_id, 'product_cat', [ 'slug' => $slug ] );
            }
            $this->logger->info( "[seo] Updated category #{$wc_term_id} slug: [{$old_slug}] → [{$slug}]" );
        }

        $old_path = "/index.php?route=product/category&path={$oc_id}";
        $new_url  = get_term_link( $wc_term_id, 'product_cat' );

        // Fallback: when WP permalink structure is "Plain", get_term_link() returns
        // a query-string URL. Build the correct URL from the slug directly.
        if ( is_wp_error( $new_url ) || false !== strpos( (string) $new_url, '?' ) ) {
            $wc_permalinks = function_exists( 'wc_get_permalink_structure' ) ? wc_get_permalink_structure() : [];
            $cat_base      = trim( $wc_permalinks['category_base'] ?? '/product-category', '/' );
            $cat_base      = trim( $cat_base, '/' ) ?: 'product-category';
            $new_url       = trailingslashit( home_url( '/' . $cat_base . '/' . $slug ) );
        }

        if ( $new_url && ! is_wp_error( $new_url ) ) {
            // Pattern 1: Old OC query-string URL.
            $this->redirect_map[ $old_path ] = $new_url;
            // Pattern 2: Old OC SEO keyword path.
            $this->redirect_map[ '/' . ltrim( $slug, '/' ) ] = $new_url;
            // Pattern 3: OC path-based URL (oc_seo_url query = category_id=X, but URL was /path/X).
            $this->redirect_map[ "/index.php?route=product/category&path={$oc_id}" ] = $new_url;
            // Pattern 4: With .html suffix.
            $this->redirect_map[ '/' . ltrim( $slug, '/' ) . '.html' ] = $new_url;
        }

        return true;
    }

    private function handleInformationSeo( int $oc_id, string $slug ): ?bool {
        // Try to find a matching WP page by octowoo meta.
        global $wpdb;
        $page_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_octowoo_oc_information_id' AND meta_value = %d
                 LIMIT 1",
                $oc_id
            )
        );

        $new_url = $page_id ? get_permalink( (int) $page_id ) : home_url( '/' . $slug . '/' );

        $this->redirect_map[ "/index.php?route=information/information&information_id={$oc_id}" ] = $new_url;

        return true;
    }

    // ── Redirect persistence ──────────────────────────────────────────────────

    /**
     * Persist the collected redirect map using both configured strategies.
     * Called once at the very end of the migration (after all chunks).
     */
    private function persistRedirects(): void {
        if ( empty( $this->redirect_map ) ) {
            return;
        }

        // Save to WP options for the early-redirect filter.
        if ( $this->config['seo']['use_wp_redirects'] ?? true ) {
            $this->saveWpRedirects();
        }

        // Write .htaccess rules.
        if ( $this->config['seo']['write_htaccess'] ?? true ) {
            $this->writeHtaccessRedirects();
        }

        $this->logger->info( '[seo] Persisted ' . count( $this->redirect_map ) . ' redirect rules.' );
    }

    /**
     * Store the redirect map in wp_options and register the early-redirect hook.
     */
    private function saveWpRedirects(): void {
        if ( $this->isDry() ) {
            $this->logger->debug( '[DRY-RUN] Would save ' . count( $this->redirect_map ) . ' WP redirects.' );
            return;
        }

        // Merge with any previously saved redirects.
        $existing = get_option( 'octowoo_redirects', [] );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }

        $merged = array_merge( $existing, $this->redirect_map );
        update_option( 'octowoo_redirects', $merged, false );

        // Ensure the template_redirect hook is registered.
        if ( ! has_action( 'template_redirect', [ __CLASS__, 'handleWpRedirect' ] ) ) {
            add_action( 'template_redirect', [ __CLASS__, 'handleWpRedirect' ] );
        }
    }

    /**
     * WordPress hook: fires on every front-end request.
     * Checks if the current request matches a stored OC redirect path.
     */
    public static function handleWpRedirect(): void {
        $redirects = get_option( 'octowoo_redirects', [] );

        if ( empty( $redirects ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $raw_uri      = $_SERVER['REQUEST_URI'] ?? '';
        $request_uri  = (string) parse_url( $raw_uri, PHP_URL_PATH );
        $query_string = (string) ( parse_url( $raw_uri, PHP_URL_QUERY ) ?? '' );
        $full_path    = $request_uri . ( $query_string !== '' ? '?' . $query_string : '' );

        // Normalise paths: strip double slashes, decode percent-encoded chars for lookup.
        $norm_path = rtrim( (string) preg_replace( '#//+#', '/', $request_uri ), '/' ) ?: '/';

        // Priority order: full path with query string → exact path → normalised path.
        $candidates = array_unique( array_filter( [
            $full_path,
            $request_uri,
            $norm_path,
            $norm_path . '/',
            rawurldecode( $full_path ),
            rawurldecode( $request_uri ),
        ] ) );

        foreach ( $candidates as $candidate ) {
            if ( isset( $redirects[ $candidate ] ) && $redirects[ $candidate ] ) {
                wp_safe_redirect( esc_url_raw( $redirects[ $candidate ] ), 301 );
                exit;
            }
        }
    }

    /**
     * Write Apache-compatible 301 RewriteRule directives into .htaccess.
     */
    private function writeHtaccessRedirects(): void {
        if ( $this->isDry() ) {
            $this->logger->debug( '[DRY-RUN] Would write .htaccess redirect rules.' );
            return;
        }

        $htaccess = ABSPATH . '.htaccess';

        if ( ! file_exists( $htaccess ) || ! is_writable( $htaccess ) ) {
            $this->logger->warning( "[seo] .htaccess not writable at [{$htaccess}] – skipping." );
            return;
        }

        $rules = [ 'RewriteEngine On' ];

        foreach ( $this->redirect_map as $old => $new ) {
            // Escape special chars in the old path for use in a regex.
            $escaped_old = preg_quote( ltrim( $old, '/' ), '#' );
            // Destination must be a full URL for external redirect.
            $safe_new    = esc_url_raw( $new );

            if ( $safe_new ) {
                $rules[] = "RewriteRule ^{$escaped_old}$ {$safe_new} [R=301,L]";
            }
        }

        $rule_block = implode( "\n", $rules );

        insert_with_markers( $htaccess, 'OctoWoo Redirects', $rule_block );

        $this->logger->info( '[seo] Wrote ' . ( count( $rules ) - 1 ) . ' redirect rules to .htaccess.' );
    }
}
