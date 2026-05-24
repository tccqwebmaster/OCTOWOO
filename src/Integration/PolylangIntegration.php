<?php
/**
 * Polylang multilingual integration — v2.5.50 full rebuild.
 *
 * Full parity with WpmlIntegration.php. Every critical fix applied:
 *  - global $wpdb in all methods (background AS jobs)
 *  - direct $wpdb->insert() instead of wp_insert_post() (10-20x faster)
 *  - pll_set_post_language() + pll_save_post_translations() after direct insert
 *  - Bulk OC description prefetch (1 query per chunk, not 1 per product)
 *  - NOT EXISTS query to skip already-translated products (safe re-runs)
 *  - Chunked processing with checkpoint tracking
 *  - wp_suspend_cache_invalidation() + wp_defer_term_counting()
 *
 * @package OctoWoo\Integration
 */

namespace OctoWoo\Integration;

defined( 'ABSPATH' ) || exit;

use OctoWoo\Core\Logger;
use OctoWoo\Core\CheckpointManager;
use OctoWoo\Core\DatabaseConnector;
use OctoWoo\Core\BatchProcessor;
use OctoWoo\Core\RankMathHelper;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

class PolylangIntegration {

    const KEY = 'multilingual';

    private Logger            $logger;
    private CheckpointManager $checkpoint;
    private BatchProcessor    $batch;
    private array             $config;
    private string            $primary_lang;
    private string            $secondary_lang;
    private DatabaseConnector $oc;
    private array             $sec_desc_cache = [];

    public function __construct( Logger $logger, CheckpointManager $checkpoint, array $config ) {
        $this->logger         = $logger;
        $this->checkpoint     = $checkpoint;
        $this->config         = $config;
        $this->batch          = new BatchProcessor( $logger );

        $pri = $config['multilingual']['primary_locale']   ?? 'en';
        $sec = $config['multilingual']['secondary_locale'] ?? 'ar';
        $this->primary_lang   = strtolower( explode( '_', $pri )[0] );
        $this->secondary_lang = strtolower( explode( '_', $sec )[0] );

        $this->oc = new DatabaseConnector( $config['db'] ?? [] );
    }

    // ── Entry point ──────────────────────────────────────────────────────────

