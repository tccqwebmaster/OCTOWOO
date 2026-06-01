<?php
/**
 * WPML / Polylang multilingual integration.
 *
 * This class runs as a post-migration pass (registered in MIGRATOR_ORDER as
 * the last step) that:
 *
 *  1. Queries every product, category, and page that was already migrated
 *     (from the octowoo_id_map table).
 *  2. Fetches the secondary-language data stored on each WP entity by the
 *     primary migrator (e.g. _octowoo_title_ar, _octowoo_description_ar).
 *  3. Creates a translated WP post / term in the secondary language.
 *  4. Links the primary and secondary entities using WPML or Polylang APIs.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * Supported multilingual plugins:
 *   • WPML (Multilingual CMS) – SitePress integration via action hooks.
 *   • Polylang / Polylang Pro  – PLL_* function integration.
 *
 * When neither plugin is active the integration is a no-op; it will log a
 * warning and return immediately.
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Coverage:
 *   ✔ Products (post_type = product)
 *   ✔ Product categories (taxonomy = product_cat)
 *   ✔ Information pages   (post_type = page)
 *
 * Config keys used:
 *   multilingual.enabled            – master switch
 *   multilingual.primary_locale     – e.g. "en"   (WPML language code)
 *   multilingual.secondary_locale   – e.g. "ar"
 *   multilingual.use_wpml           – true to prefer WPML even if Polylang is installed
 *   multilingual.use_polylang       – true to prefer Polylang
 *
 * @package OctoWoo\Integration
 */

namespace OctoWoo\Integration;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table names from $wpdb->prefix are safe; %i identifier escaping requires WP 6.2+ above our minimum target.

use OctoWoo\Core\DatabaseConnector;
use OctoWoo\Core\Logger;
use OctoWoo\Core\CheckpointManager;
use OctoWoo\Core\BatchProcessor;
use OctoWoo\Migrators\AbstractMigrator;
use OctoWoo\Migrators\ImageMigrator;

defined( 'ABSPATH' ) || exit;

class WpmlIntegration extends AbstractMigrator {

    private const KEY = 'multilingual';

    /** Detected adapter: 'wpml', 'polylang', or 'none'. */
    private string $adapter = 'none';

    /** Primary language code (e.g. 'en'). */
    private string $primary_lang = 'en';

    /** Secondary language code (e.g. 'ar'). */
    private string $secondary_lang = 'ar';

    /**
     * Collected secondary-language SEO redirects, flushed to wp_options at the
     * end of each translatePosts() pass (keyed old-path => new-URL).
     *
     * @var array<string, string>
     */
    private array $pending_sec_redirects = [];

    /** Lazy-initialised ImageMigrator used for image re-import fallback. */
    private ?ImageMigrator $image_migrator = null;

    /**
     * Pre-fetched secondary + primary language tags, keyed by OC product_id.
     * Populated by prefetchSecLangTagsForProducts() once per chunk to avoid N+1 OC DB queries.
     *
     * Format: [ oc_id => [ 'sec' => 'tag1,tag2', 'pri' => 'tag1,tag2' ] ]
     *
     * @var array<int, array{sec: string, pri: string}>
     */
    private array $sec_tags_cache = [];
    private array $sec_desc_cache = []; // Keyed by OC product_id → description row array

    /**
     * Memoised active brand taxonomy slug. detectActiveBrandTaxonomy() runs up to
     * 7 taxonomy_exists() probes; without this it was re-run for every product
     * inside copyProductDataToTranslation(). Resolved once per request.
     *
     * @var string|null  null = not yet resolved, '' = none found.
     */
    private ?string $brand_tax_cache = null;

    /**
     * Per-request cache of secondary-language tag translation work.
     * Tag terms are SHARED across products, so creating/linking/WPML-registering
     * them once per product (per tag) was an N×M explosion against the heaviest
     * WPML API call. Keyed by secondary tag-name → resolved secondary term_id.
     *
     * @var array<string, int>
     */
    private array $tag_xlate_cache = [];

    /** name → product_tag term_id (direct-DB find-or-create cache). */
    private array $tag_term_cache = [];
    /** name → [term_id, slug] for primary-language product_tag lookups. */
    private array $pri_tag_cache = [];

    // ── Entry point (implements AbstractMigrator::migrate) ────────────────────

    public function migrate(): array {
        global $wpdb;

        // ── Guards ────────────────────────────────────────────────────────────
        if ( empty( $this->config['multilingual']['enabled'] ) && empty( $this->config['migration']['run_multilingual'] ) ) {
            $this->logger->info( '[multilingual] Disabled — skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'is_done' => true ];
        }
        if ( $this->onDuplicate() !== 'update' && $this->checkpoint->isCompleted( self::KEY ) ) {
            $this->logger->info( '[multilingual] Already completed — skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'is_done' => true ];
        }

        // ── Setup ─────────────────────────────────────────────────────────────
        $this->primary_lang   = $this->config['multilingual']['primary_locale']  ?? 'en';
        $this->secondary_lang = $this->config['multilingual']['secondary_locale'] ?? 'ar';
        $this->adapter        = $this->detectAdapter();
        $this->resolveLanguageCodes();

        if ( $this->adapter === 'none' ) {
            $this->logger->warning( '[multilingual] Neither WPML nor Polylang active — skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'is_done' => true ];
        }

        $this->logger->info( "[multilingual] Using adapter: {$this->adapter}. Primary: {$this->primary_lang} | Secondary: {$this->secondary_lang}" );

        // Suppress WC hooks — we use direct $wpdb writes.
        wp_suspend_cache_invalidation( true );
        wp_defer_term_counting( true );
        remove_all_actions( 'woocommerce_update_product' );
        remove_all_actions( 'woocommerce_new_product' );
        remove_all_actions( 'save_post_product' );

        $terms_key   = 'octowoo_ml_terms_v2';
        $terms_state = get_option( $terms_key, [] );
        if ( ! is_array( $terms_state ) ) {
            $terms_state = [];
        }
        $terms_done   = ! empty( $terms_state['done'] );
        $brand_tax    = $this->detectActiveBrandTaxonomy();
        $sfx          = $this->secLangSuffix();
        $processed    = 0;
        $skipped      = 0;
        $failed       = 0;

        // ── Phase 1: Terms (categories + brands) — 10 per chunk, OC queries needed ──
        if ( ! $terms_done ) {
            if ( empty( $terms_state['inited'] ) ) {
                $total = $this->countUntranslated( $wpdb );
                $this->checkpoint->init( self::KEY, $total );
                $this->checkpoint->start( self::KEY );
                $terms_state['inited']    = true;
                $terms_state['cat_off']   = 0;
                $terms_state['brand_off'] = 0;
                $terms_state['prod_done'] = 0;
            }

            // Categories
            if ( ( $terms_state['cat_off'] ?? 'done' ) !== 'done' ) {
                $cat_seo = $this->fetchSecondaryCategorySeoMap();
                [ $p, $s, $f, $more ] = $this->translateTerms( 'product_cat', $cat_seo, 'category', (int) $terms_state['cat_off'], 10 );
                $processed += $p; $skipped += $s; $failed += $f;
                $terms_state['cat_off'] = $more ? (int) $terms_state['cat_off'] + 10 : 'done';
                if ( $more ) {
                    update_option( $terms_key, $terms_state, false );
                    $this->logger->info( "[multilingual] Categories chunk done (offset={$terms_state['cat_off']}). More remain." );
                    return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'is_done' => false ];
                }
                $this->logger->info( '[multilingual] Categories complete.' );
            }

            // Brands
            if ( $brand_tax !== '' && ( $terms_state['brand_off'] ?? 'done' ) !== 'done' ) {
                [ $p, $s, $f, $more ] = $this->translateTerms( $brand_tax, [], 'manufacturer', (int) $terms_state['brand_off'], 10 );
                $processed += $p; $skipped += $s; $failed += $f;
                $terms_state['brand_off'] = $more ? (int) $terms_state['brand_off'] + 10 : 'done';
                if ( $more ) {
                    update_option( $terms_key, $terms_state, false );
                    $this->logger->info( "[multilingual] Brands chunk done (offset={$terms_state['brand_off']}). More remain." );
                    return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'is_done' => false ];
                }
                $this->logger->info( '[multilingual] Brands complete.' );
            }

            $terms_state['done'] = true;
            update_option( $terms_key, $terms_state, false );
            $total = $this->countUntranslated( $wpdb );
            $this->logger->info( "[multilingual] Terms done. Products remaining: {$total}" );
            return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'is_done' => false ];
        }

        // ── Phase 2: Products — chunked, pure WP postmeta, zero OC queries ──
        // One-time backfill: flag every English product that ALREADY has an Arabic
        // twin with '_octowoo_has_translation' so the fast indexed query below sees
        // them as done. Pays the slow legacy scan exactly once per run; the LEFT JOIN
        // guard makes it idempotent even if it somehow runs again. Without this, the
        // first run after upgrading would re-translate the thousands already done.
        if ( empty( $terms_state['hastrans_backfilled'] ) ) {
            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                 SELECT DISTINCT CAST(ps.meta_value AS UNSIGNED), '_octowoo_has_translation', '1'
                 FROM {$wpdb->postmeta} ps
                 LEFT JOIN {$wpdb->postmeta} pf
                     ON pf.post_id = CAST(ps.meta_value AS UNSIGNED)
                    AND pf.meta_key = '_octowoo_has_translation'
                 WHERE ps.meta_key = '_octowoo_translation_of'
                   AND ps.meta_value REGEXP '^[0-9]+$'
                   AND pf.post_id IS NULL"
            );
            $terms_state['hastrans_backfilled'] = true;
            update_option( $terms_key, $terms_state, false );
            $this->logger->info( '[multilingual] Backfilled _octowoo_has_translation flags for existing translations.' );
        }

        $total = $this->countUntranslated( $wpdb );

