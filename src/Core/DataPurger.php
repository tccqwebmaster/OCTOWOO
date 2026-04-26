<?php

namespace OctoWoo\Core;

/**
 * DataPurger — removes data that was imported by OctoWoo from OpenCart.
 *
 * Safety contract: ONLY entities tagged with _octowoo_oc_id (or
 * _octowoo_oc_order_id for orders) meta are ever touched.
 * Manually-added products, native WP pages, admin users, and any
 * content that was not created by this plugin are never deleted.
 *
 * Supported entity keys:
 *   products, categories, tags, customers, orders, coupons,
 *   reviews, manufacturers, information, downloads, filters
 */
class DataPurger {

    private Logger $logger;
    private array $config = [];

    public function __construct( Logger $logger, array $config = [] ) {
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Purge one or more entity types.
     *
     * @param  string[] $entities  Entity keys to purge.
     * @param  bool     $force     When true, delete ALL WooCommerce data of each type,
     *                             not just items that were imported by OctoWoo.
     * @return array{results: array<string,int>, diagnostics: array<string,array{total:int,tagged:int}>}
     */
    public function purge( array $entities, bool $force = false ): array {
        // Before purging: backfill any missing _octowoo_oc_id meta from the id_map
        // table. This covers items created by an older code path that ran saveIdMap()
        // but skipped addTermMeta() (e.g. the term_exists slug-lookup bug in pre-v2.4.5
        // releases) as well as any items whose meta was lost due to a partial migration.
        // Non-destructive: update_term_meta / update_post_meta are idempotent.
        if ( ! $force ) {
            $this->repairMetaFromIdMap();
        }

        $results     = [];
        $diagnostics = [];

        $map = [
            'products'      => 'purgeProducts',
            'categories'    => 'purgeCategories',
            'tags'          => 'purgeTags',
            'customers'     => 'purgeCustomers',
            'orders'        => 'purgeOrders',
            'coupons'       => 'purgeCoupons',
            'reviews'       => 'purgeReviews',
            'manufacturers' => 'purgeManufacturers',
            'information'   => 'purgeInformation',
            'downloads'     => 'purgeDownloads',
            'filters'       => 'purgeFilters',
        ];

        foreach ( $entities as $entity ) {
            $method = $map[ $entity ] ?? null;
            if ( ! $method ) {
                continue;
            }
            $mode = $force ? 'force' : 'tagged';
            $this->logger->info( "[purge] Starting purge ({$mode}): {$entity}" );
            try {
                $count = $this->$method( $force );
            } catch ( \Throwable $e ) {
                $this->logger->error( "[purge] {$entity} failed: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
                $results[ $entity ] = 'error: ' . $e->getMessage();
                continue;
            }
            $results[ $entity ] = $count;
            $this->logger->info( "[purge] Finished purge: {$entity} — {$count} item(s) deleted." );

            // When nothing was deleted in tagged mode, collect a diagnostic count
            // (total WC items vs. how many carry the OctoWoo tag) so the caller can
            // advise the user whether Force Purge is needed.
            if ( ! $force && $count === 0 ) {
                $diagnostics[ $entity ] = $this->countEntityItems( $entity );
                $total_wc   = $diagnostics[ $entity ]['total'];
                $total_tag  = $diagnostics[ $entity ]['tagged'];
                if ( $total_wc > 0 && $total_tag === 0 ) {
                    $this->logger->warning(
                        "[purge] {$entity}: {$total_wc} item(s) exist in WooCommerce but NONE carry the " .
                        "_octowoo_oc_id tag (id_map may have been reset). Use Force Purge to remove them."
                    );
                }
            }
        }

        // Always reset MySQL AUTO_INCREMENT counters after purge so a fresh
        // migration doesn't inherit the old high-watermark IDs.
        $this->resetAutoIncrements();

        return [ 'results' => $results, 'diagnostics' => $diagnostics ];
    }

    /**
     * Reset AUTO_INCREMENT counters on the core WordPress tables.
     *
     * After a bulk purge, MySQL's auto-increment watermark stays at the old high
     * value, so the next migrated product gets ID 20001 instead of 1.
     * Running ALTER TABLE … AUTO_INCREMENT = 1 is safe on non-empty tables:
     * MySQL simply chooses MAX(existing_id) + 1 as the real next value, so
     * no existing rows are overwritten.
     *
     * Called automatically at the end of every purge() run.
     */
    private function resetAutoIncrements(): void {
        global $wpdb;

        $tables = [
            $wpdb->posts,                    // Products, pages, orders (post-based).
            $wpdb->terms,                    // Terms shared table (term_id).
            $wpdb->term_taxonomy,            // Taxonomy assignments.
            $wpdb->comments,                 // Reviews.
            $wpdb->users,                    // Customers.
            $wpdb->usermeta,                 // User meta.
        ];

        foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query( "ALTER TABLE `{$table}` AUTO_INCREMENT = 1" );
        }

        $this->logger->info( '[purge] AUTO_INCREMENT counters reset on core WP tables.' );
    }

    // ── Products ──────────────────────────────────────────────────────────────

    private function purgeProducts( bool $force = false ): int {
        global $wpdb;

        $this->clearIdMapEntity( 'product' );
        $this->clearIdMapEntity( 'variation' );
        $this->clearIdMapEntity( 'product_image' );

        if ( $force ) {
            // for stores with thousands of products.

            // Count parent products before deletion (variations are collateral).
            $parent_count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                  WHERE post_type = 'product' AND post_status != 'auto-draft'"
            );

            if ( $parent_count === 0 ) {
                return 0;
            }

            // Collect all IDs (parents + variations) for cascade cleanup.
            $all_ids = array_map( 'intval', (array) $wpdb->get_col(
                "SELECT ID FROM {$wpdb->posts}
                  WHERE post_type IN ('product', 'product_variation')
                    AND post_status != 'auto-draft'"
            ) );

            if ( empty( $all_ids ) ) {
                return 0;
            }

            $csv = implode( ',', $all_ids );

            // Delete attached media (product images + gallery attachments).
            $attachment_ids = array_map( 'intval', (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL
                "SELECT ID FROM {$wpdb->posts}
                  WHERE post_type = 'attachment' AND post_parent IN ({$csv})"
            ) );
            if ( ! empty( $attachment_ids ) ) {
                $att_csv = implode( ',', $attachment_ids );
                $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$att_csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL
                $wpdb->query( "DELETE FROM {$wpdb->posts}    WHERE ID      IN ({$att_csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL
            }

            // Delete child data first, then the posts themselves.
            $wpdb->query( "DELETE FROM {$wpdb->postmeta}          WHERE post_id  IN ({$csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query( "DELETE FROM {$wpdb->posts}              WHERE ID       IN ({$csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL

            // Clear WooCommerce product transients/caches.
            if ( function_exists( 'wc_delete_product_transients' ) ) {
                wc_delete_product_transients();
            }

            $this->logger->info( "[purge] Bulk-deleted {$parent_count} products + variations via SQL." );
            return $parent_count;
        }

        // Tagged (non-force) path — only parent products; variations auto-deleted.
        $ids = $wpdb->get_col(
            "SELECT DISTINCT p.ID
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
              WHERE pm.meta_key = '_octowoo_oc_id'
                AND p.post_type = 'product'"
        );

        $deleted = 0;
        foreach ( array_map( 'intval', $ids ) as $id ) {
            if ( wp_delete_post( $id, true ) ) {
                $deleted++;
            }
        }

        return $deleted;
    }

    // ── Categories / Tags / Manufacturers ─────────────────────────────────────

    private function purgeCategories( bool $force = false ): int {
        $this->clearIdMapEntity( 'category' );
        // Invalidate the topological-sort transient so the next migration run
        // re-builds the sorted category list from a clean OC database state.
        delete_transient( 'octowoo_cat_topo_' . md5( $this->config['oc_db']['prefix'] ?? 'oc_' ) );
        return $this->purgeTermsByTaxonomy( 'product_cat', $force );
    }

    private function purgeTags( bool $force = false ): int {
        $this->clearIdMapEntity( 'tag' );
        return $this->purgeTermsByTaxonomy( 'product_tag', $force );
    }

    private function purgeManufacturers( bool $force = false ): int {
        $this->clearIdMapEntity( 'manufacturer' );
        $deleted = 0;
        // Support common brand taxonomy plugins.
        foreach ( [ 'product_brand', 'pwb-brand', 'pa_brand' ] as $tax ) {
            if ( taxonomy_exists( $tax ) ) {
                $deleted += $this->purgeTermsByTaxonomy( $tax, $force );
            }
        }
        return $deleted;
    }

    private function purgeFilters( bool $force = false ): int {
        $this->clearIdMapEntity( 'filter' );
        $deleted = 0;
        foreach ( [ 'product_filter', 'pa_filter' ] as $tax ) {
            if ( taxonomy_exists( $tax ) ) {
                $deleted += $this->purgeTermsByTaxonomy( $tax, $force );
            }
        }
        return $deleted;
    }

    /**
     * Delete all id_map rows for a given entity type.
     * Called by purge methods so that stale OC→WC mappings don't survive
     * across re-migrations (which causes ID-collision bugs after AUTO_INCREMENT reset).
     */
    private function clearIdMapEntity( string $entity_type ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'octowoo_id_map';
        $wpdb->delete( $table, [ 'entity_type' => $entity_type ], [ '%s' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    private function purgeTermsByTaxonomy( string $taxonomy, bool $force = false ): int {
        global $wpdb;

        if ( $force ) {
            // Use direct SQL instead of wp_delete_term() for force mode.
            // When WPML is active, wp_delete_term() called in the primary-language
            // context silently skips secondary-language (Arabic) terms via its hooks.
            // This leaves orphaned Arabic terms with no icl_translations row, and on
            // the next migration run those orphans are invisible to getExistingTranslationId()
            // (which queries WPML), causing translateTerms() to create brand-new duplicates
            // on top of the surviving orphans — hence duplicate Arabic categories.
            $term_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT tt.term_id FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy = %s",
                $taxonomy
            ) ) );

            if ( empty( $term_ids ) ) {
                return 0;
            }

            $id_csv = implode( ',', $term_ids );

            // 1. Remove object → term assignments (product in category, etc.).
            $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                "DELETE tr FROM {$wpdb->term_relationships} tr
                  JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tt.taxonomy = %s",
                $taxonomy
            ) );

            // 2. Remove all term meta (thumbnail_id, _octowoo_oc_id, WPML flags, etc.).
            $wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE term_id IN ({$id_csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL

            // 3. Remove taxonomy registration rows.
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) ); // phpcs:ignore WordPress.DB.PreparedSQL

            // 4. Remove term rows that are no longer referenced by any other taxonomy.
            $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL
                "DELETE t FROM {$wpdb->terms} t
                  LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 WHERE tt.term_id IS NULL AND t.term_id IN ({$id_csv})"
            );

            // Flush WP in-memory term caches so stale data is not served.
            clean_taxonomy_cache( $taxonomy );
            foreach ( $term_ids as $tid ) {
                clean_term_cache( $tid );
            }

            $deleted = count( $term_ids );
        } else {
            // Tagged mode: only delete terms that carry OctoWoo meta, using
            // wp_delete_term() so WP hooks (wc term counts etc.) fire correctly.

            // Include both primary OC terms (_octowoo_oc_id) and WPML translation
            // terms (_octowoo_translation_lang), so Arabic/secondary terms are also
            // purged even when they have no OC id of their own.
            $term_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT tm.term_id
                   FROM {$wpdb->termmeta} tm
                   JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                  WHERE ( tm.meta_key = '_octowoo_oc_id' OR tm.meta_key = '_octowoo_translation_lang' )
                    AND tt.taxonomy   = %s",
                $taxonomy
            ) );

            if ( empty( $term_ids ) ) {
                return 0;
            }

            $deleted = 0;
            foreach ( array_map( 'intval', $term_ids ) as $term_id ) {
                $result = wp_delete_term( $term_id, $taxonomy );
                if ( $result && ! is_wp_error( $result ) ) {
                    $deleted++;
                }
            }
        }

