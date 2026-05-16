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

    // ── Entry point (implements AbstractMigrator::migrate) ────────────────────

    public function migrate(): array {
        $settings_enabled = ! empty( $this->config['multilingual']['enabled'] );
        $run_enabled      = ! empty( $this->config['migration']['run_multilingual'] );

        if ( ! $settings_enabled && ! $run_enabled ) {
            $this->logger->info( '[multilingual] Disabled in config – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'is_done' => true ];
        }

        // Guard against re-execution when a prior chunk already completed this step.
        // In update mode we always re-run so images, secondary-language tags, and brands are
        // re-copied to existing secondary-language translations without requiring Reset Progress.
        if ( $this->onDuplicate() !== 'update' && $this->checkpoint->isCompleted( self::KEY ) ) {
            $this->logger->info( '[multilingual] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'is_done' => true ];
        }

        $this->primary_lang   = $this->config['multilingual']['primary_locale']   ?? 'en';
        $this->secondary_lang = $this->config['multilingual']['secondary_locale']  ?? 'ar';

        $this->adapter = $this->detectAdapter();

        $this->resolveLanguageCodes();

        if ( $this->adapter === 'none' ) {
            $this->logger->warning( '[multilingual] Neither WPML nor Polylang is active. Skipping translation pass.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'is_done' => true ];
        }

        $this->logger->info( "[multilingual] Using adapter: {$this->adapter}. Primary: {$this->primary_lang} | Secondary: {$this->secondary_lang}" );

        $chunk_mode  = $this->batch->isChunkMode();
        $batch_size  = max( 1, (int) ( $this->config['migration']['batch_size'] ?? 20 ) );
        $demo_limit  = max( 0, (int) ( $this->config['migration']['demo_limit'] ?? 0 ) );

        global $wpdb;

        // How many product rows have been translated so far (used as SQL OFFSET).
        $product_offset = $this->checkpoint->getProcessedCount( self::KEY );

        // Total products to translate — query wp_posts directly so Multilingual
        // Recovery works even when the id_map was cleared (e.g. after Reset Progress).
        //
        // We exclude secondary-language copies using NOT EXISTS on the
        // '_octowoo_translation_of' postmeta that our migrator sets on every
        // translated post it creates.  This is faster than JOINing on
        // icl_translations (which may be unindexed for our query) and works for
        // both WPML and Polylang.  It also preserves correct OFFSET-based
        // pagination because the meta is set on the TRANSLATED posts, not the
        // originals, so the primary-language result set is stable across chunks.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $product_total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type   = 'product'
               AND p.post_status IN ('publish','draft')
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pm_x
                   WHERE pm_x.post_id  = p.ID
                     AND pm_x.meta_key = '_octowoo_translation_of'
               )"
        );
        if ( $demo_limit > 0 ) {
            $product_total = min( $product_total, $demo_limit );
        }

        $processed = 0;
        $skipped   = 0;
        $failed    = 0;

        // ── Terms phase: chunked translation of categories + brands ────────────
        // Categories and brand terms MUST be done before products so that
        // copyProductDataToTranslation() can resolve secondary-language term IDs.
        //
        // Because large stores can have 200+ categories and each WPML term
        // operation does several DB inserts, we process terms in batches of
        // $batch_size per chunk (same as products) and track progress in a
        // transient keyed by run_id.  Each chunk returns early (is_done=false)
        // until all taxonomies are done, then products start on the next chunk.
        //
        // Transient structure:
        //   'cat_off'    int|'done'  offset into category rows
        //   'brand_off'  int|'done'  offset into brand rows (or 'done' when no brand tax)
        //   'done'       bool        true once both taxonomies are finished
        //   'inited'     bool        true once checkpoint->init() was called
        $run_id_key    = $this->checkpoint->getRunId();
        $terms_key     = 'octowoo_ml_terms_' . $run_id_key;
        $terms_state   = get_transient( $terms_key );
        $brand_tax     = $this->detectActiveBrandTaxonomy();

        if ( ! is_array( $terms_state ) ) {
            $terms_state = [
                'cat_off'   => 0,
                'brand_off' => ( $brand_tax !== '' ) ? 0 : 'done',
                'done'      => false,
                'inited'    => false,
            ];
        }

        if ( $product_offset === 0 && ! $terms_state['done'] ) {

            if ( ! $terms_state['inited'] ) {
                $this->checkpoint->init( self::KEY, $product_total );
                $this->checkpoint->start( self::KEY );
                $terms_state['inited'] = true;
            }

            // ── Category batch ────────────────────────────────────────────
            if ( $terms_state['cat_off'] !== 'done' ) {
                $cat_seo_map = $this->fetchSecondaryCategorySeoMap();
                [ $p, $s, $f, $cat_has_more ] = $this->translateTerms(
                    'product_cat', $cat_seo_map, 'category',
                    (int) $terms_state['cat_off'], $batch_size
                );
                $processed += $p; $skipped += $s; $failed += $f;

                if ( $cat_has_more ) {
                    $terms_state['cat_off'] = (int) $terms_state['cat_off'] + $batch_size;
                    set_transient( $terms_key, $terms_state, DAY_IN_SECONDS );
                    $this->logger->info( "[multilingual] Categories chunk done (offset={$terms_state['cat_off']}). More categories remain." );
                    return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'is_done' => false ];
                }
                $terms_state['cat_off'] = 'done';
                $this->logger->info( '[multilingual] Categories translation complete.' );
            }

            // ── Brand batch ───────────────────────────────────────────────
            if ( $brand_tax !== '' && $terms_state['brand_off'] !== 'done' ) {
                [ $p, $s, $f, $brand_has_more ] = $this->translateTerms(
                    $brand_tax, [], 'manufacturer',
                    (int) $terms_state['brand_off'], $batch_size
                );
                $processed += $p; $skipped += $s; $failed += $f;

                if ( $brand_has_more ) {
                    $terms_state['brand_off'] = (int) $terms_state['brand_off'] + $batch_size;
                    set_transient( $terms_key, $terms_state, DAY_IN_SECONDS );
                    $this->logger->info( "[multilingual] Brands chunk done (offset={$terms_state['brand_off']}). More brands remain." );
                    return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'is_done' => false ];
                }
                $terms_state['brand_off'] = 'done';
                $this->logger->info( '[multilingual] Brands translation complete.' );
            }

            // All terms done — next chunk will start products.
            $terms_state['done'] = true;
            set_transient( $terms_key, $terms_state, DAY_IN_SECONDS );
            $this->logger->info( "[multilingual] All terms done. Next chunk starts products: total={$product_total}, batch_size={$batch_size}" );
            return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'is_done' => false ];
        }

        // ── Translate a batch of products ─────────────────────────────────────
        if ( $product_total > 0 ) {
            $sec_seo_map = $this->fetchSecondaryLangSeoMap();
            $fetch_limit = $chunk_mode ? $batch_size : $product_total;

            // Fetch products directly from wp_posts with a LEFT JOIN on _octowoo_oc_id.
            // Using wp_posts (not id_map) ensures ALL products are translated,
            // even when id_map was cleared by Reset Progress.
            // NOT EXISTS on '_octowoo_translation_of' excludes secondary-language copies
            // that our migrator created, so we only process primary-language originals.
            // This is stable across chunks (meta is on translations, not originals).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $product_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID AS wc_id, COALESCE(pm.meta_value, 0) AS oc_id
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} pm
                         ON pm.post_id = p.ID AND pm.meta_key = '_octowoo_oc_id'
                     WHERE p.post_type   = 'product'
                       AND p.post_status IN ('publish','draft')
                       AND NOT EXISTS (
                           SELECT 1 FROM {$wpdb->postmeta} pm_x
                           WHERE pm_x.post_id  = p.ID
                             AND pm_x.meta_key = '_octowoo_translation_of'
                       )
                     ORDER BY p.ID ASC
                     LIMIT %d OFFSET %d",
                    $fetch_limit,
                    $product_offset
                ),
                ARRAY_A
            );

            if ( ! empty( $product_rows ) ) {
                // Pre-fetch secondary + primary OC tags for this batch of products
                // in a single query each to eliminate the N+1 OC DB query pattern
                // previously found in copyProductDataToTranslation().
                $batch_oc_ids = array_filter( array_map( fn( $r ) => (int) $r['oc_id'], $product_rows ) );
                if ( ! empty( $batch_oc_ids ) ) {
                    $this->prefetchSecLangTagsForProducts( $batch_oc_ids );
                }

                [ $p, $s, $f ] = $this->translatePostsFromRows(
                    $product_rows,
                    'product',
                    '_octowoo_name' . $this->secLangSuffix(),
                    '_octowoo_description' . $this->secLangSuffix(),
                    $sec_seo_map
                );
                $processed += $p; $skipped += $s; $failed += $f;

                $batch_count    = count( $product_rows );
                $new_offset     = $product_offset + $batch_count;
                $this->checkpoint->update( self::KEY, $new_offset, $batch_count );
                $product_offset = $new_offset;

                $this->logger->info( "[multilingual] Products chunk done: offset={$product_offset}/{$product_total}, translated={$p}, skipped={$s}, failed={$f}" );
            } else {
                // No rows returned — treat as done.
                $product_offset = $product_total;
            }
        }

        // ── Last chunk: translate pages + complete ────────────────────────────
        if ( $product_offset >= $product_total ) {
            // Pages (InformationMigrator) are small; process them all at once.
            [ $p, $s, $f ] = $this->translatePosts( 'page', '_octowoo_title' . $this->secLangSuffix(), '_octowoo_desc' . $this->secLangSuffix() );
            $processed += $p; $skipped += $s; $failed += $f;

            $this->logger->info( "[multilingual] All done. Translated: {$processed}, Skipped: {$skipped}, Errors: {$failed}" );

            // Flush WordPress rewrite rules so newly created/updated term slugs
            // are immediately routable.
            flush_rewrite_rules( false );

            // Clean up the terms-phase transient — no longer needed after completion.
            delete_transient( 'octowoo_ml_terms_' . $this->checkpoint->getRunId() );

            $this->checkpoint->complete( self::KEY );
            return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'is_done' => true ];
        }

        // More product batches remain — signal the caller to schedule another chunk.
        $this->flushSecondaryLangRedirects();
        return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'is_done' => false ];
    }

    /**
     * Pre-fetch secondary (secondary) and primary language tag strings from
     * oc_product_description for a given set of OC product IDs.
     *
     * This eliminates the N+1 OC DB query pattern in copyProductDataToTranslation()
     * where each product made two separate queries.  Instead we make two bulk queries
     * per chunk and cache the results in $this->sec_tags_cache.
     *
     * @param int[] $oc_ids
     */

    /**
     * Rebuild the octowoo_id_map table for entity_type='product' from the
     * _octowoo_oc_id postmeta stored on existing WC product posts.
     *
     * Called automatically when Multilingual Recovery is triggered but the
     * id_map is empty (e.g. after Reset Progress). This allows translation to
     * run without requiring a full product re-migration.
     */
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
        $processed = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ( $rows as $row ) {
            $primary_id = (int) $row['wc_id'];
            $oc_id      = (int) $row['oc_id'];

            $sec_title   = (string) get_post_meta( $primary_id, $title_meta_key,   true );
            $sec_content = (string) get_post_meta( $primary_id, $content_meta_key, true );
            $sec_excerpt = $post_type === 'product'
                ? (string) get_post_meta( $primary_id, '_octowoo_short_description' . $this->secLangSuffix(), true )
                : '';

            $primary_post_raw = get_post( $primary_id );
            if ( ! $primary_post_raw ) {
                $failed++;
                continue;
            }

            // Log preview of what is stored in postmeta — shows if content is Arabic or English
            if ( $sec_content !== '' ) {
                $preview = mb_substr( wp_strip_all_tags( $sec_content ), 0, 100 );
                $has_arabic = preg_match( '/[\x{0600}-\x{06FF}]/u', $sec_content ) ? '[IS ARABIC]' : '[NOT ARABIC - will re-fetch from OC]';
                $this->logger->info( "[multilingual] content-check #{$primary_id}: {$has_arabic} preview: {$preview}" );
            }

            // If postmeta is empty (e.g. ProductMigrator ran with wrong language_id_secondary),
            // try to fetch the secondary-language data directly from the OC database.
            // This ensures the multilingual pass can recover Arabic data even when the
            // primary migration stored empty strings.
            // Re-fetch if postmeta is empty OR has no Arabic characters (English stored in Arabic field)
            $sec_has_arabic   = $sec_content !== '' && preg_match( '/[\x{0600}-\x{06FF}]/u', $sec_content );
            if ( ! $sec_has_arabic && $post_type === 'product' ) {
                $oc_id_meta = (int) get_post_meta( $primary_id, '_octowoo_oc_id', true );
                if ( $oc_id_meta > 0 ) {
                    $fresh = $this->fetchSecDescriptionFromOC( $oc_id_meta );
                    if ( $fresh !== null ) {
                        if ( $sec_title   === '' && isset( $fresh['name'] ) ) {
                            $sec_title   = $this->sanitizeName( $fresh['name'] );
                            $this->logger->info( "[multilingual] Fetched secondary title from OC for product #{$primary_id} (OC #{$oc_id_meta}): '{$sec_title}'" );
                        }
                        if ( isset( $fresh['description'] ) && $fresh['description'] !== '' ) {
                            $fresh_desc = $this->cleanDescription( $fresh['description'] );
                            $fresh_has_ar = preg_match( '/[\x{0600}-\x{06FF}]/u', $fresh_desc );
                            if ( $fresh_has_ar || $sec_content === '' ) {
                                // Use fresh OC content if it has Arabic, or if postmeta was empty.
                                $sec_content     = $fresh_desc;
                                $sec_content_len = mb_strlen( wp_strip_all_tags( $sec_content ) );
                                $ar_label        = $fresh_has_ar ? '[ARABIC]' : '[not Arabic]';
                                $this->logger->info( "[multilingual] Re-fetched description from OC for #{$primary_id}: {$sec_content_len} chars {$ar_label}" );
                                // Update postmeta so future runs don't re-fetch unnecessarily.
                                $sfx_key = '_octowoo_description' . $this->secLangSuffix();
                                update_post_meta( $primary_id, $sfx_key, $sec_content );
                            }
                        }
                        if ( $sec_excerpt === '' && isset( $fresh['tag'] ) ) {
                            // OC tag field = excerpt-equivalent for secondary language.
                            // Leave blank; tags are handled by copyProductDataToTranslation().
                            $sec_excerpt = '';
                        }
                    }
                }
            }

            if ( $sec_title === '' ) {
                $sec_title = $primary_post_raw->post_title;
                $this->logger->debug( "[multilingual] No secondary-language title for {$post_type} #{$primary_id} – using primary title as fallback." );
            }
            if ( $sec_content === '' ) {
                $sec_content = $primary_post_raw->post_content;
            } elseif ( trim( wp_strip_all_tags( $sec_content ) ) === trim( wp_strip_all_tags( $primary_post_raw->post_content ) ) ) {
                // Secondary description identical to primary — OC product likely has the
                // same (untranslated) content in both language rows.
                $this->logger->debug(
                    "[multilingual] Note: secondary-language description for {$post_type} #{$primary_id} is identical to primary. " .
                    'This may mean the OpenCart description was not translated in the source store.'
                );
            }
            if ( $sec_excerpt === '' && $post_type === 'product' ) {
                $sec_excerpt = $primary_post_raw->post_excerpt;
            }

            $existing_translation_id = $this->getExistingTranslationId( $primary_id, 'post_' . $post_type );
            if ( $existing_translation_id > 0 ) {
                if ( $this->isDry() ) {
                    $this->logger->debug( "[DRY-RUN] Would update existing {$this->secondary_lang} translation for {$post_type} #{$primary_id}: {$sec_title}" );
                    $processed++;
                    continue;
                }

                // Temporarily remove WPML's save_post field-sync handler so it
                // doesn't copy primary-language content over our Arabic content
                // when wp_update_post fires save_post on the translated post.
                global $sitepress;
                $wpml_handler_removed = false;
                if ( isset( $sitepress ) && method_exists( $sitepress, 'save_post_handler' ) ) {
                    remove_action( 'save_post', [ $sitepress, 'save_post_handler' ] );
                    $wpml_handler_removed = true;
                }
                // Also try the newer WPML hook name used in WPML 4.5+.
                $wpml_save_removed = remove_filter( 'save_post', [ 'WPML_Translation_Job_Helper', 'save_post_handler' ], 10 );

                $update_data = [
                    'ID'           => $existing_translation_id,
                    'post_title'   => $sec_title,
                    'post_content' => $sec_content,
                    'post_excerpt' => $sec_excerpt,
                    'post_name'    => $primary_post_raw->post_name,
                ];
                $updated = wp_update_post( $update_data, true );

                // Re-hook WPML after our write completes.
                if ( $wpml_handler_removed && isset( $sitepress ) ) {
                    add_action( 'save_post', [ $sitepress, 'save_post_handler' ] );
                }

                if ( is_wp_error( $updated ) ) {
                    $this->logger->error( "[multilingual] Failed updating existing translated post #{$existing_translation_id}: " . $updated->get_error_message() );
                    $failed++;
                    continue;
                }

                if ( $post_type === 'product' ) {
                    $this->copyProductDataToTranslation( $primary_id, $existing_translation_id );
                }

                $this->applyYoastPostMeta( $primary_id, $existing_translation_id );
                $this->fixTranslationSlug( $existing_translation_id, $primary_post_raw->post_name );

                // Force secondary-language content + thumbnail after wp_update_post which fires
                // save_post: WPML field-sync may copy primary-language post_content back over
                // the secondary content we set in $update_data, erasing the description.
                // Direct DB write + meta update bypass all hooks (same as fixTranslationSlug).
                $sec_len = mb_strlen( wp_strip_all_tags( $sec_content ) );
                $pri_len = mb_strlen( wp_strip_all_tags( $primary_post_raw->post_content ) );
                if ( $sec_len > 0 ) {
                    $this->logger->info( "[multilingual] Updating {$post_type} #{$existing_translation_id} — secondary content: {$sec_len} chars | primary: {$pri_len} chars" );
                } else {
                    $this->logger->warning( "[multilingual] ⚠ {$post_type} #{$primary_id}: secondary description is EMPTY — translation will use primary (English) content. Check oc_product_description language_id={$this->langIdSecondary()} has Arabic description." );
                }
                $this->forceTranslationContent(
                    $existing_translation_id,
                    $sec_title,
                    $sec_content,
                    $sec_excerpt,
                    (int) get_post_meta( $primary_id, '_thumbnail_id', true )
                );

                // Read-back verification: confirm the DB actually has our content.
                $verify_post = get_post( $existing_translation_id );
                if ( $verify_post ) {
                    $saved_len = mb_strlen( wp_strip_all_tags( $verify_post->post_content ) );
                    $want_len  = mb_strlen( wp_strip_all_tags( $sec_content ) );
                    if ( $saved_len !== $want_len ) {
                        $this->logger->warning( "[multilingual] ⚠ Content mismatch after write for {$post_type} #{$existing_translation_id}: wanted {$want_len} chars, DB has {$saved_len} chars. WPML may be overwriting — trying direct DB update again." );
                        // Second direct DB write attempt.
                        $this->forceTranslationContent(
                            $existing_translation_id, $sec_title, $sec_content, $sec_excerpt,
                            (int) get_post_meta( $primary_id, '_thumbnail_id', true )
                        );
                    } else {
                        $this->logger->info( "[multilingual] ✔ Verified: {$post_type} #{$existing_translation_id} has {$saved_len} chars of secondary content in DB." );
                    }
                }

                if ( ! empty( $sec_seo_map[ $oc_id ] ) ) {
                    $this->queueSecondaryLangRedirect( $existing_translation_id, $sec_seo_map[ $oc_id ] );
                }

                $this->logger->debug( "[multilingual] Updated existing {$post_type} translation #{$existing_translation_id} from primary #{$primary_id}." );
                $processed++;
                continue;
            }

            if ( $this->isDry() ) {
                $this->logger->debug( "[DRY-RUN] Would create {$this->secondary_lang} translation for {$post_type} #{$primary_id}: {$sec_title}" );
                $processed++;
                continue;
            }

            $translated_id = $this->createTranslatedPost( $primary_post_raw, $sec_title, $sec_content, $post_type, $sec_excerpt );

            if ( ! $translated_id ) {
                $this->logger->error( "[multilingual] Failed to create {$this->secondary_lang} translation for {$post_type} #{$primary_id} – wp_insert_post returned 0." );
                $failed++;
                continue;
            }

            // Register the WPML/Polylang translation link BEFORE copying WC data.
            // This ensures any field-sync triggered by wpml_set_element_language_details
            // fires first, so we can then overwrite with the correct secondary-language values.
            $this->linkPostTranslation( $primary_id, $translated_id, $post_type );

            if ( $post_type === 'product' ) {
                $this->copyProductDataToTranslation( $primary_id, $translated_id );
            }

            $this->fixTranslationSlug( $translated_id, $primary_post_raw->post_name );

            // Force secondary-language content + thumbnail AFTER all WPML/Polylang operations.
            $sec_len_c = mb_strlen( wp_strip_all_tags( $sec_content ) );
            $pri_len_c = mb_strlen( wp_strip_all_tags( $primary_post_raw->post_content ) );
            if ( $sec_len_c === 0 ) {
                $this->logger->warning( "[multilingual] ⚠ {$post_type} #{$primary_id}: secondary description is EMPTY — translation #{$translated_id} will use primary (English) content." );
            } elseif ( $sec_len_c === $pri_len_c ) {
                $this->logger->debug( "[multilingual] Note: secondary content same length as primary for {$post_type} #{$primary_id} (may not be translated in OC)." );
            }
            $this->forceTranslationContent(
                $translated_id,
                $sec_title,
                $sec_content,
                $sec_excerpt,
                (int) get_post_meta( $primary_id, '_thumbnail_id', true )
            );

            if ( ! empty( $sec_seo_map[ $oc_id ] ) ) {
                $this->queueSecondaryLangRedirect( $translated_id, $sec_seo_map[ $oc_id ] );
            }

            $this->logger->debug( "[multilingual] Linked {$post_type} #{$primary_id} ({$this->primary_lang}) ↔ #{$translated_id} ({$this->secondary_lang})" );
            $processed++;
        }

        $this->flushSecondaryLangRedirects();

        return [ $processed, $skipped, $failed ];
    }

    // ── Post translation pass (all-at-once, for small collections like pages) ─

    /**
     * Fetch ALL id_map rows for the given entity type and translate them in one
     * pass.  Only used for 'page' (InformationMigrator content) which is a
     * small set.  Products use the chunked translatePostsFromRows() path.
     *
     * @return int[] [processed, skipped, failed]
     */
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
        $new_id = wp_insert_post( $insert_data, true );
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            do_action( 'wpml_switch_language', null ); // Restore default language.
        }

        if ( is_wp_error( $new_id ) ) {
            $this->logger->error( "[multilingual] Failed creating translated post ({$this->secondary_lang}): " . $new_id->get_error_message() );
            return 0;
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
            // is_primary = true → source_language_code = null (this IS the original).
            $this->wpmlSetPostLanguage( $primary_id, $element_type, $this->primary_lang, $existing_trid, true );
            // Re-fetch trid after language update to ensure we have the canonical value.
            $trid = $this->wpmlGetTrid( $primary_id, $element_type );
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

        // ── Image fallback: re-attempt import when primary product has no thumbnail ──
        // This covers the case where images were unavailable during the primary
        // migration pass (e.g. source server temporarily down, local path not mounted).
        // ProductMigrator stores '_octowoo_oc_image_path' on every product so we
        // can always retry the import here without any extra DB queries.
        $thumb_id = (int) get_post_meta( $source_id, '_thumbnail_id', true );
        if ( $thumb_id <= 0 ) {
            $oc_image_path = (string) get_post_meta( $source_id, '_octowoo_oc_image_path', true );
            if ( $oc_image_path !== '' && $this->imageMigratorInstance() !== null ) {
                $new_thumb = $this->imageMigratorInstance()->importByOcPath( $oc_image_path );
                if ( $new_thumb && $new_thumb > 0 ) {
                    // Apply to primary product as well so it is not missing next time.
                    set_post_thumbnail( $source_id, $new_thumb );
                    set_post_thumbnail( $target_id, $new_thumb );
                }
            }
        }

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
                            // Create (or find existing) secondary-language tag term.
                            $result = wp_insert_term( $sec_tag_name, 'product_tag' );
                            if ( is_wp_error( $result ) && $result->get_error_code() === 'term_exists' ) {
                                $sec_tid = (int) $result->get_error_data( 'term_exists' );
                            } elseif ( ! is_wp_error( $result ) ) {
                                $sec_tid = (int) $result['term_id'];
                            } else {
                                continue;
                            }

                            // Find the matching primary-language WC tag term by position.
                            // If found, force the secondary-language term to share the same slug
                            // so URLs use clean primary-language text instead of encoded characters.
                            if ( isset( $pri_tag_names[ $idx ] ) ) {
                                $pri_term = get_term_by( 'name', $pri_tag_names[ $idx ], 'product_tag' );
                                if ( $pri_term && ! is_wp_error( $pri_term ) ) {
                                    $this->fixTranslationTermSlug( $sec_tid, $pri_term->slug );
                                    // Register WPML translation link between primary and secondary tag terms.
                                    $this->linkTermTranslation( $pri_term->term_id, $sec_tid, 'product_tag' );
                                }
                            }

                            // Register secondary-language tag with WPML (idempotent).
                            if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
                                $sec_term_obj = get_term( $sec_tid, 'product_tag' );
                                if ( $sec_term_obj && ! is_wp_error( $sec_term_obj ) ) {
                                    $sec_tt_id = (int) $sec_term_obj->term_taxonomy_id;
                                    if ( $sec_tt_id > 0 ) {
                                        do_action( 'wpml_set_element_language_details', [
                                            'element_id'           => $sec_tt_id,
                                            'element_type'         => 'tax_product_tag',
                                            'trid'                 => null,
                                            'language_code'        => $this->secondary_lang,
                                            'source_language_code' => null,
                                        ] );
                                    }
                                }
                            }

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
                return $tax;
            }
        }
        return '';
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

            // When secondary-language meta is empty (e.g. wrong language_id_secondary
            // was used during CategoryMigrator run), try to fetch directly from OC.
            if ( ( ! $sec_name || ! $sec_description ) && $oc_id > 0 ) {
                $fresh_cat = $this->fetchSecCategoryDescriptionFromOC( $oc_id );
                if ( $fresh_cat !== null ) {
                    if ( ! $sec_name && ! empty( $fresh_cat['name'] ) ) {
                        $sec_name = sanitize_text_field( $fresh_cat['name'] );
                        $this->logger->info( "[multilingual] Fetched secondary category name directly from OC for term #{$primary_term_id} (OC #{$oc_id})." );
                    }
                    if ( ! $sec_description && ! empty( $fresh_cat['description'] ) ) {
                        $sec_description = wp_kses_post( $fresh_cat['description'] );
                    }
                    // Also update the stored meta so next run doesn't re-fetch from OC.
                    if ( $sec_name ) {
                        update_term_meta( $primary_term_id, '_octowoo_name' . $sfx, $sec_name );
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
                $temp_slug = 'octowoo-sec-' . $existing_translation_id;

                $updated = wp_update_term( $existing_translation_id, $taxonomy, [
                    'name'        => $sec_name,
                    'description' => $sec_description,
                    'slug'        => $temp_slug,
                    'parent'      => $sec_parent,
                ] );

                if ( is_wp_error( $updated ) ) {
                    $this->logger->error( "[multilingual] Failed updating existing translated term #{$existing_translation_id}: " . $updated->get_error_message() );
                    $failed++;
                    continue;
                }

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

            $result = wp_update_term( $sec_term_id, $taxonomy, [ 'parent' => $sec_parent_id ] );
            if ( is_wp_error( $result ) ) {
                $this->logger->warning( "[multilingual] Could not fix secondary-language parent for {$taxonomy} term #{$sec_term_id}: " . $result->get_error_message() );
            } else {
                // wp_update_term may suffix the slug for uniqueness; restore it.
                $pri_term_for_slug = get_term( $pri_term_id, $taxonomy );
                if ( $pri_term_for_slug && ! is_wp_error( $pri_term_for_slug ) ) {
                    $this->fixTranslationTermSlug( $sec_term_id, $pri_term_for_slug->slug );
                }
                $this->logger->debug( "[multilingual] Fixed secondary-language parent for {$taxonomy} term #{$sec_term_id} → parent #{$sec_parent_id}." );
            }
        }
    }

    /**
     * Create a translated taxonomy term in the secondary language.
     */
    private function createTranslatedTerm( \WP_Term $source, string $name, string $description, string $taxonomy, int $sec_parent = 0 ): int {
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
    private function linkTermTranslation( int $primary_term_id, int $translated_term_id, string $taxonomy ): void {
        $primary_term = get_term( $primary_term_id, $taxonomy );
        if ( ! $primary_term || is_wp_error( $primary_term ) ) {
            return;
        }

        $element_type = "tax_{$taxonomy}";

        if ( $this->adapter === 'wpml' ) {
            // Get existing trid FIRST (WPML may have auto-assigned one during
            // wp_insert_term) to avoid creating a duplicate translation group.
            $existing_trid = $this->wpmlGetTridForTerm( $primary_term, $element_type );
            // is_primary = true → source_language_code = null (this IS the original).
            $this->wpmlSetTermLanguage( $primary_term, $element_type, $this->primary_lang, $existing_trid, true );
            // Re-fetch after update for canonical trid.
            $trid = $this->wpmlGetTridForTerm( $primary_term, $element_type );
            $translated_term = get_term( $translated_term_id, $taxonomy );
            if ( $translated_term && ! is_wp_error( $translated_term ) ) {
                // is_primary = false → source_language_code = $this->primary_lang.
                $this->wpmlSetTermLanguage( $translated_term, $element_type, $this->secondary_lang, $trid, false );
            }

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

            if ( $sec_lid > 0 ) {
                $row = $this->oc->fetchAll(
                    "SELECT name, description, meta_title, meta_description, meta_keyword
                     FROM `{$pfx}category_description`
                     WHERE category_id = ? AND language_id = ? LIMIT 1",
                    [ $oc_id, $sec_lid ]
                );
                if ( ! empty( $row ) ) { return $row[0]; }
            }
            // Auto-detect: first non-primary language.
            $all = $this->oc->fetchAll(
                "SELECT language_id, name, description, meta_title, meta_description, meta_keyword
                 FROM `{$pfx}category_description`
                 WHERE category_id = ? AND language_id != ?
                 ORDER BY language_id ASC LIMIT 1",
                [ $oc_id, $pri_lid ]
            );
            if ( ! empty( $all ) ) {
                $this->logger->info( "[multilingual] Auto-detected secondary category description (language_id={$all[0]['language_id']}) for OC #{$oc_id}." );
                return $all[0];
            }
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
        try {
            $pfx     = $this->pfx();
            $pri_lid = $this->langId();
            $sec_lid = $this->langIdSecondary();

            // Try configured secondary language first.
            if ( $sec_lid > 0 ) {
                $row = $this->oc->fetchAll(
                    "SELECT name, description, meta_title, meta_description, meta_keyword, tag
                     FROM `{$pfx}product_description`
                     WHERE product_id = ? AND language_id = ? LIMIT 1",
                    [ $oc_id, $sec_lid ]
                );
                if ( ! empty( $row ) ) {
                    return $row[0];
                }
            }

            // Auto-detect: first language_id that is not primary.
            $all = $this->oc->fetchAll(
                "SELECT language_id, name, description, meta_title, meta_description, meta_keyword, tag
                 FROM `{$pfx}product_description`
                 WHERE product_id = ? AND language_id != ?
                 ORDER BY language_id ASC LIMIT 1",
                [ $oc_id, $pri_lid ]
            );
            if ( ! empty( $all ) ) {
                $this->logger->info( "[multilingual] Auto-detected secondary description (language_id={$all[0]['language_id']}) for OC product #{$oc_id}." );
                return $all[0];
            }
        } catch ( \Throwable $e ) {
            $this->logger->warning( "[multilingual] fetchSecDescriptionFromOC failed for OC #{$oc_id}: " . $e->getMessage() );
        }
        return null;
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
