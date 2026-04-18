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
        $pfx       = $this->pfx();
        $resume_id = $this->checkpoint->getLastId( self::KEY );
        $demo_limit = max( 0, (int) ( $this->config['migration']['demo_limit'] ?? 0 ) );

        if ( $resume_id === PHP_INT_MAX ) {
            $this->logger->info( '[seo] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
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
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        $stats = [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];

        // Fetch all SEO URL rows for the primary language.
        $rows = $this->oc->fetchAll(
            "SELECT seo_url_id, query, keyword
             FROM `{$pfx}seo_url`
             WHERE store_id = 0 AND language_id = ?
               AND keyword != ''
             ORDER BY seo_url_id ASC",
            [ $this->langId() ]
        );

                if ( $demo_limit > 0 && count( $rows ) > $demo_limit ) {
                        $rows = array_slice( $rows, 0, $demo_limit );
                        $this->logger->info( "[seo] Demo limit active: processing first {$demo_limit} rows." );
                }

        $this->checkpoint->init( self::KEY, count( $rows ) );
        $this->checkpoint->start( self::KEY );

                $last_id = 0;

        foreach ( $rows as $row ) {
            $last_id = max( $last_id, (int) ( $row['seo_url_id'] ?? 0 ) );
            $result = $this->processSeoRow( $row );
            if ( $result === true ) {
                $stats['processed']++;
            } elseif ( $result === false ) {
                $stats['failed']++;
            } else {
                $stats['skipped']++;
            }
        }

        $this->checkpoint->update( self::KEY, $last_id, count( $rows ) );

        // Write all collected redirects to persistent storage.
        $this->persistRedirects();

        $this->checkpoint->complete( self::KEY );
        $this->logger->success(
            "[seo] Done. processed={$stats['processed']}, skipped={$stats['skipped']}, failed={$stats['failed']}"
        );

        return $stats;
    }

    // ── Per-row processing ────────────────────────────────────────────────────

    /**
     * @return bool|null  true = processed, false = failed, null = skipped.
     */
    private function processSeoRow( array $row ): ?bool {
        $query   = trim( $row['query'] );
        $keyword = sanitize_title( $row['keyword'] );

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
        $oc_shop_url = rtrim( $this->config['opencart']['shop_url'] ?? '', '/' );
        $old_path    = "/index.php?route=product/product&product_id={$oc_id}";
        $new_url     = get_permalink( $wc_id );

        if ( $new_url ) {
            $this->redirect_map[ $old_path ]         = $new_url;
            // Also handle the SEO URL form.
            $this->redirect_map[ '/' . $old_slug ]   = $new_url;
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

        if ( ! is_wp_error( $new_url ) ) {
            $this->redirect_map[ $old_path ]       = $new_url;
            $this->redirect_map[ '/' . $old_slug ] = $new_url;
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

        // Build the request path.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $request_uri  = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
        $query_string = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY );
        $full_path    = $request_uri . ( $query_string ? '?' . $query_string : '' );

        // Check exact match first.
        if ( isset( $redirects[ $full_path ] ) ) {
            wp_safe_redirect( $redirects[ $full_path ], 301 );
            exit;
        }

        // Check path without query string.
        if ( isset( $redirects[ $request_uri ] ) ) {
            wp_safe_redirect( $redirects[ $request_uri ], 301 );
            exit;
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