        // Clean up orphaned WPML icl_translations rows for deleted terms.
        // wp_delete_term() does not remove these rows, leaving stale WPML data.
        // In force mode, this removes ALL icl rows for the taxonomy since we've
        // deleted every term_taxonomy row above.
        if ( $deleted > 0 ) {
            $icl_table = $wpdb->prefix . 'icl_translations';
            $has_icl   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $icl_table ) );
            if ( $has_icl ) {
                $type = 'tax_' . $taxonomy;
                $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
                    "DELETE FROM `{$icl_table}` WHERE element_type = %s AND element_id NOT IN (SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s)", // phpcs:ignore WordPress.DB.PreparedSQL
                    $type,
                    $taxonomy
                ) );
            }
        }

        return $deleted;
    }

    // ── Customers ─────────────────────────────────────────────────────────────

    private function purgeCustomers( bool $force = false ): int {
        global $wpdb;

        $this->clearIdMapEntity( 'customer' );

        if ( ! function_exists( 'wp_delete_user' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        // Collect IDs: force = all octowoo-tagged; tagged = same (never delete all users).
        // Always exclude administrators regardless of mode.
        $cap_key = $wpdb->prefix . 'capabilities';
        $user_ids = $wpdb->get_col(
            "SELECT DISTINCT um.user_id
               FROM {$wpdb->usermeta} um
              WHERE um.meta_key = '_octowoo_oc_id'
                AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->usermeta} cap
                     WHERE cap.user_id  = um.user_id
                       AND cap.meta_key = '{$cap_key}'
                       AND cap.meta_value LIKE '%administrator%'
                )"
        );

        if ( empty( $user_ids ) ) {
            return 0;
        }

        // Bulk direct SQL — same as what wp_delete_user() does internally,
        // but without per-user PHP hooks (crucial for 7,000+ customers).
        $ids_int = array_map( 'intval', $user_ids );
        $csv     = implode( ',', $ids_int );

        $wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE user_id IN ({$csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query( "DELETE FROM {$wpdb->users}    WHERE ID       IN ({$csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL

        // Reassign posts owned by deleted users to the first admin.
        $admin_id = (int) $wpdb->get_var(
            "SELECT u.ID FROM {$wpdb->users} u
               JOIN {$wpdb->usermeta} m ON m.user_id = u.ID
              WHERE m.meta_key = '{$cap_key}'
                AND m.meta_value LIKE '%administrator%'
              LIMIT 1"
        );
        if ( $admin_id > 0 ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_author = %d WHERE post_author IN ({$csv})", $admin_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
        }

        $this->logger->info( "[purge] Bulk-deleted " . count( $ids_int ) . " customers via SQL." );
        return count( $ids_int );
    }

    // ── Orders ────────────────────────────────────────────────────────────────

    private function purgeOrders( bool $force = false ): int {
        global $wpdb;

        $this->clearIdMapEntity( 'order' );

        if ( $force ) {
            // Use direct SQL for speed — loading full WC_Order objects for bulk
            // deletion is extremely slow and memory-intensive on large stores.
            $count = 0;

            // ── HPOS path (WooCommerce 7+ High-Performance Order Storage) ──
            $hpos_table = $wpdb->prefix . 'wc_orders';
            $has_hpos   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) );

            if ( $has_hpos ) {
                $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                if ( $count > 0 ) {
                    // Delete order item meta → order items → order meta → orders.
                    $wpdb->query( "DELETE oim FROM {$wpdb->prefix}woocommerce_order_itemmeta oim JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id WHERE o.type = 'shop_order'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    $wpdb->query( "DELETE oi FROM {$wpdb->prefix}woocommerce_order_items oi JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id WHERE o.type = 'shop_order'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    $wpdb->query( "DELETE om FROM {$wpdb->prefix}wc_orders_meta om JOIN {$wpdb->prefix}wc_orders o ON o.id = om.order_id WHERE o.type = 'shop_order'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    $wpdb->query( "DELETE FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    $this->logger->info( "[purge] HPOS: bulk-deleted {$count} orders via SQL." );
                }
            }

            // ── Legacy path (post-based orders in wp_posts) ──
            // Also runs after HPOS in case some orders were not yet migrated.
            $legacy_ids = array_map( 'intval', (array) $wpdb->get_col(
                "SELECT ID FROM {$wpdb->posts}
                  WHERE post_type = 'shop_order'
                    AND post_status != 'auto-draft'"
            ) );
            if ( ! empty( $legacy_ids ) ) {
                $csv = implode( ',', $legacy_ids );
                $wpdb->query( "DELETE FROM {$wpdb->postmeta}                          WHERE post_id      IN ({$csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL
                $wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_items     WHERE order_id     IN ({$csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL
                $wpdb->query( "DELETE oim FROM {$wpdb->prefix}woocommerce_order_itemmeta oim LEFT JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id WHERE oi.order_item_id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL
                $wpdb->query( "DELETE FROM {$wpdb->posts}                             WHERE ID           IN ({$csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL
                if ( ! $has_hpos ) {
                    $count = count( $legacy_ids );
                    $this->logger->info( "[purge] Legacy: bulk-deleted {$count} orders via SQL." );
                }
            }

            return $count;
        }

        // ── Tagged (non-force) path — filter by _octowoo_oc_order_id via direct SQL. ──
        $deleted = 0;

        // ── HPOS tagged path ──
        $hpos_table = $wpdb->prefix . 'wc_orders';
        $hpos_meta  = $wpdb->prefix . 'wc_orders_meta';
        $has_hpos   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) );

        if ( $has_hpos ) {
            $hpos_ids = $wpdb->get_col(
                "SELECT DISTINCT o.id
                   FROM {$wpdb->prefix}wc_orders o
                   JOIN {$wpdb->prefix}wc_orders_meta om ON om.order_id = o.id
                  WHERE o.type = 'shop_order'
                    AND om.meta_key = '_octowoo_oc_order_id'"
            );
            if ( ! empty( $hpos_ids ) ) {
                $csv_h = implode( ',', array_map( 'intval', $hpos_ids ) );
                $wpdb->query( "DELETE oim FROM {$wpdb->prefix}woocommerce_order_itemmeta oim JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id WHERE oi.order_id IN ({$csv_h})" ); // phpcs:ignore WordPress.DB.PreparedSQL
                $wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id IN ({$csv_h})" ); // phpcs:ignore WordPress.DB.PreparedSQL
                $wpdb->query( "DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE order_id IN ({$csv_h})" ); // phpcs:ignore WordPress.DB.PreparedSQL
                $wpdb->query( "DELETE FROM {$wpdb->prefix}wc_orders WHERE id IN ({$csv_h})" ); // phpcs:ignore WordPress.DB.PreparedSQL
                $deleted += count( $hpos_ids );
                $this->logger->info( "[purge] HPOS tagged: deleted " . count( $hpos_ids ) . " orders via SQL." );
            }
        }

        // ── Legacy post-based tagged path ──
        $legacy_ids = $wpdb->get_col(
            "SELECT DISTINCT p.ID
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
              WHERE pm.meta_key = '_octowoo_oc_order_id'
                AND p.post_type = 'shop_order'"
        );
        if ( ! empty( $legacy_ids ) ) {
            $csv_l = implode( ',', array_map( 'intval', $legacy_ids ) );
            $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$csv_l})" ); // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query( "DELETE oim FROM {$wpdb->prefix}woocommerce_order_itemmeta oim LEFT JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id WHERE oi.order_item_id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id IN ({$csv_l})" ); // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$csv_l})" ); // phpcs:ignore WordPress.DB.PreparedSQL
            $deleted += count( $legacy_ids );
            $this->logger->info( "[purge] Legacy tagged: deleted " . count( $legacy_ids ) . " orders via SQL." );
        }

        return $deleted;
    }

    // ── Coupons ───────────────────────────────────────────────────────────────

    private function purgeCoupons( bool $force = false ): int {
        global $wpdb;

        $this->clearIdMapEntity( 'coupon' );
        $this->clearIdMapEntity( 'coupon_code' );

        if ( $force ) {
            $count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                  WHERE post_type = 'shop_coupon' AND post_status != 'auto-draft'"
            );
            if ( $count > 0 ) {
                // Use subquery so we don't have to materialise the ID list.
                $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL
                    "DELETE pm FROM {$wpdb->postmeta} pm
                      JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE p.post_type = 'shop_coupon' AND p.post_status != 'auto-draft'"
                );
                $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_status != 'auto-draft'" ); // phpcs:ignore WordPress.DB.PreparedSQL
            }
            return $count;
        }

        $ids = $wpdb->get_col(
            "SELECT DISTINCT p.ID
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
              WHERE pm.meta_key   = '_octowoo_oc_id'
                AND p.post_type   = 'shop_coupon'"
        );

        if ( empty( $ids ) ) {
            return 0;
        }

        $csv = implode( ',', array_map( 'intval', $ids ) );
        $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query( "DELETE FROM {$wpdb->posts}    WHERE ID      IN ({$csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL

        return count( $ids );
    }

    // ── Reviews ───────────────────────────────────────────────────────────────

    private function purgeReviews( bool $force = false ): int {
        global $wpdb;

        $this->clearIdMapEntity( 'review' );

        if ( $force ) {
            // Delete all comments of type 'review' on product posts.
            $comment_ids = $wpdb->get_col(
                "SELECT DISTINCT c.comment_ID
                   FROM {$wpdb->comments} c
                   JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
                  WHERE c.comment_type = 'review'
                    AND p.post_type    = 'product'"
            );
        } else {
            $comment_ids = $wpdb->get_col(
                "SELECT DISTINCT comment_id FROM {$wpdb->commentmeta}
                  WHERE meta_key = '_octowoo_oc_id'"
            );
        }

        if ( empty( $comment_ids ) ) {
            return 0;
        }

        $csv = implode( ',', array_map( 'intval', $comment_ids ) );
        $wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ({$csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query( "DELETE FROM {$wpdb->comments}    WHERE comment_ID IN ({$csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL

        $this->logger->info( "[purge] Bulk-deleted " . count( $comment_ids ) . " reviews via SQL." );
        return count( $comment_ids );
    }

    // ── Information pages ─────────────────────────────────────────────────────

    private function purgeInformation( bool $force = false ): int {
        global $wpdb;

        $this->clearIdMapEntity( 'information' );

        // Both tagged and force modes only delete OctoWoo-created pages.
        // Manually-created pages (theme templates, custom pages) are NEVER touched.
        $ids = $wpdb->get_col(
            "SELECT DISTINCT p.ID
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
              WHERE pm.meta_key IN ('_octowoo_oc_id', '_octowoo_translation_of')
                AND p.post_type = 'page'"
        );

        if ( empty( $ids ) ) {
            return 0;
        }

        $csv = implode( ',', array_map( 'intval', $ids ) );
        $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query( "DELETE FROM {$wpdb->posts}    WHERE ID      IN ({$csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL

        $this->logger->info( "[purge] Bulk-deleted " . count( $ids ) . " information pages via SQL." );
        return count( $ids );
    }

    // ── Downloads ─────────────────────────────────────────────────────────────

    private function purgeDownloads( bool $force = false ): int {
        // OctoWoo stores downloads as WP posts with post_type 'octowoo_download'
        // or attaches them to products. Just clear any post type that carries the meta.
        global $wpdb;

        $this->clearIdMapEntity( 'download' );

        if ( $force ) {
            $ids = $wpdb->get_col(
                "SELECT DISTINCT ID FROM {$wpdb->posts}
                  WHERE post_type NOT IN ('product', 'product_variation', 'shop_order', 'shop_coupon', 'page', 'post', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'wp_global_styles', 'wp_template', 'wp_template_part', 'wp_navigation')
                    AND post_status != 'auto-draft'"
            );
        } else {
            $ids = $wpdb->get_col(
                "SELECT DISTINCT p.ID
                   FROM {$wpdb->posts} p
                   JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                  WHERE pm.meta_key = '_octowoo_oc_id'
                    AND p.post_type NOT IN ('product', 'product_variation', 'shop_order', 'shop_coupon', 'page')"
            );
        }

        $deleted = 0;
        foreach ( array_map( 'intval', $ids ) as $id ) {
            if ( wp_delete_post( $id, true ) ) {
                $deleted++;
            }
        }

        return $deleted;
    }

    // ── Meta repair ───────────────────────────────────────────────────────────

    /**
     * Return how many WC items of the given entity type exist in total,
     * and how many of those carry the _octowoo_oc_id tag.
     *
     * Used to generate a helpful "use Force Purge" hint when tagged purge finds 0.
     *
     * @return array{total: int, tagged: int}
     */
    private function countEntityItems( string $entity ): array {
        global $wpdb;

        switch ( $entity ) {
            case 'categories':
                $total  = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(DISTINCT tt.term_id) FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy = %s",
                    'product_cat'
                ) );
                $tagged = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(DISTINCT tm.term_id) FROM {$wpdb->termmeta} tm
                     JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                     WHERE tm.meta_key = '_octowoo_oc_id' AND tt.taxonomy = %s",
                    'product_cat'
                ) );
                return [ 'total' => $total, 'tagged' => $tagged ];

            case 'tags':
                $total  = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(DISTINCT tt.term_id) FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy = %s",
                    'product_tag'
                ) );
                $tagged = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(DISTINCT tm.term_id) FROM {$wpdb->termmeta} tm
                     JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                     WHERE tm.meta_key = '_octowoo_oc_id' AND tt.taxonomy = %s",
                    'product_tag'
                ) );
                return [ 'total' => $total, 'tagged' => $tagged ];

            case 'products':
                $total  = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status != 'auto-draft'"
                );
                $tagged = (int) $wpdb->get_var(
                    "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                     JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                     WHERE pm.meta_key = '_octowoo_oc_id' AND p.post_type = 'product'"
                );
                return [ 'total' => $total, 'tagged' => $tagged ];

            case 'coupons':
                $total  = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_status != 'auto-draft'"
                );
                $tagged = (int) $wpdb->get_var(
                    "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                     JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                     WHERE pm.meta_key = '_octowoo_oc_id' AND p.post_type = 'shop_coupon'"
                );
                return [ 'total' => $total, 'tagged' => $tagged ];

            case 'orders':
                // Just count all shop_orders; exact tagged count requires meta_query (slow).
                $total = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status != 'auto-draft'"
                );
                return [ 'total' => $total, 'tagged' => 0 ];

            case 'customers':
                $total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
                $tagged = (int) $wpdb->get_var(
                    "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = '_octowoo_oc_id'"
                );
                return [ 'total' => $total, 'tagged' => $tagged ];

            default:
                return [ 'total' => 0, 'tagged' => 0 ];
        }
    }

    /**
     * Backfill missing _octowoo_oc_id meta from the id_map table.
     *
     * Walks every row in octowoo_id_map and applies update_term_meta /
     * update_post_meta to the corresponding WC entity if the tag is absent.
     * This is idempotent (update_*_meta does nothing when the value already
     * matches) and fast (SELECT before each update is skipped because
     * update_term_meta / update_post_meta handles the upsert internally).
     *
     * Covers:
     *  - Categories/terms whose addTermMeta() was skipped by the old
     *    pre-v2.4.5 term_exists slug-lookup bug.
     *  - Any post-type entity whose meta was lost due to a partial run.
     */
    private function repairMetaFromIdMap(): void {
        global $wpdb;

        $map_table = $wpdb->prefix . 'octowoo_id_map';

        // Check the table exists — it may not on a brand-new install.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $map_table ) ) !== $map_table ) { // phpcs:ignore WordPress.DB.PreparedSQL
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT entity_type, oc_id, wc_id FROM `{$map_table}`", // phpcs:ignore WordPress.DB.PreparedSQL
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return;
        }

        // Entity types stored in the map as terms (taxonomy-based).
        $term_entities = [ 'category', 'manufacturer', 'tag', 'filter' ];

        $repaired = 0;
        foreach ( $rows as $row ) {
            $entity = (string) $row['entity_type'];
            $oc_id  = (int) $row['oc_id'];
            $wc_id  = (int) $row['wc_id'];

            if ( $wc_id <= 0 || $oc_id <= 0 ) {
                continue;
            }

            if ( in_array( $entity, $term_entities, true ) ) {
                // Term-based entity: ensure the term still exists.
                $term = get_term( $wc_id );
                if ( $term && ! is_wp_error( $term ) ) {
                    $existing = get_term_meta( $wc_id, '_octowoo_oc_id', true );
                    if ( (int) $existing !== $oc_id ) {
                        update_term_meta( $wc_id, '_octowoo_oc_id', $oc_id );
                        $repaired++;
                    }
                }
            } else {
                // Post-based entity: ensure the post still exists.
                if ( get_post( $wc_id ) ) {
                    $existing = get_post_meta( $wc_id, '_octowoo_oc_id', true );
                    if ( (int) $existing !== $oc_id ) {
                        update_post_meta( $wc_id, '_octowoo_oc_id', $oc_id );
                        $repaired++;
                    }
                }
            }
        }

        if ( $repaired > 0 ) {
            $this->logger->info( "[purge] Repaired _octowoo_oc_id meta on {$repaired} item(s) from id_map before purge." );
        }
    }
}