    public function migrate(): array {
        global $wpdb;

        if ( ! $this->isAvailable() ) {
            $this->logger->warning( '[polylang] Polylang not active — skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'is_done' => true ];
        }

        $this->logger->info( "[polylang] Using Polylang. Primary: {$this->primary_lang} | Secondary: {$this->secondary_lang}" );

        // Suppress hooks for speed.
        wp_suspend_cache_invalidation( true );
        wp_defer_term_counting( true );
        remove_all_actions( 'woocommerce_update_product' );
        remove_all_actions( 'woocommerce_new_product' );

        $batch_size    = max( 1, (int) ( $this->config['migration']['batch_size'] ?? 50 ) );
        $terms_key     = 'octowoo_pll_terms_' . $this->checkpoint->getRunId();
        $terms_state   = get_transient( $terms_key );
        $processed     = 0;
        $skipped       = 0;
        $failed        = 0;

        if ( ! is_array( $terms_state ) ) {
            $terms_state = [ 'cat_done' => false, 'brand_done' => false, 'done' => false,
                             'inited' => false, 'products_done' => 0 ];
        }

        // ── Terms phase ──────────────────────────────────────────────────────
        if ( ! $terms_state['done'] ) {
            $product_total = $this->countUntranslatedProducts();

            if ( ! $terms_state['inited'] ) {
                $this->checkpoint->init( self::KEY, $product_total );
                $this->checkpoint->start( self::KEY );
                $terms_state['inited'] = true;
            }

            if ( ! $terms_state['cat_done'] ) {
                [ $p, $s, $f ] = $this->translateCategoriesChunk( $batch_size );
                $processed += $p; $skipped += $s; $failed += $f;
                if ( $p + $s + $f < $batch_size ) {
                    $terms_state['cat_done'] = true;
                    $this->logger->info( '[polylang] Categories translation complete.' );
                }
                set_transient( $terms_key, $terms_state, DAY_IN_SECONDS );
                return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'is_done' => false ];
            }

            if ( ! $terms_state['brand_done'] ) {
                [ $p, $s, $f ] = $this->translateBrandsChunk( $batch_size );
                $processed += $p; $skipped += $s; $failed += $f;
                if ( $p + $s + $f < $batch_size ) {
                    $terms_state['brand_done'] = true;
                    $this->logger->info( '[polylang] Brands translation complete.' );
                }
                set_transient( $terms_key, $terms_state, DAY_IN_SECONDS );
                return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'is_done' => false ];
            }

            $terms_state['done'] = true;
            set_transient( $terms_key, $terms_state, DAY_IN_SECONDS );
            $this->logger->info( "[polylang] All terms done. Starting products: total={$product_total}" );
            return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'is_done' => false ];
        }

        // ── Products phase ───────────────────────────────────────────────────
        $product_total = $this->countUntranslatedProducts();

        // Fetch untranslated products via NOT EXISTS — safe across re-runs.
        $product_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID AS wc_id, COALESCE(pm.meta_value, 0) AS oc_id
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_octowoo_oc_id'
                 WHERE p.post_type = 'product'
                   AND p.post_status IN ('publish','draft')
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} pm_x
                       WHERE pm_x.post_id = p.ID AND pm_x.meta_key = '_octowoo_translation_of'
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} pll_lang
                       JOIN {$wpdb->postmeta} pll_tr
                           ON pll_tr.meta_key = '_pll_translation'
                          AND pll_tr.meta_value = p.ID
                       WHERE pll_lang.post_id IN (
                           SELECT post_id FROM {$wpdb->postmeta}
                           WHERE meta_key = '_octowoo_translation_of' AND meta_value = p.ID
                       )
                   )
                 ORDER BY p.ID ASC
                 LIMIT %d",
                $batch_size
            ),
            ARRAY_A
        );

        if ( ! empty( $product_rows ) ) {
            $oc_ids = array_filter( array_map( fn( $r ) => (int) $r['oc_id'], $product_rows ) );
            if ( ! empty( $oc_ids ) ) {
                $this->prefetchSecDescriptionsFromOC( $oc_ids );
            }

            [ $p, $s, $f ] = $this->translateProductsFromRows( $product_rows );
            $processed += $p; $skipped += $s; $failed += $f;

            $terms_state['products_done'] = ( (int) ( $terms_state['products_done'] ?? 0 ) ) + count( $product_rows );
            set_transient( $terms_key, $terms_state, DAY_IN_SECONDS );
            $this->checkpoint->update( self::KEY, $terms_state['products_done'], count( $product_rows ) );

            $this->logger->info( "[polylang] Products chunk done: {$terms_state['products_done']}/{$product_total}, translated={$p}, skipped={$s}, failed={$f}" );
        } else {
            $terms_state['products_done'] = $product_total;
            set_transient( $terms_key, $terms_state, DAY_IN_SECONDS );
        }

        $products_done = (int) ( $terms_state['products_done'] ?? 0 );
        if ( $products_done >= $product_total || empty( $product_rows ) ) {
            // Translate pages.
            [ $p, $s, $f ] = $this->translatePages();
            $processed += $p; $skipped += $s; $failed += $f;

            wp_suspend_cache_invalidation( false );
            wp_defer_term_counting( false );
            wp_cache_flush();
            delete_transient( $terms_key );

            $this->checkpoint->complete( self::KEY );
            $this->logger->info( "[polylang] ✔ Multilingual complete. processed={$processed} skipped={$skipped} failed={$failed}" );
            return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'is_done' => true ];
        }

        return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'is_done' => false ];
    }

    // ── Count untranslated products ──────────────────────────────────────────

    private function countUntranslatedProducts(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish','draft')
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pm_x
                   WHERE pm_x.post_id = p.ID AND pm_x.meta_key = '_octowoo_translation_of'
               )"
        );
    }

    // ── Translate product rows ───────────────────────────────────────────────

    private function translateProductsFromRows( array $rows ): array {
        global $wpdb;
        $sfx       = $this->sfx();
        $processed = 0; $skipped = 0; $failed = 0;

        foreach ( $rows as $row ) {
            $primary_id = (int) $row['wc_id'];
            $oc_id      = (int) $row['oc_id'];

            $primary = get_post( $primary_id );
            if ( ! $primary ) { $failed++; continue; }

            $sec_title   = (string) get_post_meta( $primary_id, '_octowoo_name'              . $sfx, true );
            $sec_content = (string) get_post_meta( $primary_id, '_octowoo_description'       . $sfx, true );
            $sec_excerpt = (string) get_post_meta( $primary_id, '_octowoo_short_description' . $sfx, true );

            // Re-fetch from OC if not Arabic.
            $has_arabic = preg_match( '/[\x{0600}-\x{06FF}]/u', $sec_content . $sec_title );
            if ( ! $has_arabic && $oc_id > 0 ) {
                $fresh = $this->fetchSecDescriptionFromOC( $oc_id );
                if ( $fresh ) {
                    if ( isset( $fresh['name'] ) && $fresh['name'] !== '' ) {
                        $sec_title = sanitize_text_field( $fresh['name'] );
                    }
                    if ( isset( $fresh['description'] ) && $fresh['description'] !== '' ) {
                        $sec_content = wp_kses_post( $fresh['description'] );
                    }
                }
            }

            if ( $sec_title === '' ) { $sec_title = $primary->post_title; }
            if ( $sec_content === '' ) { $sec_content = $primary->post_content; }
            if ( $sec_excerpt === '' ) { $sec_excerpt = $primary->post_excerpt; }

            // Check existing Polylang translation.
            $existing_id = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $primary_id, $this->secondary_lang ) : 0;

            if ( $existing_id > 0 && get_post( $existing_id ) ) {
                // Update existing — direct DB write.
                $wpdb->update(
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
                $this->copyProductMeta( $primary_id, $existing_id );
                $this->applyYoastMeta( $primary_id, $existing_id, $sfx );
                // Ensure Polylang link is correct.
                if ( function_exists( 'pll_set_post_language' ) ) {
                    pll_set_post_language( $existing_id, $this->secondary_lang );
                }
                if ( function_exists( 'pll_save_post_translations' ) ) {
                    pll_save_post_translations( [
                        $this->primary_lang   => $primary_id,
                        $this->secondary_lang => $existing_id,
                    ] );
                }
                $processed++;
                continue;
            }

            // Create new Arabic post — direct DB insert.
            $now = current_time( 'mysql' );
            $now_gmt = current_time( 'mysql', true );
            $wpdb->insert(
                $wpdb->posts,
                [
                    'post_title'        => $sec_title,
                    'post_content'      => $sec_content,
                    'post_excerpt'      => $sec_excerpt,
                    'post_status'       => $primary->post_status,
                    'post_type'         => 'product',
                    'post_name'         => $primary->post_name,
                    'post_author'       => $primary->post_author,
                    'menu_order'        => $primary->menu_order,
                    'post_date'         => $now,
                    'post_date_gmt'     => $now_gmt,
                    'post_modified'     => $now,
                    'post_modified_gmt' => $now_gmt,
                    'comment_status'    => 'closed',
                    'ping_status'       => 'closed',
                ]
            );
            $new_id = (int) $wpdb->insert_id;
            if ( ! $new_id ) { $failed++; continue; }

            clean_post_cache( $new_id );

            // Register with Polylang.
            if ( function_exists( 'pll_set_post_language' ) ) {
                pll_set_post_language( $new_id, $this->secondary_lang );
            }
            if ( function_exists( 'pll_save_post_translations' ) ) {
                pll_save_post_translations( [
                    $this->primary_lang   => $primary_id,
                    $this->secondary_lang => $new_id,
                ] );
            }

            // Mark as translation.
            update_post_meta( $new_id, '_octowoo_translation_of',   $primary_id );
            update_post_meta( $new_id, '_octowoo_translation_lang',  $this->secondary_lang );

            $this->copyProductMeta( $primary_id, $new_id );
            $this->applyYoastMeta( $primary_id, $new_id, $sfx );

            $this->logger->info( "[polylang] Created product #{$new_id} ({$this->secondary_lang}) ← #{$primary_id} (OC #{$oc_id})" );
            $processed++;
        }

        return [ $processed, $skipped, $failed ];
    }

    // ── Categories chunk ─────────────────────────────────────────────────────

    private function translateCategoriesChunk( int $batch_size ): array {
        global $wpdb;
        $sfx = $this->sfx();
        $processed = 0; $skipped = 0; $failed = 0;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.term_id, tm.meta_value AS oc_id
                 FROM {$wpdb->terms} t
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 JOIN {$wpdb->termmeta} tm ON tm.term_id = t.term_id AND tm.meta_key = '_octowoo_oc_id'
                 WHERE tt.taxonomy = 'product_cat'
                 LIMIT %d",
                $batch_size
            ),
            ARRAY_A
        );

        foreach ( (array) $rows as $row ) {
            $term_id = (int) $row['term_id'];
            $oc_id   = (int) $row['oc_id'];

            if ( function_exists( 'pll_set_term_language' ) ) {
                pll_set_term_language( $term_id, $this->primary_lang );
            }

            $sec_name = (string) get_term_meta( $term_id, '_octowoo_name'        . $sfx, true );
            $sec_desc = (string) get_term_meta( $term_id, '_octowoo_description' . $sfx, true );

            $existing_id = function_exists( 'pll_get_term' ) ? (int) pll_get_term( $term_id, $this->secondary_lang ) : 0;

            if ( $existing_id > 0 ) {
                $wpdb->update( $wpdb->terms, [ 'name' => $sec_name ], [ 'term_id' => $existing_id ] );
                $wpdb->update( $wpdb->term_taxonomy, [ 'description' => $sec_desc ], [ 'term_id' => $existing_id ] );
                clean_term_cache( $existing_id, 'product_cat' );
                $processed++;
                continue;
            }

            if ( $sec_name === '' ) {
                $primary_term = get_term( $term_id, 'product_cat' );
                if ( ! $primary_term || is_wp_error( $primary_term ) ) { $skipped++; continue; }
                $sec_name = $primary_term->name;
            }

            $result = wp_insert_term( sanitize_text_field( $sec_name ), 'product_cat', [
                'description' => wp_kses_post( $sec_desc ),
            ] );

            if ( is_wp_error( $result ) ) {
                $eid = (int) $result->get_error_data( 'term_exists' );
                if ( $eid > 0 ) {
                    if ( function_exists( 'pll_set_term_language' ) ) { pll_set_term_language( $eid, $this->secondary_lang ); }
                    if ( function_exists( 'pll_save_term_translations' ) ) {
                        pll_save_term_translations( [ $this->primary_lang => $term_id, $this->secondary_lang => $eid ] );
                    }
                    $processed++;
                } else { $failed++; }
                continue;
            }

            $new_id = (int) $result['term_id'];
            if ( function_exists( 'pll_set_term_language' ) ) { pll_set_term_language( $new_id, $this->secondary_lang ); }
            if ( function_exists( 'pll_save_term_translations' ) ) {
                pll_save_term_translations( [ $this->primary_lang => $term_id, $this->secondary_lang => $new_id ] );
            }
            $this->logger->info( "[polylang] Created category #{$new_id} ({$this->secondary_lang}) for OC #{$oc_id}" );
            $processed++;
        }

        return [ $processed, $skipped, $failed ];
    }

    // ── Brands chunk ─────────────────────────────────────────────────────────

    private function translateBrandsChunk( int $batch_size ): array {
        global $wpdb;
        $sfx  = $this->sfx();
        $taxes = [ 'pwb-brand', 'yith_product_brand', 'product_brand', 'pa_brand' ];
        $tax  = '';
        foreach ( $taxes as $t ) {
            if ( taxonomy_exists( $t ) ) { $tax = $t; break; }
        }
        if ( ! $tax ) { return [ 0, 0, 0 ]; }

        $processed = 0; $skipped = 0; $failed = 0;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.term_id, tm.meta_value AS oc_id
                 FROM {$wpdb->terms} t
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 JOIN {$wpdb->termmeta} tm ON tm.term_id = t.term_id AND tm.meta_key = '_octowoo_oc_manufacturer_id'
                 WHERE tt.taxonomy = %s
                 LIMIT %d",
                $tax, $batch_size
            ),
            ARRAY_A
        );

        foreach ( (array) $rows as $row ) {
            $term_id = (int) $row['term_id'];
            $sec_name = (string) get_term_meta( $term_id, '_octowoo_name' . $sfx, true );

            if ( function_exists( 'pll_set_term_language' ) ) {
                pll_set_term_language( $term_id, $this->primary_lang );
            }

            $existing_id = function_exists( 'pll_get_term' ) ? (int) pll_get_term( $term_id, $this->secondary_lang ) : 0;
            if ( $existing_id > 0 ) {
                if ( $sec_name !== '' ) {
                    $wpdb->update( $wpdb->terms, [ 'name' => $sec_name ], [ 'term_id' => $existing_id ] );
                }
                $processed++;
                continue;
            }

            if ( $sec_name === '' ) { $skipped++; continue; }

            $result = wp_insert_term( sanitize_text_field( $sec_name ), $tax );
            if ( is_wp_error( $result ) ) { $failed++; continue; }

            $new_id = (int) $result['term_id'];
            if ( function_exists( 'pll_set_term_language' ) ) { pll_set_term_language( $new_id, $this->secondary_lang ); }
            if ( function_exists( 'pll_save_term_translations' ) ) {
                pll_save_term_translations( [ $this->primary_lang => $term_id, $this->secondary_lang => $new_id ] );
            }
            $processed++;
        }

        return [ $processed, $skipped, $failed ];
    }

    // ── Pages ────────────────────────────────────────────────────────────────

    private function translatePages(): array {
        global $wpdb;
        $sfx = $this->sfx();
        $processed = 0; $skipped = 0; $failed = 0;

        $rows = $wpdb->get_results(
            "SELECT wc_id AS post_id, oc_id FROM {$wpdb->prefix}octowoo_id_map WHERE entity_type = 'information' ORDER BY oc_id ASC",
            ARRAY_A
        );

        foreach ( (array) $rows as $row ) {
            $post_id     = (int) $row['post_id'];
            $sec_title   = (string) get_post_meta( $post_id, '_octowoo_title' . $sfx, true );
            $sec_content = (string) get_post_meta( $post_id, '_octowoo_desc'  . $sfx, true );

            if ( $sec_title === '' ) { $skipped++; continue; }

            $primary = get_post( $post_id );
            if ( ! $primary ) { $failed++; continue; }
            if ( $sec_content === '' ) { $sec_content = $primary->post_content; }

            if ( function_exists( 'pll_set_post_language' ) ) {
                pll_set_post_language( $post_id, $this->primary_lang );
            }

            $existing_id = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $post_id, $this->secondary_lang ) : 0;
            if ( $existing_id > 0 ) {
                $wpdb->update( $wpdb->posts, [
                    'post_title'        => $sec_title,
                    'post_content'      => $sec_content,
                    'post_modified'     => current_time( 'mysql' ),
                    'post_modified_gmt' => current_time( 'mysql', true ),
                ], [ 'ID' => $existing_id ] );
                clean_post_cache( $existing_id );
                $processed++;
                continue;
            }

            $now = current_time( 'mysql' );
            $wpdb->insert( $wpdb->posts, [
                'post_title' => $sec_title, 'post_content' => $sec_content,
                'post_status' => 'publish', 'post_type' => 'page',
                'post_name' => $primary->post_name, 'post_author' => $primary->post_author,
                'post_date' => $now, 'post_date_gmt' => $now,
                'post_modified' => $now, 'post_modified_gmt' => $now,
            ] );
            $new_id = (int) $wpdb->insert_id;
            if ( ! $new_id ) { $failed++; continue; }

            if ( function_exists( 'pll_set_post_language' ) ) { pll_set_post_language( $new_id, $this->secondary_lang ); }
            if ( function_exists( 'pll_save_post_translations' ) ) {
                pll_save_post_translations( [ $this->primary_lang => $post_id, $this->secondary_lang => $new_id ] );
            }
            $processed++;
        }

        return [ $processed, $skipped, $failed ];
    }

    // ── Bulk OC description prefetch (1 query per chunk) ─────────────────────

    private function prefetchSecDescriptionsFromOC( array $oc_ids ): void {
        if ( empty( $oc_ids ) ) { return; }
        $this->sec_desc_cache = [];
        try {
            $pfx     = rtrim( $this->config['db']['prefix'] ?? 'oc_', '_' ) . '_';
            $pri_lid = (int) ( $this->config['multilingual']['primary_language_id']   ?? 1 );
            $sec_lid = (int) ( $this->config['multilingual']['secondary_language_id'] ?? 3 );
            $ph      = implode( ',', array_fill( 0, count( $oc_ids ), '?' ) );

            $all_rows = $this->oc->fetchAll(
                "SELECT product_id, language_id, name, description, meta_title, meta_description, meta_keyword, tag
                 FROM `{$pfx}product_description`
                 WHERE product_id IN ({$ph}) AND language_id != ?
                 ORDER BY product_id ASC, language_id ASC",
                array_merge( $oc_ids, [ $pri_lid ] )
            );

            $grouped = [];
            foreach ( (array) $all_rows as $row ) {
                $grouped[ (int) $row['product_id'] ][] = $row;
            }

            foreach ( $oc_ids as $oc_id ) {
                $rows = $grouped[ $oc_id ] ?? [];
                if ( empty( $rows ) ) { $this->sec_desc_cache[ $oc_id ] = null; continue; }

                $selected = null;
                foreach ( $rows as $r ) {
                    if ( $sec_lid > 0 && (int) $r['language_id'] === $sec_lid
                         && preg_match( '/[\x{0600}-\x{06FF}]/u', $r['name'] . $r['description'] ) ) {
                        $selected = $r; break;
                    }
                }
                if ( ! $selected ) {
                    foreach ( $rows as $r ) {
                        if ( preg_match( '/[\x{0600}-\x{06FF}]/u', $r['name'] . $r['description'] ) ) {
                            $selected = $r; break;
                        }
                    }
                }
                if ( ! $selected ) {
                    foreach ( $rows as $r ) {
                        if ( $sec_lid > 0 && (int) $r['language_id'] === $sec_lid ) { $selected = $r; break; }
                    }
                }
                $this->sec_desc_cache[ $oc_id ] = $selected ?? $rows[0];
            }
        } catch ( \Throwable $e ) {
            $this->logger->warning( '[polylang] prefetchSecDescriptionsFromOC failed: ' . $e->getMessage() );
        }
    }

    private function fetchSecDescriptionFromOC( int $oc_id ): ?array {
        if ( array_key_exists( $oc_id, $this->sec_desc_cache ) ) {
            return $this->sec_desc_cache[ $oc_id ];
        }
        try {
            $pfx     = rtrim( $this->config['db']['prefix'] ?? 'oc_', '_' ) . '_';
            $pri_lid = (int) ( $this->config['multilingual']['primary_language_id'] ?? 1 );
            $all     = $this->oc->fetchAll(
                "SELECT language_id, name, description FROM `{$pfx}product_description`
                 WHERE product_id = ? AND language_id != ?",
                [ $oc_id, $pri_lid ]
            );
            foreach ( (array) $all as $r ) {
                if ( preg_match( '/[\x{0600}-\x{06FF}]/u', $r['name'] . $r['description'] ) ) return $r;
            }
            return $all[0] ?? null;
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    // ── Meta helpers ─────────────────────────────────────────────────────────

    private function copyProductMeta( int $from_id, int $to_id ): void {
        $keys = [
            '_sku', '_regular_price', '_price', '_sale_price',
            '_stock', '_stock_status', '_manage_stock', '_backorders', '_sold_individually',
            '_weight', '_length', '_width', '_height', '_virtual', '_downloadable',
            '_product_attributes', '_tax_status', '_tax_class',
            '_product_image_gallery', '_octowoo_oc_id', '_octowoo_oc_image_path',
        ];
        foreach ( $keys as $key ) {
            $val = get_post_meta( $from_id, $key, true );
            if ( $val !== '' && $val !== false ) { update_post_meta( $to_id, $key, $val ); }
        }
        $thumb = (int) get_post_thumbnail_id( $from_id );
        if ( $thumb > 0 ) { set_post_thumbnail( $to_id, $thumb ); }

        // Product type term.
        $types = wp_get_object_terms( $from_id, 'product_type', [ 'fields' => 'names' ] );
        if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
            wp_set_object_terms( $to_id, $types, 'product_type' );
        }
    }

    private function applyYoastMeta( int $primary_id, int $trans_id, string $sfx ): void {
        $meta_title = (string) get_post_meta( $primary_id, '_octowoo_metatitle' . $sfx, true );
        $meta_desc  = (string) get_post_meta( $primary_id, '_octowoo_metadesc'  . $sfx, true );
        $meta_kw    = (string) get_post_meta( $primary_id, '_octowoo_metakw'    . $sfx, true );
        if ( $meta_title === '' ) { $meta_title = (string) get_post_meta( $primary_id, '_yoast_wpseo_title',    true ); }
        if ( $meta_desc  === '' ) { $meta_desc  = (string) get_post_meta( $primary_id, '_yoast_wpseo_metadesc', true ); }
        if ( $meta_title !== '' ) { update_post_meta( $trans_id, '_yoast_wpseo_title',    $meta_title ); }
        if ( $meta_desc  !== '' ) { update_post_meta( $trans_id, '_yoast_wpseo_metadesc', $meta_desc  ); }
        if ( $meta_kw    !== '' ) { update_post_meta( $trans_id, '_yoast_wpseo_focuskw',  $meta_kw    ); }
        RankMathHelper::writePostMeta( $trans_id, $meta_title, $meta_desc, $meta_kw );
    }

    // ── Utilities ────────────────────────────────────────────────────────────

    private function isAvailable(): bool {
        return function_exists( 'pll_set_post_language' )
            && function_exists( 'pll_save_post_translations' )
            && function_exists( 'pll_get_post' );
    }

    private function sfx(): string {
        $sec = $this->config['multilingual']['secondary_locale'] ?? 'ar';
        return '_' . strtolower( explode( '_', $sec )[0] );
    }
}
