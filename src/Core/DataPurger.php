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

    public function __construct( Logger $logger ) {
        $this->logger = $logger;
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

        return [ 'results' => $results, 'diagnostics' => $diagnostics ];
    }

    // ── Products ──────────────────────────────────────────────────────────────

    private function purgeProducts( bool $force = false ): int {
        global $wpdb;

        if ( $force ) {
            // Bulk SQL path — much faster than calling wp_delete_post() one by one
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
        return $this->purgeTermsByTaxonomy( 'product_cat', $force );
    }

    private function purgeTags( bool $force = false ): int {
        return $this->purgeTermsByTaxonomy( 'product_tag', $force );
    }

    private function purgeManufacturers( bool $force = false ): int {
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
        $deleted = 0;
        foreach ( [ 'product_filter', 'pa_filter' ] as $tax ) {
            if ( taxonomy_exists( $tax ) ) {
                $deleted += $this->purgeTermsByTaxonomy( $tax, $force );
            }
        }
        return $deleted;
    }

    private function purgeTermsByTaxonomy( string $taxonomy, bool $force = false ): int {
        global $wpdb;

        if ( $force ) {
            $term_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT tt.term_id
                   FROM {$wpdb->term_taxonomy} tt
                  WHERE tt.taxonomy = %s",
                $taxonomy
            ) );
        } else {
            $term_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT tm.term_id
                   FROM {$wpdb->termmeta} tm
                   JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                  WHERE tm.meta_key = '_octowoo_oc_id'
                    AND tt.taxonomy   = %s",
                $taxonomy
            ) );
        }

        $deleted = 0;
        foreach ( array_map( 'intval', $term_ids ) as $term_id ) {
            $result = wp_delete_term( $term_id, $taxonomy );
            if ( $result && ! is_wp_error( $result ) ) {
                $deleted++;
            }
        }

        return $deleted;
    }

    // ── Customers ─────────────────────────────────────────────────────────────

    private function purgeCustomers( bool $force = false ): int {
        global $wpdb;

        // Force mode still requires the OctoWoo tag for customers – deleting
        // all WP users without filtering is too destructive.
        $user_ids = $wpdb->get_col(
            "SELECT user_id FROM {$wpdb->usermeta}
              WHERE meta_key = '_octowoo_oc_id'"
        );

        if ( ! function_exists( 'wp_delete_user' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        $deleted = 0;
        foreach ( array_map( 'intval', $user_ids ) as $uid ) {
            // Never delete administrators — safety guard.
            if ( user_can( $uid, 'manage_options' ) ) {
                $this->logger->warning( "[purge] Skipping user #{$uid} (admin)." );
                continue;
            }
            if ( wp_delete_user( $uid ) ) {
                $deleted++;
            }
        }

        return $deleted;
    }

    // ── Orders ────────────────────────────────────────────────────────────────

    private function purgeOrders( bool $force = false ): int {
        global $wpdb;

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

        // ── Tagged (non-force) path — use wc_get_orders() for HPOS compat. ──
        $deleted = 0;
        $page    = 1;

        do {
            $orders = wc_get_orders( [
                'limit'      => 50,
                'page'       => $page,
                'return'     => 'objects',
                'type'       => 'shop_order',
                'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery
                    [
                        'key'     => '_octowoo_oc_order_id',
                        'compare' => 'EXISTS',
                    ],
                ],
            ] );

            foreach ( $orders as $order ) {
                $order->delete( true );
                $deleted++;
            }

            $page++;
        } while ( count( $orders ) === 50 );

        return $deleted;
    }

    // ── Coupons ───────────────────────────────────────────────────────────────

    private function purgeCoupons( bool $force = false ): int {
        global $wpdb;

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

        $deleted = 0;
        foreach ( array_map( 'intval', $ids ) as $id ) {
            if ( wp_delete_post( $id, true ) ) {
                $deleted++;
            }
        }

        return $deleted;
    }

    // ── Reviews ───────────────────────────────────────────────────────────────

    private function purgeReviews( bool $force = false ): int {
        global $wpdb;

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

        $deleted = 0;
        foreach ( array_map( 'intval', $comment_ids ) as $cid ) {
            if ( wp_delete_comment( $cid, true ) ) {
                $deleted++;
            }
        }

        return $deleted;
    }

    // ── Information pages ─────────────────────────────────────────────────────

    private function purgeInformation( bool $force = false ): int {
        global $wpdb;

        // Both tagged and force modes only delete pages that OctoWoo created:
        //   - pages with _octowoo_oc_id  (directly migrated from OpenCart)
        //   - pages with _octowoo_translation_of  (WPML/Polylang translated copies)
        // Manually-created pages (header templates, custom pages, etc.) are NEVER touched.
        $ids = $wpdb->get_col(
            "SELECT DISTINCT p.ID
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
              WHERE pm.meta_key IN ('_octowoo_oc_id', '_octowoo_translation_of')
                AND p.post_type = 'page'"
        );

        $deleted = 0;
        foreach ( array_map( 'intval', $ids ) as $id ) {
            if ( wp_delete_post( $id, true ) ) {
                $deleted++;
            }
        }

        return $deleted;
    }

    // ── Downloads ─────────────────────────────────────────────────────────────

    private function purgeDownloads( bool $force = false ): int {
        // OctoWoo stores downloads as WP posts with post_type 'octowoo_download'
        // or attaches them to products. Just clear any post type that carries the meta.
        global $wpdb;

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