        if ( $total > 0 ) {
            // Chunk size must be small enough that a full chunk — including the
            // end-of-chunk checkpoint update + redirect flush below — finishes
            // inside PHP max_execution_time. A 500-row chunk of fully-decorated
            // products (meta + taxonomies + tags + WPML linking) routinely
            // exceeded 30–60s on shared hosting, so the process was killed before
            // the checkpoint/flush ran: each chunk paid the full 500-row fetch but
            // committed only a fraction, dragging the run out for days. A small
            // chunk that COMPLETES every time is far faster end-to-end.
            $product_chunk = (int) ( $this->config['multilingual']['product_chunk'] ?? 40 );
            if ( $product_chunk < 1 )   { $product_chunk = 40; }
            if ( $product_chunk > 200 ) { $product_chunk = 200; }

            // Fetch the next batch of untranslated English originals.
            // Indexed (post_id, meta_key) lookups — no LONGTEXT scan.
            //   • skip products already flagged as translated
            //   • skip the Arabic posts themselves (they carry _octowoo_translation_of)
            $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "SELECT p.ID AS wc_id, COALESCE(pm.meta_value, 0) AS oc_id
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_octowoo_oc_id'
                     WHERE p.post_type = 'product'
                       AND p.post_status IN ('publish','draft')
                       AND NOT EXISTS (
                           SELECT 1 FROM {$wpdb->postmeta} pf
                           WHERE pf.post_id = p.ID AND pf.meta_key = '_octowoo_has_translation'
                       )
                       AND NOT EXISTS (
                           SELECT 1 FROM {$wpdb->postmeta} pt
                           WHERE pt.post_id = p.ID AND pt.meta_key = '_octowoo_translation_of'
                       )
                     ORDER BY p.ID ASC LIMIT %d",
                    $product_chunk
                ),
                ARRAY_A
            );

            if ( ! empty( $rows ) ) {
                // Prefetch tags from OC (lightweight string query only).
                $oc_ids = array_filter( array_map( fn( $r ) => (int) $r['oc_id'], $rows ) );
                if ( $oc_ids ) {
                    $this->prefetchSecLangTagsForProducts( $oc_ids );
                }

                [ $p, $s, $f ] = $this->translatePostsFromRows(
                    $rows, 'product',
                    '_octowoo_name' . $sfx,
                    '_octowoo_description' . $sfx,
                    $this->fetchSecondaryLangSeoMap()
                );
                $processed += $p; $skipped += $s; $failed += $f;

                $done_so_far = ( (int) ( $terms_state['prod_done'] ?? 0 ) ) + count( $rows );
                $terms_state['prod_done'] = $done_so_far;
                update_option( $terms_key, $terms_state, false );
                $this->checkpoint->update( self::KEY, $done_so_far, count( $rows ) );
                $this->logger->info( "[multilingual] Products chunk done: {$done_so_far} translated, {$total} remaining, batch={$p} ok/{$f} fail" );

                wp_suspend_cache_invalidation( false );
                wp_defer_term_counting( false );
                return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'is_done' => false ];
            }
        }

        // ── Phase 3: Pages + completion ───────────────────────────────────────
        [ $p, $s, $f ] = $this->translatePosts( 'page', '_octowoo_title' . $sfx, '_octowoo_desc' . $sfx );
        $processed += $p; $skipped += $s; $failed += $f;

        $fix_taxes  = array_filter( [ 'product_cat', $brand_tax ?: null ] );
        $slug_fixed = $this->autoFixTempSlugs( $fix_taxes );
        if ( $slug_fixed > 0 ) { $this->logger->info( "[multilingual] Auto-fixed {$slug_fixed} temp slug(s)." ); }

        wp_suspend_cache_invalidation( false );
        wp_defer_term_counting( false );
        wp_cache_flush();
        flush_rewrite_rules( false );
        delete_option( $terms_key );
        $this->checkpoint->complete( self::KEY );
        $this->logger->info( "[multilingual] ✔ Complete. processed={$processed} skipped={$skipped} failed={$failed}" );
        return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'is_done' => true ];
    }

    // ── Count untranslated products ───────────────────────────────────────────
    // Fast: both NOT EXISTS subqueries hit the postmeta (post_id, meta_key) index.
    // The old query compared the unindexed LONGTEXT meta_value to p.ID, forcing a
    // full scan that grew slower with every translation created (minutes per call
    // once thousands existed). '_octowoo_has_translation' is a flag set on every
    // English product that has an Arabic twin; '_octowoo_translation_of' marks the
    // Arabic posts themselves so they are never re-fed into the translation loop.
    private function countUntranslated( \wpdb $wpdb ): int {
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish','draft')
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pf
                   WHERE pf.post_id = p.ID AND pf.meta_key = '_octowoo_has_translation'
               )
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pt
                   WHERE pt.post_id = p.ID AND pt.meta_key = '_octowoo_translation_of'
               )"
        );
    }

    private function rebuildProductIdMapFromMeta(): void {
        global $wpdb;

        $this->logger->info( '[multilingual] id_map is empty – rebuilding product map from postmeta (_octowoo_oc_id).' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            "SELECT pm.meta_value AS oc_id, pm.post_id AS wc_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_octowoo_oc_id'
               AND p.post_type = 'product'
               AND p.post_status != 'trash'",
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            $this->logger->warning( '[multilingual] No WC products with _octowoo_oc_id meta found. Cannot rebuild id_map.' );
            return;
        }

        $table   = $wpdb->prefix . 'octowoo_id_map';
        $run_id  = 'rebuilt-' . gmdate( 'Ymd' );
        $count   = 0;

        foreach ( $rows as $row ) {
            $oc_id = (int) $row['oc_id'];
            $wc_id = (int) $row['wc_id'];
            if ( $oc_id <= 0 || $wc_id <= 0 ) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO `{$table}` (entity_type, oc_id, wc_id, run_id)
                     VALUES ('product', %d, %d, %s)
                     ON DUPLICATE KEY UPDATE wc_id = VALUES(wc_id), run_id = VALUES(run_id)",
                    $oc_id,
                    $wc_id,
                    $run_id
                )
            );
            $count++;
        }

        $this->logger->info( "[multilingual] Rebuilt id_map: {$count} product entries restored." );
    }

    private function prefetchSecLangTagsForProducts( array $oc_ids ): void {
        if ( empty( $oc_ids ) ) {
            return;
        }

        $sec_lang_id = $this->langIdSecondary();
        $pri_lang_id = $this->langId();

        $pfx         = $this->pfx();
        $placeholders = implode( ',', array_fill( 0, count( $oc_ids ), '?' ) );

        // If language_id_secondary = 0 (not configured), auto-detect by fetching
        // the first language_id that is not the primary language.
        if ( $sec_lang_id === 0 ) {
            $all_langs = $this->oc->fetchAll(
                "SELECT DISTINCT language_id FROM `{$pfx}product_description`
                  WHERE product_id = ? ORDER BY language_id ASC",
                [ $oc_ids[0] ]
            );
            foreach ( $all_langs as $lr ) {
                if ( (int) $lr['language_id'] !== $pri_lang_id ) {
                    $sec_lang_id = (int) $lr['language_id'];
                    $this->logger->info( "[multilingual] Auto-detected secondary language_id={$sec_lang_id} for tags (language_id_secondary not configured)." );
                    break;
                }
            }
        }

        if ( $sec_lang_id === 0 ) {
            $this->logger->warning( '[multilingual] Cannot determine secondary language ID for tag fetch — only one language in oc_product_description?' );
            return;
        }

        // Fetch secondary-language tags for the whole batch.
        $sec_rows = $this->oc->fetchAll(
            "SELECT product_id, `tag`
             FROM `{$pfx}product_description`
             WHERE product_id IN ({$placeholders}) AND language_id = ?",
            array_merge( $oc_ids, [ $sec_lang_id ] )
        );

        // Fallback: if the configured secondary_lang_id returned no rows at all,
        // try the first non-primary language ID for these products.
        if ( empty( $sec_rows ) && count( $oc_ids ) > 0 ) {
            $alt_lang = $this->oc->fetchColumn(
                "SELECT DISTINCT language_id FROM `{$pfx}product_description`
                  WHERE product_id = ? AND language_id != ? ORDER BY language_id ASC LIMIT 1",
                [ $oc_ids[0], $pri_lang_id ]
            );
            if ( $alt_lang && (int) $alt_lang !== $sec_lang_id ) {
                $sec_lang_id = (int) $alt_lang;
                $sec_rows    = $this->oc->fetchAll(
                    "SELECT product_id, `tag`
                     FROM `{$pfx}product_description`
                     WHERE product_id IN ({$placeholders}) AND language_id = ?",
                    array_merge( $oc_ids, [ $sec_lang_id ] )
                );
                $this->logger->info( "[multilingual] Fallback secondary language_id={$sec_lang_id} used for tag fetch." );
            }
        }

        // Fetch primary-language tags for the whole batch.
        $pri_rows = $this->oc->fetchAll(
            "SELECT product_id, `tag`
             FROM `{$pfx}product_description`
             WHERE product_id IN ({$placeholders}) AND language_id = ?",
            array_merge( $oc_ids, [ $pri_lang_id ] )
        );

        // Index by product_id.
        $sec_index = [];
        foreach ( $sec_rows as $r ) {
            $sec_index[ (int) $r['product_id'] ] = (string) $r['tag'];
        }
        $pri_index = [];
        foreach ( $pri_rows as $r ) {
            $pri_index[ (int) $r['product_id'] ] = (string) $r['tag'];
        }

        $this->sec_tags_cache = [];
        foreach ( $oc_ids as $oc_id ) {
            $this->sec_tags_cache[ $oc_id ] = [
                'sec' => $sec_index[ $oc_id ] ?? '',
                'pri' => $pri_index[ $oc_id ] ?? '',
            ];
        }
    }

    /**
     * Bulk-fetch secondary language descriptions for a batch of OC product IDs.
     * Stores results in $this->sec_desc_cache keyed by OC product_id.
     * Eliminates N+1 remote OC DB queries in translatePostsFromRows().
     *
     * @param int[] $oc_ids
     */
    private function prefetchSecDescriptionsFromOC( array $oc_ids ): void {
        if ( empty( $oc_ids ) ) {
            return;
        }
        $this->sec_desc_cache = [];
        try {
            $pfx         = $this->pfx();
            $pri_lid     = $this->langId();
            $sec_lid     = $this->langIdSecondary();
            $placeholders = implode( ',', array_fill( 0, count( $oc_ids ), '?' ) );

            // Fetch ALL non-primary language rows for the entire batch in ONE query.
            $all_rows = $this->oc->fetchAll(
                "SELECT product_id, language_id, name, description, meta_title, meta_description, meta_keyword, tag
                 FROM `{$pfx}product_description`
                 WHERE product_id IN ({$placeholders}) AND language_id != ?
                 ORDER BY product_id ASC, language_id ASC",
                array_merge( $oc_ids, [ $pri_lid ] )
            );

            // Group by product_id.
            $grouped = [];
            foreach ( (array) $all_rows as $row ) {
                $grouped[ (int) $row['product_id'] ][] = $row;
            }

            // For each product, apply same 4-tier priority as fetchSecDescriptionFromOC().
            foreach ( $oc_ids as $oc_id ) {
                $rows = $grouped[ $oc_id ] ?? [];
                if ( empty( $rows ) ) {
                    $this->sec_desc_cache[ $oc_id ] = null;
                    continue;
                }

                $selected = null;

                // Priority 1: configured sec lang + Arabic content.
                foreach ( $rows as $row ) {
                    if ( $sec_lid > 0 && (int) $row['language_id'] === $sec_lid
                         && preg_match( '/[\x{0600}-\x{06FF}]/u', $row['name'] . $row['description'] ) ) {
                        $selected = $row;
                        break;
                    }
                }
                // Priority 2: any Arabic row.
                if ( ! $selected ) {
                    foreach ( $rows as $row ) {
                        if ( preg_match( '/[\x{0600}-\x{06FF}]/u', $row['name'] . $row['description'] ) ) {
                            $selected = $row;
                            break;
                        }
                    }
                }
                // Priority 3: configured sec lang (even if not Arabic).
                if ( ! $selected ) {
                    foreach ( $rows as $row ) {
                        if ( $sec_lid > 0 && (int) $row['language_id'] === $sec_lid ) {
                            $selected = $row;
                            break;
                        }
                    }
                }
                // Priority 4: first available non-primary row.
                if ( ! $selected ) {
                    $selected = $rows[0];
                }

                $this->sec_desc_cache[ $oc_id ] = $selected;
            }
        } catch ( \Throwable $e ) {
            $this->logger->warning( '[multilingual] prefetchSecDescriptionsFromOC failed: ' . $e->getMessage() );
        }
    }

    /**
     * Translate posts for a pre-fetched set of id_map rows.
     * Extracted from translatePosts() to support chunked (OFFSET-based) iteration.
     *
     * @param  array[] $rows             Rows from octowoo_id_map (oc_id, wc_id).
     * @param  string  $post_type
     * @param  string  $title_meta_key
     * @param  string  $content_meta_key
     * @param  array   $sec_seo_map      [ oc_id => slug ]
     * @return int[]  [processed, skipped, failed]
     */
    private function translatePostsFromRows(
        array  $rows,
        string $post_type,
        string $title_meta_key,
        string $content_meta_key,
        array  $sec_seo_map = []
    ): array {
        global $wpdb;
        $processed = 0;
        $skipped   = 0;
        $failed    = 0;
        $sfx       = $this->secLangSuffix();

        if ( empty( $rows ) ) {
            return [ $processed, $skipped, $failed ];
        }

        // ── Bulk load postmeta + posts (2 queries for entire batch) ───────────
        $batch_ids = array_map( fn( $r ) => (int) $r['wc_id'], $rows );
        $meta_keys = [ $title_meta_key, $content_meta_key, '_octowoo_short_description' . $sfx, '_sku' ];
        $id_ph     = implode( ',', array_fill( 0, count( $batch_ids ), '%d' ) );
        $key_ph    = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

        $raw_meta = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id IN ({$id_ph}) AND meta_key IN ({$key_ph})",
                array_merge( $batch_ids, $meta_keys )
            ),
            ARRAY_A
        );
        $meta = [];
        foreach ( (array) $raw_meta as $r ) {
            $meta[ (int) $r['post_id'] ][ $r['meta_key'] ] = $r['meta_value'];
        }

        $raw_posts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT ID, post_title, post_content, post_excerpt, post_status,
                        post_type, post_name, post_author, menu_order
                 FROM {$wpdb->posts} WHERE ID IN ({$id_ph})",
                $batch_ids
            ),
            ARRAY_A
        );
        $posts = [];
        foreach ( (array) $raw_posts as $r ) {
            $posts[ (int) $r['ID'] ] = new \WP_Post( (object) $r );
        }

        // ── Process each product ──────────────────────────────────────────────
        foreach ( $rows as $row ) {
            $primary_id = (int) $row['wc_id'];
            $primary    = $posts[ $primary_id ] ?? null;
            if ( ! $primary ) { $failed++; continue; }

            // Get secondary-language content from postmeta (set by ProductMigrator).
            // No OC DB queries — content is already in WP.
            $sec_title   = (string) ( $meta[ $primary_id ][ $title_meta_key   ] ?? '' );
            $sec_content = (string) ( $meta[ $primary_id ][ $content_meta_key ] ?? '' );
            $sec_excerpt = (string) ( $meta[ $primary_id ][ '_octowoo_short_description' . $sfx ] ?? '' );

            // Fall back to English if secondary language content is empty.
            if ( $sec_title   === '' ) { $sec_title   = $primary->post_title;   }
            if ( $sec_content === '' ) { $sec_content = $primary->post_content;  }
            if ( $sec_excerpt === '' ) { $sec_excerpt = $primary->post_excerpt;  }

            $existing_id = $this->getExistingTranslationId( $primary_id, 'post_' . $post_type );

            if ( $existing_id > 0 && get_post_status( $existing_id ) !== false ) {
                // ── Update existing translation ───────────────────────────────
                $this->verifyAndFixTranslationLanguage( $existing_id, $primary_id, $post_type );

                $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $wpdb->posts,
                    [
                        'post_title'        => $sec_title,
                        'post_content'      => $sec_content,
                        'post_excerpt'      => $sec_excerpt,
                        'post_modified'     => current_time( 'mysql' ),
                        'post_modified_gmt' => current_time( 'mysql', true ),
                    ],
                    [ 'ID' => $existing_id ]
                );
                clean_post_cache( $existing_id );
                $this->copyProductDataToTranslation( $primary_id, $existing_id );
                $this->linkPostTranslation( $primary_id, $existing_id, $post_type );
                $this->applyYoastPostMeta( $primary_id, $existing_id );

                if ( isset( $sec_seo_map[ (int) $row['oc_id'] ] ) ) {
                    $this->queueSecondaryLangRedirect( $existing_id, $sec_seo_map[ (int) $row['oc_id'] ] );
                }
                update_post_meta( $existing_id, '_octowoo_translation_of',   $primary_id );
                update_post_meta( $existing_id, '_octowoo_translation_lang',  $this->secondary_lang );
                update_post_meta( $primary_id,  '_octowoo_has_translation',   1 );
                $u_sku  = (string) ( $meta[ $primary_id ]['_sku'] ?? '' );
                $this->logger->info( sprintf(
                    '[multilingual] Updated product #%d ← #%d | SKU: %s | %s',
                    $existing_id, $primary_id, $u_sku !== '' ? $u_sku : '—', $primary->post_title
                ) );
                $processed++;
            } else {
                // ── Create new translation ────────────────────────────────────
                $new_id = $this->createTranslatedPost( $primary, $sec_title, $sec_content, $post_type, $sec_excerpt );
                if ( ! $new_id ) { $failed++; continue; }

                $this->copyProductDataToTranslation( $primary_id, $new_id );
                $this->linkPostTranslation( $primary_id, $new_id, $post_type );
                $this->applyYoastPostMeta( $primary_id, $new_id );

                if ( isset( $sec_seo_map[ (int) $row['oc_id'] ] ) ) {
                    $this->queueSecondaryLangRedirect( $new_id, $sec_seo_map[ (int) $row['oc_id'] ] );
                }
                update_post_meta( $new_id, '_octowoo_translation_of',   $primary_id );
                update_post_meta( $new_id, '_octowoo_translation_lang',  $this->secondary_lang );
                update_post_meta( $primary_id, '_octowoo_has_translation', 1 );

                $c_sku = (string) ( $meta[ $primary_id ]['_sku'] ?? '' );
                $this->logger->info( sprintf(
                    '[multilingual] Created product #%d (%s) ← #%d | SKU: %s | %s',
                    $new_id, $this->secondary_lang, $primary_id,
                    $c_sku !== '' ? $c_sku : '—', $primary->post_title
                ) );
                $processed++;
            }
        }

        $this->flushSecondaryLangRedirects();
        return [ $processed, $skipped, $failed ];
    }

    // ── Verify and fix icl_translations / Polylang language for existing posts ─
    private function verifyAndFixTranslationLanguage( int $trans_id, int $primary_id, string $post_type ): void {
        global $wpdb;
        if ( $this->adapter === 'wpml' ) {
            $icl = $wpdb->prefix . 'icl_translations';
            $row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "SELECT id, language_code, trid FROM `{$icl}` WHERE element_id=%d AND element_type=%s LIMIT 1",
                $trans_id, 'post_' . $post_type
            ), ARRAY_A );
            $primary_trid = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "SELECT trid FROM `{$icl}` WHERE element_id=%d AND element_type LIKE 'post_%' LIMIT 1", $primary_id ) );
            if ( $row ) {
                if ( $row['language_code'] !== $this->secondary_lang || (int) $row['trid'] !== $primary_trid ) {
                    $wpdb->update( $icl, // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        [ 'language_code' => $this->secondary_lang, 'source_language_code' => $this->primary_lang, 'trid' => $primary_trid ],
                        [ 'id' => (int) $row['id'] ]
                    );
                }
            } elseif ( $primary_trid ) {
                $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "INSERT INTO `{$icl}` (element_type,element_id,trid,language_code,source_language_code) VALUES(%s,%d,%d,%s,%s) ON DUPLICATE KEY UPDATE trid=VALUES(trid),language_code=VALUES(language_code)",
                    'post_' . $post_type, $trans_id, $primary_trid, $this->secondary_lang, $this->primary_lang
                ) );
            }
        } elseif ( $this->adapter === 'polylang' ) {
            if ( function_exists( 'pll_get_post_language' ) && pll_get_post_language( $trans_id ) !== $this->secondary_lang ) {
                if ( function_exists( 'pll_set_post_language' ) ) { pll_set_post_language( $trans_id, $this->secondary_lang ); }
                if ( function_exists( 'pll_save_post_translations' ) ) {
                    pll_save_post_translations( [ $this->primary_lang => $primary_id, $this->secondary_lang => $trans_id ] );
                }
            }
        }
    }

    private function translatePosts( string $post_type, string $title_meta_key, string $content_meta_key, array $sec_seo_map = [] ): array {
        global $wpdb;

        // Determine entity_type string used in id_map.
        $entity_type = $post_type === 'product' ? 'product' : 'information';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT oc_id, wc_id FROM {$wpdb->prefix}octowoo_id_map WHERE entity_type = %s",
                $entity_type
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [ 0, 0, 0 ];
        }

        return $this->translatePostsFromRows( $rows, $post_type, $title_meta_key, $content_meta_key, $sec_seo_map );
    }

    /**
     * Create a translated WP post in the secondary language.
     */
    private function createTranslatedPost( \WP_Post $source, string $title, string $content, string $post_type, string $excerpt = '' ): int {
        global $wpdb;
        // Always use the primary-language slug so secondary-language URLs stay clean
        // (e.g. /ar/product/apple-cable/ instead of /ar/product/%d8%a7%d8%a8%d9%84-...).
        $slug = $source->post_name;

        // Avoid metadata contamination – create a plain duplicate.
        $insert_data = [
            'post_title'     => $title,
            'post_content'   => $content ?: $source->post_content,
            'post_excerpt'   => $excerpt,
            'post_status'    => $source->post_status,
            'post_type'      => $source->post_type,
            'post_name'      => $slug,
            'post_author'    => $source->post_author,
            'menu_order'     => $source->menu_order,
        ];

        // Switch to the secondary language BEFORE wp_insert_post so WPML's save_post
        // hook auto-registers this post in the secondary language immediately during creation.
        // Without this switch, WPML assigns the new post to the current admin language
        // ('en'), then linkPostTranslation must re-assign it to the secondary language —
        // which triggers WPML field-sync that copies the primary post_content back, erasing
        // the secondary-language description.  This is the same pattern used in createTranslatedTerm().
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            do_action( 'wpml_switch_language', $this->secondary_lang );
        }
        // Direct DB INSERT — 10-20x faster than wp_insert_post() which fires
        // save_post, WC product hooks, stock recalc, and transient flush per post.
        //
        // NOTE: For Polylang, we still use direct $wpdb->insert() but then call
        // pll_set_post_language() + pll_save_post_translations() via linkPostTranslation()
        // because Polylang stores language in its own table (not wp_postmeta), so
        // bypassing save_post is safe — Polylang API handles its own DB writes.
        // For WPML we insert into icl_translations directly (see below).
        $now     = current_time( 'mysql' );
        $now_gmt = current_time( 'mysql', true );
        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->posts,
            [
                'post_title'        => $insert_data['post_title'],
                'post_content'      => $insert_data['post_content'],
                'post_excerpt'      => $insert_data['post_excerpt'],
                'post_status'       => $insert_data['post_status'],
                'post_type'         => $insert_data['post_type'],
                'post_name'         => $insert_data['post_name'],
                'post_author'       => $insert_data['post_author'],
                'menu_order'        => $insert_data['menu_order'],
                'post_date'         => $now,
                'post_date_gmt'     => $now_gmt,
                'post_modified'     => $now,
                'post_modified_gmt' => $now_gmt,
                'comment_status'    => 'closed',
                'ping_status'       => 'closed',
                'post_parent'       => 0,
                'guid'              => '',
            ]
        );
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            do_action( 'wpml_switch_language', null ); // Restore default language.
        }

        $new_id = (int) $wpdb->insert_id;
        if ( ! $new_id ) {
            $this->logger->error( "[multilingual] Failed creating translated post ({$this->secondary_lang}): " . $wpdb->last_error );
            return 0;
        }
        clean_post_cache( $new_id );

        // Since we used $wpdb->insert() instead of wp_insert_post(), language hooks
        // never fired. Register the post with the correct language plugin directly.
        if ( $this->adapter === 'wpml' && defined( 'ICL_SITEPRESS_VERSION' ) ) {
            // WPML: insert icl_translations row directly (ON DUPLICATE KEY UPDATE = idempotent).
            $icl_table    = $wpdb->prefix . 'icl_translations';
            $element_type = 'post_' . $source->post_type;
            $primary_trid = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "SELECT trid FROM `{$icl_table}` WHERE element_id = %d AND element_type LIKE 'post_%' LIMIT 1",
                (int) $source->ID
            ) );
            if ( $primary_trid ) {
                $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "INSERT INTO `{$icl_table}` (element_type, element_id, trid, language_code, source_language_code)
                     VALUES (%s, %d, %d, %s, %s)
                     ON DUPLICATE KEY UPDATE trid=VALUES(trid), language_code=VALUES(language_code), source_language_code=VALUES(source_language_code)",
                    $element_type, $new_id, $primary_trid, $this->secondary_lang, $this->primary_lang
                ) );
            }
        } elseif ( $this->adapter === 'polylang' ) {
            // Polylang: use pll_* API — it manages its own tables (no icl_translations).
            if ( function_exists( 'pll_set_post_language' ) ) {
                pll_set_post_language( $new_id, $this->secondary_lang );
            }
            if ( function_exists( 'pll_save_post_translations' ) ) {
                pll_save_post_translations( [
                    $this->primary_lang   => (int) $source->ID,
                    $this->secondary_lang => $new_id,
                ] );
            }
        }
        // Copy Yoast SEO meta for secondary language.
        // Fall back to primary-language values when secondary meta is absent so the translated post
        // always has meaningful Yoast data instead of blank fields.
        $sfx           = $this->secLangSuffix();
        $sec_meta_title = (string) get_post_meta( (int) $source->ID, '_octowoo_metatitle' . $sfx, true );
        $sec_meta_desc  = (string) get_post_meta( (int) $source->ID, '_octowoo_metadesc'  . $sfx, true );
        $sec_meta_kw    = (string) get_post_meta( (int) $source->ID, '_octowoo_metakw'    . $sfx, true );

        if ( $sec_meta_title === '' ) {
            $sec_meta_title = (string) get_post_meta( (int) $source->ID, '_yoast_wpseo_title', true );
        }
        if ( $sec_meta_desc === '' ) {
            $sec_meta_desc = (string) get_post_meta( (int) $source->ID, '_yoast_wpseo_metadesc', true );
        }
        if ( $sec_meta_kw === '' ) {
            $sec_meta_kw = (string) get_post_meta( (int) $source->ID, '_yoast_wpseo_focuskw', true );
        }

        if ( $sec_meta_title ) {
            update_post_meta( $new_id, '_yoast_wpseo_title',   $sec_meta_title );
        }
        if ( $sec_meta_desc ) {
            update_post_meta( $new_id, '_yoast_wpseo_metadesc', $sec_meta_desc );
        }
        if ( $sec_meta_kw ) {
            update_post_meta( $new_id, '_yoast_wpseo_focuskw', $sec_meta_kw );
        }

        // Mark as a translation.
        update_post_meta( $new_id, '_octowoo_translation_of', $source->ID );
        update_post_meta( $new_id, '_octowoo_translation_lang', $this->secondary_lang );

        return (int) $new_id;
    }

    /**
     * Force secondary-language content and thumbnail onto a translation post via direct DB writes
     * that bypass all WordPress/WPML/Polylang hooks.
     *
     * WPML field-sync (fired by wpml_set_element_language_details or save_post) can
     * copy the primary-language post_content back over the secondary content we set — erasing it.
     * WPML Media Translation can also clear _thumbnail_id when it looks for a secondary-language
     * attachment translation that does not exist.
     *
     * Writing directly to wp_posts and wp_postmeta then busting the object cache is
     * the same technique used by fixTranslationSlug() for post_name and is guaranteed
     * to survive any plugin hook because it runs AFTER all those hooks have fired.
     *
     * @param int    $post_id   Translated post ID.
     * @param string $title     Secondary-language post title.
     * @param string $content   Secondary-language post content (HTML).
     * @param string $excerpt   Secondary-language short description / post_excerpt.
     * @param int    $thumb_id  Featured image attachment ID from the primary product.
     */
    private function forceTranslationContent( int $post_id, string $title, string $content, string $excerpt, int $thumb_id ): void {
        global $wpdb;

        // Direct write to wp_posts — bypasses save_post, WPML field-sync, and
        // every other plugin hook that could overwrite the secondary-language content.
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->posts,
            [
                'post_title'   => $title,
                'post_content' => $content,
                'post_excerpt' => $excerpt,
            ],
            [ 'ID' => $post_id ]
        );
        clean_post_cache( $post_id );

        // Re-apply thumbnail after WPML language linking which may have cleared
        // _thumbnail_id if WPML Media Translation treats it as translatable and
        // no secondary-language attachment exists (returns null → no image on translated page).
        if ( $thumb_id > 0 ) {
            update_post_meta( $post_id, '_thumbnail_id', $thumb_id );
        }
    }

    /**
     * Force a post's slug (post_name) to exactly $desired_slug, bypassing
     * WordPress's wp_unique_post_slug() uniqueness check.
     *
     * Why this is needed: when wp_insert_post() runs for the secondary-language translation,
     * WordPress sees the primary-language post already has the same slug and appends "-2",
     * producing ugly URLs like /ar/product/zelda-switch-2/.
     *
     * This must be called AFTER linkPostTranslation() so WPML already knows the
     * post is in the secondary language. WPML then routes it under /ar/ making
     * the full URL unique — we just need the post_name to be identical.
     *
     * We write directly to wp_posts and bust the object cache; no hooks fire.
     */
    private function fixTranslationSlug( int $post_id, string $desired_slug ): void {
        global $wpdb;
        if ( $desired_slug === '' ) {
            return;
        }
        $current = get_post_field( 'post_name', $post_id );
        if ( $current === $desired_slug ) {
            return; // Already correct — nothing to do.
        }
        global $wpdb;
        $wpdb->update( $wpdb->posts, [ 'post_name' => $desired_slug ], [ 'ID' => $post_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        clean_post_cache( $post_id );
        $this->logger->debug( "[multilingual] Slug fixed for post #{$post_id}: '{$current}' → '{$desired_slug}'" );
    }

    /**
     * Force a term's slug to $desired_slug, bypassing WordPress's uniqueness check.
     *
     * wp_update_term() / wp_insert_term() reject a slug already used by another
     * term in the same taxonomy — even if the other term is in a different WPML
     * language. We write directly to wp_terms and bust the term cache so WPML
     * can route both terms under their respective language prefixes using the
     * same slug (e.g. /product-category/electronics-in-qatar/ vs
     * /ar/product-category/electronics-in-qatar/).
     */
    private function fixTranslationTermSlug( int $term_id, string $desired_slug ): void {
        global $wpdb;
        if ( $desired_slug === '' || $term_id <= 0 ) {
            return;
        }
        $term = get_term( $term_id );
        if ( ! $term || is_wp_error( $term ) || $term->slug === $desired_slug ) {
            return; // Already correct — nothing to do.
        }
        global $wpdb;
        $wpdb->update( $wpdb->terms, [ 'slug' => $desired_slug ], [ 'term_id' => $term_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        clean_term_cache( $term_id );
        $this->logger->debug( "[multilingual] Term slug fixed for term #{$term_id}: '{$term->slug}' → '{$desired_slug}'" );
    }

    // ── Yoast SEO meta helpers ─────────────────────────────────────────────────

    /**
     * Write Yoast SEO meta (title, metadesc, focuskw) to a secondary-language translated post.
     * Reads secondary-language _octowoo_* meta from the primary post; falls back to the
     * primary-language Yoast values so the translated post always has complete SEO data.
     */
    private function applyYoastPostMeta( int $primary_id, int $translated_id ): void {
        $sfx   = $this->secLangSuffix();
        $title = (string) get_post_meta( $primary_id, '_octowoo_metatitle' . $sfx, true );
        $desc  = (string) get_post_meta( $primary_id, '_octowoo_metadesc'  . $sfx, true );
        $kw    = (string) get_post_meta( $primary_id, '_octowoo_metakw'    . $sfx, true );

        if ( $title === '' ) { $title = (string) get_post_meta( $primary_id, '_yoast_wpseo_title',    true ); }
        if ( $desc  === '' ) { $desc  = (string) get_post_meta( $primary_id, '_yoast_wpseo_metadesc', true ); }
        if ( $kw    === '' ) { $kw    = (string) get_post_meta( $primary_id, '_yoast_wpseo_focuskw',  true ); }

        if ( $title ) { update_post_meta( $translated_id, '_yoast_wpseo_title',   $title ); }
        if ( $desc )  { update_post_meta( $translated_id, '_yoast_wpseo_metadesc', $desc ); }
        if ( $kw )    { update_post_meta( $translated_id, '_yoast_wpseo_focuskw',  $kw ); }
    }

    /**
     * Write Yoast SEO meta to a secondary-language translated term.
     * Reads secondary-language _octowoo_* meta from the primary term; falls back to primary-language values.
     */
    private function applyYoastTermMeta( int $primary_term_id, int $translated_term_id ): void {
        $sfx   = $this->secLangSuffix();
        $title = (string) get_term_meta( $primary_term_id, '_octowoo_metatitle' . $sfx, true );
        $desc  = (string) get_term_meta( $primary_term_id, '_octowoo_metadesc'  . $sfx, true );
        $kw    = (string) get_term_meta( $primary_term_id, '_octowoo_metakw'    . $sfx, true );

        if ( $title === '' ) { $title = (string) get_term_meta( $primary_term_id, '_yoast_wpseo_title',    true ); }
        if ( $desc  === '' ) { $desc  = (string) get_term_meta( $primary_term_id, '_yoast_wpseo_metadesc', true ); }
        if ( $kw    === '' ) { $kw    = (string) get_term_meta( $primary_term_id, '_yoast_wpseo_focuskw',  true ); }

        if ( $title ) { update_term_meta( $translated_term_id, '_yoast_wpseo_title',   $title ); }
        if ( $desc )  { update_term_meta( $translated_term_id, '_yoast_wpseo_metadesc', $desc ); }
        if ( $kw )    { update_term_meta( $translated_term_id, '_yoast_wpseo_focuskw',  $kw ); }
    }

    // ── Secondary-language SEO redirects ──────────────────────────────────────

    /**
     * Pre-fetch all secondary-language SEO keywords from oc_seo_url indexed by
     * OC product_id.  Used to map old OpenCart secondary-language product paths to new WC
     * secondary-language URLs.
     *
     * Returns an empty array when the secondary language is disabled, the
     * oc_seo_url table does not exist, or no secondary-language rows are found.
     *
     * @return array<int, string>  [ oc_product_id => sanitised_slug ]
     */
    private function fetchSecondaryLangSeoMap(): array {
        $lang_id_sec = $this->langIdSecondary();
        if ( $lang_id_sec === 0 ) {
            return [];
        }

        $pfx = $this->pfx();

        // Guard: table may not exist on older OC installs.
        $table_exists = $this->oc->fetchColumn(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [ $pfx . 'seo_url' ]
        );
        if ( ! $table_exists ) {
            return [];
        }

        $rows = $this->oc->fetchAll(
            "SELECT query, keyword
             FROM `{$pfx}seo_url`
             WHERE store_id = 0 AND language_id = ? AND keyword != ''",
            [ $lang_id_sec ]
        );

        $map = [];
        foreach ( $rows as $row ) {
            if ( preg_match( '/^product_id=(\d+)$/', $row['query'], $m ) ) {
                $map[ (int) $m[1] ] = sanitize_title( $row['keyword'] );
            }
        }

        $this->logger->debug( '[multilingual] Fetched ' . count( $map ) . ' secondary-language SEO keywords for redirect mapping.' );

        return $map;
    }

    /**
     * Pre-fetch all secondary-language SEO keywords for categories from
     * oc_seo_url indexed by OC category_id.
     *
     * @return array<int, string>  [ oc_category_id => sanitised_slug ]
     */
    private function fetchSecondaryCategorySeoMap(): array {
        $lang_id_sec = $this->langIdSecondary();
        if ( $lang_id_sec === 0 ) {
            return [];
        }

        $pfx = $this->pfx();

        $table_exists = $this->oc->fetchColumn(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [ $pfx . 'seo_url' ]
        );
        if ( ! $table_exists ) {
            return [];
        }

        $rows = $this->oc->fetchAll(
            "SELECT query, keyword
             FROM `{$pfx}seo_url`
             WHERE store_id = 0 AND language_id = ? AND keyword != ''",
            [ $lang_id_sec ]
        );

        $map = [];
        foreach ( $rows as $row ) {
            if ( preg_match( '/^category_id=(\d+)$/', $row['query'], $m ) ) {
                $map[ (int) $m[1] ] = sanitize_title( $row['keyword'] );
            }
        }

        $this->logger->debug( '[multilingual] Fetched ' . count( $map ) . ' secondary-language SEO keywords for category redirect mapping.' );

        return $map;
    }

    /**
     * Collect a secondary-language SEO redirect into the pending batch.
     *
     * Old path  = /{secondary_lang}/{oc_keyword}  (e.g. /ar/some-product-slug)
     * New URL   = WPML-aware permalink of the translated post.
     */
    private function queueSecondaryLangRedirect( int $translated_id, string $oc_keyword ): void {
        // Use WPML's permalink filter so the returned URL includes the correct
        // language prefix (e.g. /ar/) even when called outside of a request context.
        $new_url = apply_filters( 'wpml_permalink', get_permalink( $translated_id ), $this->secondary_lang );
        if ( empty( $new_url ) ) {
            return;
        }

        $old_path = '/' . $this->secondary_lang . '/' . $oc_keyword;
        $this->pending_sec_redirects[ $old_path ] = $new_url;
    }

    /**
     * Collect a secondary-language SEO redirect for a translated taxonomy term.
     *
     * Old path = /{secondary_lang}/{oc_keyword}  (e.g. /ar/electronics-in-qatar)
     * New URL  = WPML-aware term link            (e.g. /ar/product-category/electronics-in-qatar/)
     */
    private function queueSecondaryTermRedirect( int $translated_term_id, string $taxonomy, string $oc_keyword ): void {
        $term_link = apply_filters( 'wpml_permalink', get_term_link( $translated_term_id, $taxonomy ), $this->secondary_lang );
        if ( empty( $term_link ) || is_wp_error( $term_link ) ) {
            return;
        }

        $old_path = '/' . $this->secondary_lang . '/' . $oc_keyword;
        $this->pending_sec_redirects[ $old_path ] = $term_link;
    }

    /**
     * Merge all pending secondary-language redirects into the octowoo_redirects
     * WP option (the same store that SeoMigrator writes to, served by
     * SeoMigrator::handleWpRedirect() on every front-end request).
     */
    private function flushSecondaryLangRedirects(): void {
        if ( empty( $this->pending_sec_redirects ) ) {
            return;
        }

        if ( $this->isDry() ) {
            $this->logger->debug( '[DRY-RUN] Would register ' . count( $this->pending_sec_redirects ) . ' secondary-language SEO redirects.' );
            $this->pending_sec_redirects = [];
            return;
        }

        $existing = get_option( 'octowoo_redirects', [] );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }

        $merged = array_merge( $existing, $this->pending_sec_redirects );
        update_option( 'octowoo_redirects', $merged, false );

        $this->logger->info( '[multilingual] Registered ' . count( $this->pending_sec_redirects ) . ' secondary-language SEO redirects.' );
        $this->pending_sec_redirects = [];
    }

    /**
     * Set language and link the post pair with WPML or Polylang.
     */
    private function linkPostTranslation( int $primary_id, int $translated_id, string $post_type ): void {
        $element_type = 'post_' . $post_type;

        if ( $this->adapter === 'wpml' ) {
            // Get the existing trid FIRST (WPML may have auto-assigned one during
            // wp_insert_post). Passing the existing trid avoids creating a duplicate
            // translation group for the same post.
            $existing_trid = $this->wpmlGetTrid( $primary_id, $element_type );
            // Only re-register primary post if not already correctly set as primary language.
            // Re-registering an already-correct English product/page causes it to drop
            // from the English language filter — same bug as categories.
            $current_post_lang = apply_filters( 'wpml_element_language_code', null, [
                'element_id'   => $primary_id,
                'element_type' => $element_type,
            ] );
            if ( $current_post_lang !== $this->primary_lang ) {
                $this->wpmlSetPostLanguage( $primary_id, $element_type, $this->primary_lang, $existing_trid, true );
            }
            // Re-fetch trid after potential update to ensure we have the canonical value.
            $trid = $this->wpmlGetTrid( $primary_id, $element_type );
            if ( ! $trid ) { $trid = $existing_trid; }
            // is_primary = false → source_language_code = $this->primary_lang (translated FROM primary).
            $this->wpmlSetPostLanguage( $translated_id, $element_type, $this->secondary_lang, $trid, false );

        } elseif ( $this->adapter === 'polylang' ) {
            $this->polylangSetPostLanguage( $primary_id,    $this->primary_lang );
            $this->polylangSetPostLanguage( $translated_id, $this->secondary_lang );
            $this->polylangLinkPostTranslations( [
                $this->primary_lang   => $primary_id,
                $this->secondary_lang => $translated_id,
            ] );
        }
    }

    // ── Term translation pass ─────────────────────────────────────────────────

    /**
     * Copy all WooCommerce-specific meta and taxonomy term assignments from the
     * primary product to its secondary-language translation post.
     *
     * WPML does NOT automatically carry these over when we create the translated
     * post manually, so we must copy them explicitly:
     *   – Core WC product meta (SKU, price, stock, weight, attributes …)
     *   – product_type term  (simple/variable)
     *   – product_tag terms
     *   – Brand taxonomy terms (whichever plugin is active)
     */
    private function copyProductDataToTranslation( int $source_id, int $target_id ): void {
        // ── WooCommerce core product meta ──────────────────────────────────
        $wc_meta_keys = [
            '_sku', '_regular_price', '_price', '_sale_price',
            '_stock', '_stock_status', '_manage_stock', '_backorders',
            '_weight', '_length', '_width', '_height',
            '_virtual', '_downloadable', '_sold_individually',
            '_tax_status', '_tax_class', '_product_attributes',
            '_octowoo_oc_id',
            // Featured image and gallery — without these the translated product
            // has no images even though the primary product has them.
            '_thumbnail_id',
            '_product_image_gallery',
        ];
        foreach ( $wc_meta_keys as $key ) {
            $value = get_post_meta( $source_id, $key, true );
            // update_post_meta handles '' safely (clears the meta).
            update_post_meta( $target_id, $key, $value );
        }

        // ── NO image import here ────────────────────────────────────────────
        // The translated post shares the English parent's '_thumbnail_id' and
        // '_product_image_gallery' (copied above). It must NOT attempt to
        // re-download images from the remote OpenCart host: each missing-thumbnail
        // product blocked for up to ~40s on download_url()/wp_remote_get timeouts,
        // making the Arabic pass crawl at ~45s/product. Image (re)import is the job
        // of the product/image migrators (use "Re-run Products + Images"), not the
        // translation pass, which must stay pure-DB and fast.


        // ── product_type term (simple / variable / …) ──────────────────────
        $type_terms = wp_get_object_terms( $source_id, 'product_type', [ 'fields' => 'names' ] );
        if ( ! is_wp_error( $type_terms ) && ! empty( $type_terms ) ) {
            wp_set_object_terms( $target_id, $type_terms, 'product_type' );
        }

        // ── product_cat terms → resolve to secondary-language translated category terms ─
        // Without this the translated product has no category at all, so the
        // breadcrumb shows "Home › Shop › Product" with no category segment.
        $cat_ids = wp_get_object_terms( $source_id, 'product_cat', [ 'fields' => 'ids' ] );
        if ( ! is_wp_error( $cat_ids ) && ! empty( $cat_ids ) ) {
            $translated_cat_ids = [];
            foreach ( array_map( 'intval', $cat_ids ) as $cat_id ) {
                $sec_cat_id = $this->getExistingTranslationId( $cat_id, 'tax_product_cat' );
                // Fall back to the primary-language term ID if no secondary-language translation exists yet.
                $translated_cat_ids[] = $sec_cat_id > 0 ? $sec_cat_id : $cat_id;
            }
            wp_set_object_terms( $target_id, $translated_cat_ids, 'product_cat' );
        }

        // ── product_tag terms ──────────────────────────────────────────────
        // Prefer secondary-language tag strings from OpenCart so the translated
        // product gets secondary-language tag terms instead of the shared primary-language ones.
        // OpenCart stores per-language comma-separated tags in
        // oc_product_description.tag (one row per language per product).
        $sec_tags_assigned = false;
        $oc_product_id    = (int) get_post_meta( $source_id, '_octowoo_oc_id', true );
        if ( $oc_product_id > 0 ) {
            $sec_lang_id = $this->langIdSecondary();
            $pri_lang_id = $this->langId();
            if ( $sec_lang_id > 0 ) {
                // Use pre-fetched tag cache (populated by prefetchSecLangTagsForProducts() once
                // per chunk) to avoid per-product N+1 queries against the OC database.
                // Falls back to a live OC query only if the cache was not populated
                // (e.g. when translatePosts() is called directly outside the chunked path).
                if ( isset( $this->sec_tags_cache[ $oc_product_id ] ) ) {
                    $sec_tag_raw = $this->sec_tags_cache[ $oc_product_id ]['sec'];
                    $pri_tag_raw = $this->sec_tags_cache[ $oc_product_id ]['pri'];
                } else {
                    $pfx        = $this->pfx();
                    $sec_tag_raw = (string) $this->oc->fetchColumn(
                        "SELECT `tag` FROM `{$pfx}product_description`
                         WHERE product_id = ? AND language_id = ?",
                        [ $oc_product_id, $sec_lang_id ]
                    );
                    $pri_tag_raw = (string) $this->oc->fetchColumn(
                        "SELECT `tag` FROM `{$pfx}product_description`
                         WHERE product_id = ? AND language_id = ?",
                        [ $oc_product_id, $pri_lang_id ]
                    );
                }

                if ( is_string( $sec_tag_raw ) && $sec_tag_raw !== '' ) {
                    $sec_tag_names = array_values(
                        array_filter(
                            array_map( 'sanitize_text_field', explode( ',', $sec_tag_raw ) ),
                            fn( string $t ) => $t !== ''
                        )
                    );

                    if ( ! empty( $sec_tag_names ) ) {
                        $pri_tag_names = is_string( $pri_tag_raw ) && $pri_tag_raw !== ''
                            ? array_values( array_filter(
                                array_map( 'sanitize_text_field', explode( ',', $pri_tag_raw ) ),
                                fn( string $t ) => $t !== ''
                            ) )
                            : [];

                        $sec_term_ids = [];
                        foreach ( $sec_tag_names as $idx => $sec_tag_name ) {
                            if ( isset( $this->tag_xlate_cache[ $sec_tag_name ] ) ) {
                                $sec_term_ids[] = $this->tag_xlate_cache[ $sec_tag_name ];
                                continue;
                            }

                            // Create (or find) the Arabic tag term via DIRECT DB —
                            // no wp_insert_term, so WPML's created_term re-sync never fires.
                            $sec_tid = $this->findOrCreateTagTerm( $sec_tag_name );
                            if ( $sec_tid <= 0 ) { continue; }

                            // Share the English slug + link the two languages, all via
                            // direct icl_translations writes (fast, no WPML hooks).
                            if ( isset( $pri_tag_names[ $idx ] ) ) {
                                [ $pri_tid, $pri_slug ] = $this->findPrimaryTagByName( $pri_tag_names[ $idx ] );
                                if ( $pri_tid > 0 ) {
                                    if ( $pri_slug !== '' ) { $this->fixTranslationTermSlug( $sec_tid, $pri_slug ); }
                                    $this->fastLinkTagTranslation( $pri_tid, $sec_tid );
                                } elseif ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
                                    $this->fastLinkTagTranslation( $sec_tid, $sec_tid );
                                }
                            } elseif ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
                                $this->fastLinkTagTranslation( $sec_tid, $sec_tid );
                            }

                            $this->tag_xlate_cache[ $sec_tag_name ] = $sec_tid;
                            $sec_term_ids[] = $sec_tid;
                        }

                        if ( ! empty( $sec_term_ids ) ) {
                            wp_set_object_terms( $target_id, $sec_term_ids, 'product_tag', false );
                            $sec_tags_assigned = true;
                        }
                    }
                }
            }
        }
        // Fall back: no secondary-language OC tags — resolve primary-language tag IDs to their
        // translated counterparts so translated products get secondary-language tag terms
        // (not primary-language tag IDs, which would make them visible on primary-language archives).
        if ( ! $sec_tags_assigned ) {
            $tag_ids = wp_get_object_terms( $source_id, 'product_tag', [ 'fields' => 'ids' ] );
            if ( ! is_wp_error( $tag_ids ) && ! empty( $tag_ids ) ) {
                $translated_tag_ids = [];
                foreach ( array_map( 'intval', $tag_ids ) as $pri_tag_id ) {
                    $sec_tag_id           = $this->getExistingTranslationId( $pri_tag_id, 'tax_product_tag' );
                    $translated_tag_ids[] = $sec_tag_id > 0 ? $sec_tag_id : $pri_tag_id;
                }
                wp_set_object_terms( $target_id, $translated_tag_ids, 'product_tag', false );
            }
        }

        // ── Brand / manufacturer taxonomy ──────────────────────────────────
        // Resolve primary-language brand term IDs → secondary-language translated term IDs.
        // Falls back to the primary-language term ID when no secondary-language translation exists.
        $brand_tax = $this->detectActiveBrandTaxonomy();
        if ( $brand_tax !== '' ) {
            $brand_ids = wp_get_object_terms( $source_id, $brand_tax, [ 'fields' => 'ids' ] );
            if ( ! is_wp_error( $brand_ids ) && ! empty( $brand_ids ) ) {
                $translated_brand_ids = [];
                foreach ( array_map( 'intval', $brand_ids ) as $bid ) {
                    $sec_bid = $this->getExistingTranslationId( $bid, "tax_{$brand_tax}" );
                    $translated_brand_ids[] = $sec_bid > 0 ? $sec_bid : $bid;
                }
                wp_set_object_terms( $target_id, $translated_brand_ids, $brand_tax );
            }
        }
    }

    /**
     * Return the first registered brand taxonomy slug on this site, or ''.
     */
    private function detectActiveBrandTaxonomy(): string {
        if ( $this->brand_tax_cache !== null ) {
            return $this->brand_tax_cache;
        }
        $candidates = [
            'product_brand',        // WooCommerce Brands (official) · Ultimate WooCommerce Brands
            'pwb-brand',            // Perfect WooCommerce Brands
            'yith_product_brand',   // YITH WooCommerce Brands
            'berocket_brand',       // Brands for WooCommerce by BeRocket
            'pa_brand',             // Attribute-based brand
            'brand',                // Generic / theme-based
            'product_manufacturer', // OctoWoo fallback
        ];
        foreach ( $candidates as $tax ) {
            if ( taxonomy_exists( $tax ) ) {
                return $this->brand_tax_cache = $tax;
            }
        }
        return $this->brand_tax_cache = '';
    }

    /**
     * Iterate every migrated taxonomy term and create secondary language counterparts.
     *
     * @param string $taxonomy      WP taxonomy slug (e.g. 'product_cat', 'product_brand').
     * @param array  $sec_seo_map   OC-ID → SEO-slug map for redirect registration.
     * @param string $entity_type   Value used in octowoo_id_map (default 'category').
     * @return int[] [processed, skipped, failed]
     */
    /**
     * @return array{0:int,1:int,2:int,3:bool}  [processed, skipped, failed, has_more]
     */
    private function translateTerms(
        string $taxonomy,
        array  $sec_seo_map  = [],
        string $entity_type  = 'category',
        int    $offset       = 0,
        int    $limit        = 0
    ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT oc_id, wc_id FROM {$wpdb->prefix}octowoo_id_map WHERE entity_type = %s",
                $entity_type
            ),
            ARRAY_A
        );

        // Fallback: if id_map has no entries (e.g. after Reset Progress), query
        // the taxonomy directly so Multilingual Recovery still translates all terms.
        if ( empty( $rows ) ) {
            $this->logger->info( "[multilingual] id_map empty for entity_type='{$entity_type}'; querying {$taxonomy} terms directly." );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT tt.term_id AS wc_id, COALESCE(tm.meta_value, 0) AS oc_id
                     FROM {$wpdb->term_taxonomy} tt
                     LEFT JOIN {$wpdb->termmeta} tm
                         ON tm.term_id = tt.term_id AND tm.meta_key = '_octowoo_oc_id'
                     WHERE tt.taxonomy = %s
                       AND NOT EXISTS (
                           SELECT 1
                           FROM {$wpdb->prefix}icl_translations icl
                           WHERE icl.element_id   = tt.term_taxonomy_id
                             AND icl.element_type  = %s
                             AND icl.language_code != %s
                       )",
                    $taxonomy,
                    'tax_' . $taxonomy,
                    $this->primary_lang
                ),
                ARRAY_A
            );
        }

        $total_rows = count( $rows );
        $has_more   = false;
        if ( $limit > 0 ) {
            $has_more = ( $offset + $limit ) < $total_rows;
            $rows     = array_slice( $rows, $offset, $limit );
        } elseif ( $offset > 0 ) {
            $rows = array_slice( $rows, $offset );
        }

        $processed = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ( $rows as $row ) {
            $primary_term_id = (int) $row['wc_id'];
            $oc_id           = (int) $row['oc_id'];

            $sfx            = $this->secLangSuffix();
            $sec_name        = get_term_meta( $primary_term_id, '_octowoo_name' . $sfx,        true );
            $sec_description = get_term_meta( $primary_term_id, '_octowoo_description' . $sfx, true );

            // Fetch primary term for slug and fallback values.
            $primary_term = get_term( $primary_term_id, $taxonomy );
            if ( ! $primary_term || is_wp_error( $primary_term ) ) {
                $failed++;
                continue;
            }

            // Re-fetch from OC when:
            // a) sec_name/sec_description is empty (wrong language_id during CategoryMigrator), OR
            // b) sec_name exists but contains NO Arabic/secondary-language characters
            //    (English was stored in the Arabic term meta — same bug as products).
            $name_has_arabic = $sec_name        && preg_match( '/[\x{0600}-\x{06FF}]/u', $sec_name );
            $desc_has_arabic = $sec_description && preg_match( '/[\x{0600}-\x{06FF}]/u', $sec_description );
            $need_term_fresh = $oc_id > 0 && ( ! $name_has_arabic || ! $desc_has_arabic );

            if ( $need_term_fresh ) {
                $fresh_cat = $this->fetchSecCategoryDescriptionFromOC( $oc_id );
                if ( $fresh_cat !== null ) {
                    // Use fresh name if it has Arabic OR if current is empty.
                    if ( ! empty( $fresh_cat['name'] ) ) {
                        $fresh_name     = sanitize_text_field( $fresh_cat['name'] );
                        $fresh_name_ar  = preg_match( '/[\x{0600}-\x{06FF}]/u', $fresh_name );
                        if ( $fresh_name_ar || ! $sec_name ) {
                            $sec_name = $fresh_name;
                            $this->logger->info( "[multilingual] Re-fetched {$taxonomy} name from OC for term #{$primary_term_id} (OC #{$oc_id}): '{$sec_name}'" );
                            update_term_meta( $primary_term_id, '_octowoo_name' . $sfx, $sec_name );
                        }
                    }
                    // Use fresh description if it has Arabic OR if current is empty.
                    if ( ! empty( $fresh_cat['description'] ) ) {
                        $fresh_desc    = wp_kses_post( $fresh_cat['description'] );
                        $fresh_desc_ar = preg_match( '/[\x{0600}-\x{06FF}]/u', $fresh_desc );
                        if ( $fresh_desc_ar || ! $sec_description ) {
                            $sec_description = $fresh_desc;
                            update_term_meta( $primary_term_id, '_octowoo_description' . $sfx, $sec_description );
                        }
                    }
                }
            }

            // Fall back to primary-language values when secondary meta is still absent.
            if ( ! $sec_name ) {
                $sec_name = $primary_term->name;
                $this->logger->debug( "[multilingual] No secondary-language name for {$taxonomy} term WC #{$primary_term_id} (OC #{$oc_id}) – using primary name as fallback." );
            }
            if ( ! $sec_description ) {
                $sec_description = $primary_term->description;
            }

            // Resolve the secondary-language parent term ID so the translated term
            // sits at the correct depth in the taxonomy hierarchy.
            // Only applicable for hierarchical taxonomies (product_cat).
            $sec_parent = 0;
            if ( $primary_term->parent > 0 ) {
                $sec_parent = $this->getExistingTranslationId( $primary_term->parent, "tax_{$taxonomy}" );
            }

            $existing_translation_id = $this->getExistingTranslationId( $primary_term_id, "tax_{$taxonomy}" );
            if ( $existing_translation_id > 0 ) {
                if ( $this->isDry() ) {
                    $this->logger->debug( "[DRY-RUN] Would update existing {$this->secondary_lang} translation for {$taxonomy} term #{$primary_term_id}: {$sec_name}" );
                    $processed++;
                    continue;
                }

                // Use a guaranteed-unique temporary slug so WordPress never raises a
                // slug-uniqueness error. The root problem: after the first run,
                // fixTranslationTermSlug() sets the secondary-language term slug = "electronics-in-qatar"
                // (same as primary). On the second run, wp_update_term() with that
                // slug calls wp_unique_term_slug() which sees the primary owns it
                // → changes it to "electronics-in-qatar-2" → then WP checks if that suffix
                // slug already exists → returns WP_Error "already in use by another term".
                //
                // Passing 'octowoo-sec-{id}' (unique per term, never used by any real term)
                // bypasses all uniqueness conflicts. fixTranslationTermSlug() immediately
                // overwrites it with the correct shared slug via direct DB write.
                // Use timestamp in temp slug to guarantee uniqueness across recovery runs.
                // The old slug 'octowoo-sec-{id}' from a prior run may still be attached
                // to another term_id, causing wp_update_term to reject it as "already in use".
                $temp_slug = 'ow-t-' . $existing_translation_id . '-' . time();

                // Update via direct DB write to bypass wp_update_term slug uniqueness check entirely.
                // This is safe because fixTranslationTermSlug() rewrites the slug after this.
                global $wpdb;
                $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $wpdb->terms,
                    [ 'name' => $sec_name, 'slug' => $temp_slug ],
                    [ 'term_id' => $existing_translation_id ]
                );
                $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $wpdb->term_taxonomy,
                    [ 'description' => $sec_description, 'parent' => $sec_parent ],
                    [ 'term_id' => $existing_translation_id, 'taxonomy' => $taxonomy ]
                );
                clean_term_cache( $existing_translation_id, $taxonomy );

                // Verify update succeeded.
                $verify_term = get_term( $existing_translation_id, $taxonomy );
                if ( ! $verify_term || is_wp_error( $verify_term ) ) {
                    $this->logger->error( "[multilingual] Term #{$existing_translation_id} not found after update in {$taxonomy}." );
                    $failed++;
                    continue;
                }
                $name_has_ar_check = preg_match( '/[\x{0600}-\x{06FF}]/u', $sec_name );
                $this->logger->info( "[multilingual] Updated {$taxonomy} term #{$existing_translation_id}: name='{$sec_name}' " . ( $name_has_ar_check ? '[ARABIC]' : '[NOT ARABIC]' ) );

                // Re-register the WPML translation link on every update run.
                // This is idempotent and repairs any stale or missing
                // icl_translations rows that cause secondary-language category 404 errors.
                $this->linkTermTranslation( $primary_term_id, $existing_translation_id, $taxonomy );

                // Force the slug to match the primary term AFTER WPML linking.
                // WPML's wpml_set_element_language_details action may call
                // wp_update_term internally which resets the slug — so this must
                // come last.
                $this->fixTranslationTermSlug( $existing_translation_id, $primary_term->slug );

                // Sync Yoast SEO meta to the existing translated term.
                $this->applyYoastTermMeta( $primary_term_id, $existing_translation_id );

                // Sync category thumbnail image — without this update, secondary-language categories
                // lose their image whenever the primary category's image changes.
                $thumb_id = get_term_meta( $primary_term_id, 'thumbnail_id', true );
                if ( $thumb_id ) {
                    update_term_meta( $existing_translation_id, 'thumbnail_id', (int) $thumb_id );
                }

                // Register old OC secondary-language URL → new WC secondary-language category URL.
                if ( ! empty( $sec_seo_map[ $oc_id ] ) ) {
                    $this->queueSecondaryTermRedirect( $existing_translation_id, $taxonomy, $sec_seo_map[ $oc_id ] );
                }

                $this->logger->debug( "[multilingual] Updated existing {$taxonomy} translation term #{$existing_translation_id} from primary #{$primary_term_id}." );
                $processed++;
                continue;
            }

            if ( $this->isDry() ) {
                $this->logger->debug( "[DRY-RUN] Would create {$this->secondary_lang} translation for {$taxonomy} term #{$primary_term_id}: {$sec_name}" );
                $processed++;
                continue;
            }

            $translated_term_id = $this->createTranslatedTerm( $primary_term, $sec_name, $sec_description, $taxonomy, $sec_parent );

            if ( ! $translated_term_id ) {
                $failed++;
                continue;
            }

            $this->linkTermTranslation( $primary_term_id, $translated_term_id, $taxonomy );

            // Force slug to match the primary AFTER WPML linking so WPML cannot
            // clobber it with a uniqueness-suffixed version.
            $this->fixTranslationTermSlug( $translated_term_id, $primary_term->slug );

            // Register old OC secondary-language URL → new WC secondary-language category URL.
            if ( ! empty( $sec_seo_map[ $oc_id ] ) ) {
                $this->queueSecondaryTermRedirect( $translated_term_id, $taxonomy, $sec_seo_map[ $oc_id ] );
            }

            $this->logger->debug( "[multilingual] Linked {$taxonomy} term #{$primary_term_id} ({$this->primary_lang}) ↔ #{$translated_term_id} ({$this->secondary_lang})" );
            $processed++;
        }

        // Persist any queued secondary-language category redirects.
        $this->flushSecondaryLangRedirects();

        // Post-sweep: ensure every secondary-language category term has the correct
        // secondary-language parent.  Only run this after the LAST batch of categories (when
        // $has_more is false) so all secondary-language terms exist before we resolve parents.
        if ( ! $has_more && $taxonomy === 'product_cat' ) {
            $this->fixSecLangTermParents( $taxonomy );
        }

        return [ $processed, $skipped, $failed, $has_more ];
    }

    /**
     * Post-sweep: walk all migrated category terms and set the correct secondary-language
     * parent on each secondary-language translation term.
     *
     * Called once at the end of translateTerms('product_cat', ...) after every
     * secondary-language term has been created/updated, so all parents are resolvable.
     */
    private function fixSecLangTermParents( string $taxonomy ): void {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT wc_id FROM {$wpdb->prefix}octowoo_id_map WHERE entity_type = %s",
                'category'
            ),
            ARRAY_A
        );

        // v2.4.72: Safety guards against corrupt OC data with circular parent references.
        // Without these, a category A→B→A cycle causes an infinite loop → PHP memory exhaustion.
        $visited      = [];   // Term IDs we have already processed.
        $max_items    = 5000; // Hard upper bound across the entire loop.
        $item_count   = 0;

        foreach ( $rows as $row ) {
            if ( ++$item_count > $max_items ) {
                $this->logger->warning( '[multilingual] fixSecLangTermParents: safety limit reached (' . $max_items . ' iterations). Possible circular parent reference in category data.' );
                break;
            }

            if ( isset( $visited[ (int) $row['wc_id'] ] ) ) {
                continue; // Already processed — skip to avoid circular processing.
            }
            $visited[ (int) $row['wc_id'] ] = true;
            $pri_term_id = (int) $row['wc_id'];
            $pri_term    = get_term( $pri_term_id, $taxonomy );

            if ( ! $pri_term || is_wp_error( $pri_term ) || (int) $pri_term->parent === 0 ) {
                continue; // Root-level or invalid – nothing to fix.
            }

            $sec_term_id = $this->getExistingTranslationId( $pri_term_id, "tax_{$taxonomy}" );
            if ( $sec_term_id <= 0 ) {
                continue;
            }

            $sec_parent_id = $this->getExistingTranslationId( $pri_term->parent, "tax_{$taxonomy}" );
            if ( $sec_parent_id <= 0 ) {
                continue; // Secondary-language parent does not exist yet – skip.
            }

            $sec_term = get_term( $sec_term_id, $taxonomy );
            if ( $sec_term && ! is_wp_error( $sec_term ) && (int) $sec_term->parent === $sec_parent_id ) {
                continue; // Already correct.
            }

            // Direct DB write to avoid wp_update_term slug conflict errors.
            global $wpdb;
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->term_taxonomy,
                [ 'parent' => $sec_parent_id ],
                [ 'term_id' => $sec_term_id, 'taxonomy' => $taxonomy ]
            );
            clean_term_cache( $sec_term_id, $taxonomy );
            $this->logger->debug( "[multilingual] Fixed secondary-language parent for {$taxonomy} term #{$sec_term_id} → parent #{$sec_parent_id}." );
        }
    }

    /**
     * Create a translated taxonomy term in the secondary language.
     */
    private function createTranslatedTerm( \WP_Term $source, string $name, string $description, string $taxonomy, int $sec_parent = 0 ): int {
        global $wpdb;
        // Do NOT pass 'slug' to wp_insert_term — WordPress rejects the primary-
        // language slug because another term (the primary one) already owns it.
        // Let WordPress generate a temporary slug, then force the correct one
        // via direct DB write after the term is created and WPML-linked.
        //
        // Switch to the secondary language BEFORE wp_insert_term so WPML's
        // hook auto-registers the new term in the secondary language immediately during creation.
        // Without this, WPML sees the current language as 'en' (the default) and
        // registers the secondary term as primary — causing it to appear in the
        // primary-language category widget and breaking language filtering.
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            do_action( 'wpml_switch_language', $this->secondary_lang );
        }

        $result = wp_insert_term( $name, $taxonomy, [
            'description' => $description ?: $source->description,
            'parent'      => $sec_parent,
        ] );

        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            do_action( 'wpml_switch_language', null ); // Restore default language.
        }

        if ( is_wp_error( $result ) && $result->get_error_code() === 'term_exists' ) {
            $existing_id = (int) $result->get_error_data( 'term_exists' );

            // Guard: if the "existing" term is actually the primary-language term
            // (same name in both languages — e.g. brand names like "Apple", "Nintendo"),
            // using that ID as the secondary-language translation would create a self-link in
            // icl_translations, which WPML rejects. Instead, insert a throwaway
            // placeholder name that is guaranteed unique, then rename it via direct DB.
            if ( $existing_id === $source->term_id ) {
                $placeholder = 'octowoo-sec-new-' . $source->term_id . '-' . time();
                if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
                    do_action( 'wpml_switch_language', $this->secondary_lang );
                }
                $retry = wp_insert_term( $placeholder, $taxonomy, [
                    'description' => $description ?: $source->description,
                    'parent'      => $sec_parent,
                ] );
                if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
                    do_action( 'wpml_switch_language', null );
                }
                if ( is_wp_error( $retry ) ) {
                    $this->logger->error( "[multilingual] Failed creating placeholder term for same-name category ({$name}): " . $retry->get_error_message() );
                    return 0;
                }
                $existing_id = (int) $retry['term_id'];

                // Rename placeholder → actual secondary-language name immediately so the term
                // is never visible as "octowoo-sec-new-…" in WP Admin.
                // WordPress allows multiple terms with the same name in one taxonomy
                // (only slugs must be unique), so this direct update is safe.
                global $wpdb;
                $wpdb->update( $wpdb->terms, [ 'name' => $name ], [ 'term_id' => $existing_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                clean_term_cache( $existing_id );
                // fixTranslationTermSlug (called by the caller) will set the correct slug afterwards.
            }

            return $existing_id;
        }

        if ( is_wp_error( $result ) ) {
            $this->logger->error( "[multilingual] Failed creating translated term ({$this->secondary_lang}): " . $result->get_error_message() );
            return 0;
        }

        $translated_term_id = (int) $result['term_id'];

        // Copy Yoast SEO meta.
        // Fall back to primary-language values when secondary meta is absent.
        $sfx            = $this->secLangSuffix();
        $sec_meta_title = (string) get_term_meta( $source->term_id, '_octowoo_metatitle' . $sfx, true );
        $sec_meta_desc  = (string) get_term_meta( $source->term_id, '_octowoo_metadesc'  . $sfx, true );
        $sec_meta_kw    = (string) get_term_meta( $source->term_id, '_octowoo_metakw'    . $sfx, true );

        if ( $sec_meta_title === '' ) {
            $sec_meta_title = (string) get_term_meta( $source->term_id, '_yoast_wpseo_title', true );
        }
        if ( $sec_meta_desc === '' ) {
            $sec_meta_desc = (string) get_term_meta( $source->term_id, '_yoast_wpseo_metadesc', true );
        }
        if ( $sec_meta_kw === '' ) {
            $sec_meta_kw = (string) get_term_meta( $source->term_id, '_yoast_wpseo_focuskw', true );
        }

        if ( $sec_meta_title ) {
            update_term_meta( $translated_term_id, '_yoast_wpseo_title',   $sec_meta_title );
        }
        if ( $sec_meta_desc ) {
            update_term_meta( $translated_term_id, '_yoast_wpseo_metadesc', $sec_meta_desc );
        }
        if ( $sec_meta_kw ) {
            update_term_meta( $translated_term_id, '_yoast_wpseo_focuskw', $sec_meta_kw );
        }

        update_term_meta( $translated_term_id, '_octowoo_translation_of',   $source->term_id );
        update_term_meta( $translated_term_id, '_octowoo_translation_lang', $this->secondary_lang );

        // Copy category thumbnail image so the secondary-language term displays
        // the same image as its primary-language counterpart.
        // (WooCommerce stores category images as 'thumbnail_id' term meta; WPML
        // does not carry this over automatically when creating translated terms.)
        $thumb_id = get_term_meta( $source->term_id, 'thumbnail_id', true );
        if ( $thumb_id ) {
            update_term_meta( $translated_term_id, 'thumbnail_id', (int) $thumb_id );
        }

        return $translated_term_id;
    }

    /**
     * Set language and link the term pair with WPML or Polylang.
     */
    /**
     * Fast WPML tag-translation link via DIRECT icl_translations writes.
     *
     * linkTermTranslation() fires up to two wpml_set_element_language_details
     * actions and the tag loop fired a third — each of which triggers WPML's full
     * taxonomy re-sync when taxonomies are flagged out-of-sync, costing ~2s apiece
     * (≈90s for a product with many tags). This does the same registration with two
     * indexed INSERT … ON DUPLICATE KEY UPDATE statements (the icl_translations
     * UNIQUE key is element_type+element_id), in milliseconds, without invoking any
     * WPML hook. The English row is only created if absent — never overwritten —
     * so the English tag never drops out of its own language filter.
     */
    /**
     * Find or create a product_tag term via DIRECT DB writes — no wp_insert_term,
     * so WPML's created_term hook (which re-syncs the whole taxonomy when it's
     * flagged out of sync, ~2s each) never fires. Cached per request by name.
     */
    private function findOrCreateTagTerm( string $name ): int {
        global $wpdb;
        $name = trim( $name );
        if ( $name === '' ) { return 0; }
        if ( isset( $this->tag_term_cache[ $name ] ) ) { return $this->tag_term_cache[ $name ]; }

        $tid = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT t.term_id FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE t.name = %s AND tt.taxonomy = 'product_tag' LIMIT 1",
            $name
        ) );
        if ( $tid > 0 ) { return $this->tag_term_cache[ $name ] = $tid; }

        // Create directly. Slug is provisional (fixTranslationTermSlug overwrites it
        // with the English slug when a counterpart exists); keep it unique meanwhile.
        $slug = $this->toSlug( $name );
        if ( $slug === '' ) { $slug = 'tag-' . substr( md5( $name ), 0, 8 ); }
        $base = $slug; $n = 2;
        while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE slug = %s", $slug ) ) > 0 ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $slug = $base . '-' . $n; $n++;
            if ( $n > 50 ) { $slug = $base . '-' . substr( md5( $name . microtime() ), 0, 6 ); break; }
        }

        $wpdb->insert( $wpdb->terms, [ 'name' => $name, 'slug' => $slug, 'term_group' => 0 ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $new_tid = (int) $wpdb->insert_id;
        if ( $new_tid <= 0 ) { return 0; }
        $wpdb->insert( $wpdb->term_taxonomy, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'term_id' => $new_tid, 'taxonomy' => 'product_tag', 'description' => '', 'parent' => 0, 'count' => 0,
        ] );
        clean_term_cache( $new_tid, 'product_tag' );
        return $this->tag_term_cache[ $name ] = $new_tid;
    }

    /**
     * Resolve a primary-language product_tag by name to [term_id, slug] via direct
     * DB lookup (cached). Replaces get_term_by('name', …) which WPML filters/slows.
     *
     * @return array{0:int,1:string}  [term_id, slug] or [0,''] if not found.
     */
    private function findPrimaryTagByName( string $name ): array {
        global $wpdb;
        $name = trim( $name );
        if ( $name === '' ) { return [ 0, '' ]; }
        if ( isset( $this->pri_tag_cache[ $name ] ) ) { return $this->pri_tag_cache[ $name ]; }

        $row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT t.term_id, t.slug FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE t.name = %s AND tt.taxonomy = 'product_tag' LIMIT 1",
            $name
        ) );
        $out = $row ? [ (int) $row->term_id, (string) $row->slug ] : [ 0, '' ];
        return $this->pri_tag_cache[ $name ] = $out;
    }

    private function fastLinkTagTranslation( int $en_term_id, int $ar_term_id ): void {
        global $wpdb;
        if ( $en_term_id <= 0 || $ar_term_id <= 0 ) { return; }

        $icl = $wpdb->prefix . 'icl_translations';
        $et  = 'tax_product_tag';

        $ar_tt = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='product_tag' LIMIT 1", $ar_term_id ) );
        if ( $ar_tt <= 0 ) { return; }

        // Standalone Arabic tag (no English counterpart): register it as a secondary-
        // language element in its own translation group (source_language_code = NULL).
        if ( $en_term_id === $ar_term_id ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "SELECT trid FROM `{$icl}` WHERE element_type=%s AND element_id=%d LIMIT 1", $et, $ar_tt ) );
            if ( $existing > 0 ) { return; }
            $trid = 1 + (int) $wpdb->get_var( "SELECT COALESCE(MAX(trid),0) FROM `{$icl}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "INSERT INTO `{$icl}` (element_type, element_id, trid, language_code, source_language_code)
                 VALUES (%s,%d,%d,%s,NULL)
                 ON DUPLICATE KEY UPDATE language_code=VALUES(language_code)",
                $et, $ar_tt, $trid, $this->secondary_lang ) );
            return;
        }

        $en_tt = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='product_tag' LIMIT 1", $en_term_id ) );
        if ( $en_tt <= 0 ) { return; }

        // Resolve (or create) the English tag's translation group id (trid).
        $trid = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT trid FROM `{$icl}` WHERE element_type=%s AND element_id=%d LIMIT 1", $et, $en_tt ) );
        if ( $trid <= 0 ) {
            $trid = 1 + (int) $wpdb->get_var( "SELECT COALESCE(MAX(trid),0) FROM `{$icl}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "INSERT INTO `{$icl}` (element_type, element_id, trid, language_code, source_language_code)
                 VALUES (%s,%d,%d,%s,NULL)
                 ON DUPLICATE KEY UPDATE trid=VALUES(trid)",
                $et, $en_tt, $trid, $this->primary_lang ) );
        }

        // Link the Arabic tag into the same group.
        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "INSERT INTO `{$icl}` (element_type, element_id, trid, language_code, source_language_code)
             VALUES (%s,%d,%d,%s,%s)
             ON DUPLICATE KEY UPDATE trid=VALUES(trid), language_code=VALUES(language_code), source_language_code=VALUES(source_language_code)",
            $et, $ar_tt, $trid, $this->secondary_lang, $this->primary_lang ) );
    }

    private function linkTermTranslation( int $primary_term_id, int $translated_term_id, string $taxonomy ): void {
        $primary_term = get_term( $primary_term_id, $taxonomy );
        if ( ! $primary_term || is_wp_error( $primary_term ) ) {
            return;
        }

        $element_type = "tax_{$taxonomy}";

        if ( $this->adapter === 'wpml' ) {
            // DIRECT icl_translations writes — same proven approach as products
            // (linkPostTranslation). The previous do_action('wpml_set_element_
            // language_details') path was slow (triggered full taxonomy re-sync per
            // term) and unreliable (re-labeled English terms as Arabic on re-runs).
            // Direct writes are deterministic and idempotent.
            global $wpdb;
            $icl = $wpdb->prefix . 'icl_translations';
            if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$icl}'" ) ) { return; } // phpcs:ignore WordPress.DB.DirectDatabaseQuery

            $pri_tt = (int) $primary_term->term_taxonomy_id;
            $sec_term = get_term( $translated_term_id, $taxonomy );
            if ( ! $sec_term || is_wp_error( $sec_term ) ) { return; }
            $sec_tt = (int) $sec_term->term_taxonomy_id;
            if ( $pri_tt <= 0 || $sec_tt <= 0 ) { return; }

            // 1) Ensure the PRIMARY term has a source row (language=primary,
            //    source_language_code=NULL). Create with a fresh trid only if absent;
            //    NEVER flip an existing correct English row (that is what dropped
            //    English from the language filter before).
            $pri_row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "SELECT translation_id, trid, language_code FROM `{$icl}` WHERE element_type=%s AND element_id=%d LIMIT 1",
                $element_type, $pri_tt
            ) );
            if ( ! $pri_row ) {
                $trid = 1 + (int) $wpdb->get_var( "SELECT COALESCE(MAX(trid),0) FROM `{$icl}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert( $icl, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    'element_type' => $element_type, 'element_id' => $pri_tt, 'trid' => $trid,
                    'language_code' => $this->primary_lang, 'source_language_code' => null,
                ], [ '%s', '%d', '%d', '%s', '%s' ] );
            } else {
                $trid = (int) $pri_row->trid;
                // Repair only if the primary row is in the wrong language.
                if ( $pri_row->language_code !== $this->primary_lang ) {
                    $wpdb->update( $icl, // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        [ 'language_code' => $this->primary_lang, 'source_language_code' => null ],
                        [ 'translation_id' => (int) $pri_row->translation_id ], [ '%s', '%s' ], [ '%d' ] );
                }
            }

            // 2) Link the SECONDARY term into the same trid as a translation
            //    (language=secondary, source_language_code=primary).
            $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "INSERT INTO `{$icl}` (element_type, element_id, trid, language_code, source_language_code)
                 VALUES (%s,%d,%d,%s,%s)
                 ON DUPLICATE KEY UPDATE trid=VALUES(trid), language_code=VALUES(language_code), source_language_code=VALUES(source_language_code)",
                $element_type, $sec_tt, $trid, $this->secondary_lang, $this->primary_lang
            ) );

        } elseif ( $this->adapter === 'polylang' ) {
            $this->polylangSetTermLanguage( $primary_term_id,    $this->primary_lang );
            $this->polylangSetTermLanguage( $translated_term_id, $this->secondary_lang );
            $this->polylangLinkTermTranslations( $taxonomy, [
                $this->primary_lang   => $primary_term_id,
                $this->secondary_lang => $translated_term_id,
            ] );
        }
    }

    // ── WPML helpers ──────────────────────────────────────────────────────────

    private function wpmlSetPostLanguage( int $post_id, string $element_type, string $lang, ?int $trid, bool $is_primary = false ): void {
        do_action( 'wpml_set_element_language_details', [
            'element_id'           => $post_id,
            'element_type'         => $element_type,
            'trid'                 => $trid,
            'language_code'        => $lang,
            // source_language_code must be NULL for the default-language original.
            // Passing $this->primary_lang here tells WPML "this was translated FROM
            // English" which reclassifies the English original as a translation —
            // making it disappear from the English language filter (English 0).
            'source_language_code' => $is_primary ? null : $this->primary_lang,
        ] );
    }

    private function wpmlGetTrid( int $post_id, string $element_type ): ?int {
        $trid = apply_filters( 'wpml_element_trid', null, $post_id, $element_type );
        return $trid ? (int) $trid : null;
    }

    private function wpmlSetTermLanguage( \WP_Term $term, string $element_type, string $lang, ?int $trid, bool $is_primary = false ): void {
        do_action( 'wpml_set_element_language_details', [
            'element_id'           => (int) $term->term_taxonomy_id,
            'element_type'         => $element_type,
            'trid'                 => $trid,
            'language_code'        => $lang,
            // source_language_code must be NULL for the primary-language original.
            // Passing $this->primary_lang for an English term tells WPML it is a
            // translation of English, stripping it from the English language filter.
            'source_language_code' => $is_primary ? null : $this->primary_lang,
        ] );
    }

    private function wpmlGetTridForTerm( \WP_Term $term, string $element_type ): ?int {
        $trid = apply_filters( 'wpml_element_trid', null, (int) $term->term_taxonomy_id, $element_type );
        return $trid ? (int) $trid : null;
    }

    // ── Polylang helpers ──────────────────────────────────────────────────────

    private function polylangSetPostLanguage( int $post_id, string $lang ): void {
        if ( function_exists( 'pll_set_post_language' ) ) {
            pll_set_post_language( $post_id, $lang );
        }
    }

    /**
     * @param array<string, int> $lang_to_id  e.g. ['en' => 1, 'ar' => 2]
     */
    private function polylangLinkPostTranslations( array $lang_to_id ): void {
        if ( function_exists( 'pll_save_post_translations' ) ) {
            pll_save_post_translations( $lang_to_id );
        }
    }

    private function polylangSetTermLanguage( int $term_id, string $lang ): void {
        if ( function_exists( 'pll_set_term_language' ) ) {
            pll_set_term_language( $term_id, $lang );
        }
    }

    /**
     * @param array<string, int> $lang_to_id
     */
    private function polylangLinkTermTranslations( string $taxonomy, array $lang_to_id ): void {
        if ( function_exists( 'pll_save_term_translations' ) ) {
            pll_save_term_translations( $lang_to_id );
        }
    }

    // ── Translation existence check ───────────────────────────────────────────

    /**
     * Check whether $element_id already has a translation in the secondary language.
     */
    private function translationExists( int $element_id, string $element_type ): bool {
        return $this->getExistingTranslationId( $element_id, $element_type ) > 0;
    }

    /**
     * Return translated object ID in secondary language, or 0 when missing.
     */
    private function getExistingTranslationId( int $element_id, string $element_type ): int {
        if ( $this->adapter === 'wpml' ) {
            $translated = apply_filters(
                'wpml_object_id',
                $element_id,
                str_replace( [ 'post_', 'tax_' ], '', $element_type ),
                false,
                $this->secondary_lang
            );
            $translated_id = (int) $translated;
            return ( $translated_id > 0 && $translated_id !== $element_id ) ? $translated_id : 0;
        }

        if ( $this->adapter === 'polylang' ) {
            if ( strpos( $element_type, 'post_' ) === 0 && function_exists( 'pll_get_post' ) ) {
                $translated = pll_get_post( $element_id, $this->secondary_lang );
                $translated_id = (int) $translated;
                return ( $translated_id > 0 && $translated_id !== $element_id ) ? $translated_id : 0;
            }
            if ( strpos( $element_type, 'tax_' ) === 0 && function_exists( 'pll_get_term' ) ) {
                $translated = pll_get_term( $element_id, $this->secondary_lang );
                $translated_id = (int) $translated;
                return ( $translated_id > 0 && $translated_id !== $element_id ) ? $translated_id : 0;
            }
        }

        // Fallback: check our own meta.
        if ( strpos( $element_type, 'post_' ) === 0 ) {
            $existing = get_posts( [
                'meta_key'       => '_octowoo_translation_of',
                'meta_value'     => $element_id,
                'meta_query'     => [
                    'relation' => 'AND',
                    [
                        'key'   => '_octowoo_translation_of',
                        'value' => $element_id,
                    ],
                    [
                        'key'   => '_octowoo_translation_lang',
                        'value' => $this->secondary_lang,
                    ],
                ],
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ] );
            return ! empty( $existing ) ? (int) $existing[0] : 0;
        }

        return 0;
    }

    // ── Adapter detection ─────────────────────────────────────────────────────

    /**
     * Determine which multilingual plugin is active.
     *
     * @return string  'wpml' | 'polylang' | 'none'
     */
    private function detectAdapter(): string {
        $prefer_wpml      = ! empty( $this->config['multilingual']['use_wpml'] );
        $prefer_polylang  = ! empty( $this->config['multilingual']['use_polylang'] );

        $has_wpml     = defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' );
        $has_polylang = function_exists( 'pll_the_languages' ) || class_exists( 'Polylang' );

        if ( $prefer_wpml && $has_wpml ) {
            return 'wpml';
        }
        if ( $prefer_polylang && $has_polylang ) {
            return 'polylang';
        }
        if ( $has_wpml ) {
            return 'wpml';
        }
        if ( $has_polylang ) {
            return 'polylang';
        }

        return 'none';
    }

    /**
     * Lazy-initialise and return an ImageMigrator instance.
     * Returns null when the OpenCart DB connection is not available (e.g. the
     * migration was reset and no connection params are stored).
     */
    private function imageMigratorInstance(): ?ImageMigrator {
        if ( $this->image_migrator !== null ) {
            return $this->image_migrator;
        }

        // AbstractMigrator exposes $this->oc (DatabaseConnector), $this->logger,
        // $this->checkpoint, $this->batch, and $this->config — all we need.
        try {
            $this->image_migrator = new ImageMigrator(
                $this->oc,
                $this->logger,
                $this->checkpoint,
                $this->batch,
                $this->config
            );
        } catch ( \Throwable $e ) {
            $this->logger->warning( '[multilingual] Could not init ImageMigrator for fallback: ' . $e->getMessage() );
            return null;
        }

        return $this->image_migrator;
    }

    /**
     * Normalize configured language values (locale/code) to active plugin codes.
     */
    private function resolveLanguageCodes(): void {
        $configured_primary   = (string) $this->primary_lang;
        $configured_secondary = (string) $this->secondary_lang;

        if ( $this->adapter === 'wpml' ) {
            $langs = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
            if ( is_array( $langs ) && ! empty( $langs ) ) {
                $this->primary_lang   = $this->resolveAgainstWpmlLanguages( $configured_primary,   $langs, 'en' );
                $this->secondary_lang = $this->resolveAgainstWpmlLanguages( $configured_secondary, $langs, $configured_secondary ?: 'ar' );
            }
        } elseif ( $this->adapter === 'polylang' && function_exists( 'pll_languages_list' ) ) {
            $active_slugs = (array) pll_languages_list( [ 'fields' => 'slug' ] );
            if ( ! empty( $active_slugs ) ) {
                $this->primary_lang   = $this->resolveAgainstSimpleSlugs( $configured_primary,   $active_slugs, 'en' );
                $this->secondary_lang = $this->resolveAgainstSimpleSlugs( $configured_secondary, $active_slugs, $configured_secondary ?: 'ar' );
            }
        }

        if ( $this->primary_lang === $this->secondary_lang ) {
            // Use configured secondary_locale code (first segment) as fallback — not hardcoded 'ar'.
            $fallback_secondary   = strtolower( explode( '_', $configured_secondary )[0] ?? 'ar' );
            $this->logger->warning( "[multilingual] Primary and secondary resolved to same language '{$this->primary_lang}'. Keeping configured value '{$fallback_secondary}'." );
            $this->secondary_lang = $fallback_secondary !== $this->primary_lang ? $fallback_secondary : $configured_secondary;
        }
    }

    /**
     * Resolve a configured language value against WPML active languages.
     *
     * @param string $configured Language code or locale (e.g. en, en_US).
     * @param array  $langs      WPML active languages payload.
     * @param string $fallback   Fallback code.
     */
    private function resolveAgainstWpmlLanguages( string $configured, array $langs, string $fallback ): string {
        $configured = trim( $configured );
        if ( $configured === '' ) {
            return $fallback;
        }

        if ( isset( $langs[ $configured ] ) ) {
            return $configured;
        }

        $norm_target = $this->normalizeLangCode( $configured );

        foreach ( $langs as $code => $info ) {
            if ( $this->normalizeLangCode( (string) $code ) === $norm_target ) {
                return (string) $code;
            }

            $locale = (string) ( $info['default_locale'] ?? $info['locale'] ?? '' );
            if ( $locale !== '' && $this->normalizeLangCode( $locale ) === $norm_target ) {
                return (string) $code;
            }
        }

        return $fallback;
    }

    /**
     * Resolve configured language code/locale against simple slug arrays.
     *
     * @param string   $configured Language code or locale.
     * @param string[] $slugs      Active slugs.
     * @param string   $fallback   Fallback slug.
     */
    private function resolveAgainstSimpleSlugs( string $configured, array $slugs, string $fallback ): string {
        $configured = trim( $configured );
        if ( $configured === '' ) {
            return $fallback;
        }

        if ( in_array( $configured, $slugs, true ) ) {
            return $configured;
        }

        $norm_target = $this->normalizeLangCode( $configured );
        foreach ( $slugs as $slug ) {
            if ( $this->normalizeLangCode( (string) $slug ) === $norm_target ) {
                return (string) $slug;
            }
        }

        return $fallback;
    }

    /**
     * Normalize locales/codes (en_US, en-GB, EN) to base lowercase code (en).
     */
    private function normalizeLangCode( string $value ): string {
        $value = strtolower( trim( $value ) );
        if ( $value === '' ) {
            return '';
        }

        $parts = preg_split( '/[_-]/', $value );
        return (string) ( $parts[0] ?? $value );
    }

    // ── Direct OC category description fetch (fallback when termmeta empty) ───────

    /**
     * Fetch the secondary-language category description directly from OpenCart.
     * Used when _octowoo_name_{sfx} term meta is empty (wrong language_id configured).
     *
     * @param  int        $oc_id  OpenCart category_id.
     * @return array|null         Row from oc_category_description, or null.
     */
    private function fetchSecCategoryDescriptionFromOC( int $oc_id ): ?array {
        try {
            $pfx     = $this->pfx();
            $pri_lid = $this->langId();
            $sec_lid = $this->langIdSecondary();

            // Fetch ALL non-primary language rows and prefer the one with Arabic characters.
            $all = $this->oc->fetchAll(
                "SELECT language_id, name, description, meta_title, meta_description, meta_keyword
                 FROM `{$pfx}category_description`
                 WHERE category_id = ? AND language_id != ?
                 ORDER BY language_id ASC",
                [ $oc_id, $pri_lid ]
            );

            if ( empty( $all ) ) { return null; }

            // Priority 1: configured secondary language ID with Arabic content.
            foreach ( $all as $row ) {
                if ( $sec_lid > 0 && (int) $row['language_id'] === $sec_lid
                     && preg_match( '/[\x{0600}-\x{06FF}]/u', $row['name'] . $row['description'] ) ) {
                    return $row;
                }
            }

            // Priority 2: any row with Arabic characters.
            foreach ( $all as $row ) {
                if ( preg_match( '/[\x{0600}-\x{06FF}]/u', $row['name'] . $row['description'] ) ) {
                    $this->logger->info( "[multilingual] Found Arabic category row language_id={$row['language_id']} for OC #{$oc_id}." );
                    return $row;
                }
            }

            // Priority 3: configured language_id even if not Arabic.
            foreach ( $all as $row ) {
                if ( $sec_lid > 0 && (int) $row['language_id'] === $sec_lid ) { return $row; }
            }

            // Priority 4: first available non-primary row.
            $this->logger->info( "[multilingual] No Arabic category row found for OC #{$oc_id}, using language_id={$all[0]['language_id']}." );
            return $all[0];

        } catch ( \Throwable $e ) {
            $this->logger->warning( "[multilingual] fetchSecCategoryDescriptionFromOC failed for OC #{$oc_id}: " . $e->getMessage() );
        }
        return null;
    }

    // ── Override: normalize secLangSuffix to first-segment only ─────────────────

    /**
     * Return the meta-key suffix for secondary-language data.
     *
     * Overrides AbstractMigrator::secLangSuffix() to ALWAYS use only the first
     * segment of the locale code (e.g. 'ar_SA' → '_ar', 'ar' → '_ar').
     * This ensures WpmlIntegration reads the same keys that ProductMigrator wrote
     * even when the locale was configured as a full BCP-47 tag.
     *
     * ProductMigrator uses the same normalization (both call this via parent::secLangSuffix
     * in AbstractMigrator, but AbstractMigrator now normalises too — see v2.5.18 fix).
     */
    protected function secLangSuffix(): string {
        $locale = $this->config['multilingual']['secondary_locale'] ?? 'ar';
        return '_' . strtolower( explode( '_', $locale )[0] );
    }

    // ── Direct OC description fetch (fallback when postmeta empty) ──────────────

    /**
     * Fetch the secondary-language product description row directly from OpenCart.
     *
     * Used as a fallback when _octowoo_name_{sfx} postmeta is empty — which happens
     * when ProductMigrator ran with the wrong language_id_secondary, or when the
     * secondary language was added to OC after the primary migration ran.
     *
     * Tries the configured language_id_secondary first, then auto-detects by finding
     * the first non-primary language row for this product.
     *
     * @param  int        $oc_id  OpenCart product_id.
     * @return array|null         Associative row from oc_product_description, or null.
     */
    private function fetchSecDescriptionFromOC( int $oc_id ): ?array {
        // Use bulk-prefetched cache if available (eliminates remote OC query per product).
        if ( array_key_exists( $oc_id, $this->sec_desc_cache ) ) {
            return $this->sec_desc_cache[ $oc_id ];
        }

        // Fallback: single-product query (used when cache not pre-populated).
        try {
            $pfx     = $this->pfx();
            $pri_lid = $this->langId();
            $sec_lid = $this->langIdSecondary();

            // Fetch ALL non-primary language rows, then pick the best one:
            // Priority: configured sec lang + Arabic > any Arabic > configured sec lang > first non-primary.
            $all = $this->oc->fetchAll(
                "SELECT language_id, name, description, meta_title, meta_description, meta_keyword, tag
                 FROM `{$pfx}product_description`
                 WHERE product_id = ? AND language_id != ?
                 ORDER BY language_id ASC",
                [ $oc_id, $pri_lid ]
            );

            if ( empty( $all ) ) { return null; }

            // Priority 1: configured secondary language ID with Arabic content.
            foreach ( $all as $row ) {
                if ( $sec_lid > 0 && (int) $row['language_id'] === $sec_lid
                     && preg_match( '/[\x{0600}-\x{06FF}]/u', $row['name'] . $row['description'] ) ) {
                    return $row;
                }
            }

            // Priority 2: any row with Arabic characters.
            foreach ( $all as $row ) {
                if ( preg_match( '/[\x{0600}-\x{06FF}]/u', $row['name'] . $row['description'] ) ) {
                    $this->logger->info( "[multilingual] Found Arabic product row language_id={$row['language_id']} for OC #{$oc_id}." );
                    return $row;
                }
            }

            // Priority 3: configured language_id (even if not Arabic).
            foreach ( $all as $row ) {
                if ( $sec_lid > 0 && (int) $row['language_id'] === $sec_lid ) { return $row; }
            }

            // Priority 4: first available non-primary row.
            $this->logger->info( "[multilingual] No Arabic product row found for OC #{$oc_id}, using language_id={$all[0]['language_id']}." );
            return $all[0];

        } catch ( \Throwable $e ) {
            $this->logger->warning( "[multilingual] fetchSecDescriptionFromOC failed for OC #{$oc_id}: " . $e->getMessage() );
        }
        return null;
    }

    // ── Multilingual readiness pre-check ─────────────────────────────────────

    /**
     * Scan WooCommerce content and report how many items are missing their
     * secondary-language (Arabic) WPML translation.
     *
     * Call this BEFORE running the multilingual migrator so the admin can see
     * exactly what will happen.  Returns zero-cost data — no OC DB queries.
     *
     * @param array $config  Full resolved plugin config.
     * @return array {
     *   categories:   { total: int, translated: int, missing: int },
     *   brands:       { total: int, translated: int, missing: int },
     *   products:     { total: int, translated: int, missing: int },
     *   pages:        { total: int, translated: int, missing: int },
     *   secondary_lang: string,   // e.g. 'ar'
     *   ready:        bool,       // true when missing across all types = 0
     * }
     */
    public function multilingual_precheck( array $config ): array {
        global $wpdb;

        $primary_lang   = $config['multilingual']['primary_locale']   ?? 'en';
        $secondary_lang = $config['multilingual']['secondary_locale']  ?? 'ar';
        $brand_tax      = $this->detectActiveBrandTaxonomy();

        $check_term_type = static function ( string $taxonomy, string $pri_lang, string $sec_lang ) use ( $wpdb ): array {
            $element_type = 'tax_' . $taxonomy;

            // Count primary-language terms.
            $total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} tt
                     JOIN {$wpdb->prefix}icl_translations icl
                          ON icl.element_id  = tt.term_taxonomy_id
                         AND icl.element_type = %s
                     WHERE tt.taxonomy       = %s
                       AND icl.language_code = %s",
                    $element_type, $taxonomy, $pri_lang
                )
            );

            // Count primary terms that HAVE a secondary-language translation.
            $translated = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT icl_pri.trid)
                     FROM {$wpdb->prefix}icl_translations icl_pri
                     JOIN {$wpdb->prefix}icl_translations icl_sec
                          ON icl_sec.trid          = icl_pri.trid
                         AND icl_sec.language_code  = %s
                         AND icl_sec.element_type   = %s
                     WHERE icl_pri.language_code    = %s
                       AND icl_pri.element_type     = %s",
                    $sec_lang, $element_type, $pri_lang, $element_type
                )
            );

            return [
                'total'      => $total,
                'translated' => $translated,
                'missing'    => max( 0, $total - $translated ),
            ];
        };

        // Products: use _octowoo_translation_of meta to identify translated copies.
        $prod_total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type   = 'product'
               AND p.post_status IN ('publish','draft')
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pm
                   WHERE pm.post_id  = p.ID AND pm.meta_key = '_octowoo_translation_of'
               )"
        );

        $prod_translated = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type   = 'product'
               AND p.post_status IN ('publish','draft')
               AND EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pm
                   WHERE pm.post_id  = p.ID AND pm.meta_key = '_octowoo_translation_of'
               )"
        );

        // Pages.
        $page_total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type   = 'page'
               AND p.post_status IN ('publish','draft')
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pm
                   WHERE pm.post_id = p.ID AND pm.meta_key = '_octowoo_translation_of'
               )"
        );
        $page_translated = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type   = 'page'
               AND p.post_status IN ('publish','draft')
               AND EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pm
                   WHERE pm.post_id = p.ID AND pm.meta_key = '_octowoo_translation_of'
               )"
        );

        $cat_data   = $check_term_type( 'product_cat', $primary_lang, $secondary_lang );
        $brand_data = ( $brand_tax !== '' )
            ? $check_term_type( $brand_tax, $primary_lang, $secondary_lang )
            : [ 'total' => 0, 'translated' => 0, 'missing' => 0 ];

        $prod_data = [
            'total'      => $prod_total,
            'translated' => $prod_translated,
            'missing'    => max( 0, $prod_total - $prod_translated ),
        ];
        $page_data = [
            'total'      => $page_total,
            'translated' => $page_translated,
            'missing'    => max( 0, $page_total - $page_translated ),
        ];

        $total_missing = $cat_data['missing'] + $brand_data['missing']
                       + $prod_data['missing'] + $page_data['missing'];

        return [
            'secondary_lang' => $secondary_lang,
            'categories'     => $cat_data,
            'brands'         => $brand_data,
            'products'       => $prod_data,
            'pages'          => $page_data,
            'ready'          => ( $total_missing === 0 ),
        ];
    }

    // ── Static registration helper ────────────────────────────────────────────

    /**
     * Hook into OctoWoo action events (for real-time translation as migrators run).
     * Called by MigrationManager::bootstrap() when multilingual is enabled.
     *
     * @param array $config  Full resolved config.
     */
    public static function registerHooks( array $config ): void {
        if ( empty( $config['multilingual']['enabled'] ) ) {
            return;
        }

        /**
         * Fires after octowoo_migration_finished so all ID maps are populated
         * before we attempt the translation pass.
         */
        add_action( 'octowoo_migration_finished', function ( string $run_id, array $report, array $resolved_config ) {
            // WpmlIntegration is run as a formal migrator in MIGRATOR_ORDER,
            // so this hook is intentionally left as a lightweight callback.
            do_action( 'octowoo_multilingual_pass_complete', $run_id );
        }, 10, 3 );
    }
}
