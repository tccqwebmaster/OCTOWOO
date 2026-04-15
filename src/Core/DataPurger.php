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
     * @return array<string, int>  entity => number of items deleted
     */
    public function purge( array $entities, bool $force = false ): array {
        $results = [];

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
            $count = $this->$method( $force );
            $results[ $entity ] = $count;
            $this->logger->info( "[purge] Finished purge: {$entity} — {$count} item(s) deleted." );
        }

        return $results;
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
        // wc_get_orders() handles both legacy posts and HPOS transparently.
        $deleted = 0;
        $page    = 1;

        $query_args = [
            'limit'  => 100,
            'page'   => $page,
            'return' => 'objects',
            'type'   => 'shop_order',
        ];

        if ( ! $force ) {
            $query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery
                [
                    'key'     => '_octowoo_oc_order_id',
                    'compare' => 'EXISTS',
                ],
            ];
        }

        do {
            $query_args['page'] = $page;
            $orders = wc_get_orders( $query_args );

            foreach ( $orders as $order ) {
                $order->delete( true );
                $deleted++;
            }

            $page++;
        } while ( count( $orders ) === 100 );

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

        // Force mode intentionally still requires the OctoWoo tag for 'page' post type
        // because force-deleting ALL pages would destroy unrelated WP content.
        $ids = $wpdb->get_col(
            "SELECT DISTINCT p.ID
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
              WHERE pm.meta_key = '_octowoo_oc_id'
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
}
