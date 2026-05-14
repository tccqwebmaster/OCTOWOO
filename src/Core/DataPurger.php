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
        $this->resetAutoIncrements( $force );

        return [ 'results' => $results, 'diagnostics' => $diagnostics ];
    }

    /**
     * Reset AUTO_INCREMENT counters on the core WordPress tables.
     *
     * Only resets counters when the table is EMPTY — MySQL silently ignores
     * AUTO_INCREMENT = 1 on non-empty tables (choosing MAX+1 anyway), so this
     * is a no-op if rows remain. We skip non-empty tables to be explicit and
     * avoid any confusion in the log output.
     *
     * @param  bool $force  Only runs in force mode (when everything was wiped).
     */
    private function resetAutoIncrements( bool $force = false ): void {
        if ( ! $force ) {
            // Tagged purge leaves other data intact — resetting AUTO_INCREMENT
            // could cause new rows to collide with surviving records.
            return;
        }

        global $wpdb;

        $table_count_map = [
            $wpdb->posts        => 'SELECT COUNT(*) FROM ' . $wpdb->posts,
            $wpdb->terms        => 'SELECT COUNT(*) FROM ' . $wpdb->terms,
            $wpdb->term_taxonomy => 'SELECT COUNT(*) FROM ' . $wpdb->term_taxonomy,
            $wpdb->comments     => 'SELECT COUNT(*) FROM ' . $wpdb->comments,
            $wpdb->users        => 'SELECT COUNT(*) FROM ' . $wpdb->users,
            $wpdb->usermeta     => 'SELECT COUNT(*) FROM ' . $wpdb->usermeta,
        ];

        $reset = 0;
        foreach ( $table_count_map as $table => $count_sql ) {
            $count = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL
            if ( $count === 0 ) {
                $wpdb->query( "ALTER TABLE `{$table}` AUTO_INCREMENT = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL
                $reset++;
            }
        }

        if ( $reset > 0 ) {
            $this->logger->info( "[purge] AUTO_INCREMENT counters reset on {$reset} empty table(s)." );
        }
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

            // Delete attachments that were uploaded directly to a product post.
            // SAFETY: Only delete an attachment if post_parent points to one of the
            // products we're deleting AND the attachment is NOT referenced by any other
            // post (page, Porto builder, Elementor template, etc.) via postmeta.
            // This prevents deleting shared media library images used in theme designs.
            $attachment_ids = array_map( 'intval', (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL
                "SELECT ID FROM {$wpdb->posts}
                  WHERE post_type   = 'attachment'
                    AND post_parent IN ({$csv})
                    AND ID NOT IN (
                        -- Exclude attachments referenced by posts OUTSIDE the product set.
                        SELECT DISTINCT CAST(pm.meta_value AS UNSIGNED)
                          FROM {$wpdb->postmeta} pm
                          JOIN {$wpdb->posts}    pr ON pr.ID = pm.post_id
                         WHERE pm.meta_value REGEXP '^[0-9]+$'
                           AND pr.ID NOT IN ({$csv})
                           AND pr.post_type != 'revision'
                           AND pr.post_status != 'auto-draft'
                    )"
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
        // Clear all category transient formats (matches AjaxHandler::actionResetMigration).
        $oc_pfx = $this->config['db']['prefix'] ?? $this->config['oc_db']['prefix'] ?? 'oc_';
        delete_transient( 'octowoo_cat_topo_' . md5( $oc_pfx ) );
        delete_transient( 'octowoo_cat_all_'  . md5( $oc_pfx ) );
        delete_transient( 'octowoo_cat_all_'  . md5( $oc_pfx . '_fresh' ) );
        delete_transient( 'octowoo_cat_all_'  . md5( $oc_pfx . '_resume' ) );
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

    // ── Orphan translation cleanup ─────────────────────────────────────────────

    /**
     * Delete orphaned / duplicate translation terms created by WpmlIntegration
     * during failed or retried runs.  Handles two cases:
     *
     *  1. Placeholder terms — name matches 'octowoo-ar-new-%'.  These were
     *     created when two languages share the same term name (e.g. "Apple")
     *     and an older code path left the placeholder name instead of renaming
     *     it to the real Arabic text.
     *
     *  2. Duplicate Arabic terms — multiple terms share the same WPML trid
     *     (created when a category chunk was processed more than once due to a
     *     server timeout / retry).  Keeps the lowest term_id, deletes extras.
     *
     * @param  string[] $taxonomies  Defaults to product_cat + detected brand taxons.
     * @return int  Total terms deleted.
     */
    public function purgeOrphanTranslationTerms( array $taxonomies = [] ): int {
        global $wpdb;

        if ( empty( $taxonomies ) ) {
            $taxonomies = [ 'product_cat' ];
            foreach ( [ 'product_brand', 'pwb-brand', 'pa_brand' ] as $t ) {
                if ( taxonomy_exists( $t ) ) {
                    $taxonomies[] = $t;
                }
            }
        }

        $deleted = 0;

        foreach ( $taxonomies as $taxonomy ) {

            // 1. Placeholder terms (name LIKE 'octowoo-ar-new-%').
            $placeholder_ids = array_map( 'intval', (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT t.term_id
                       FROM {$wpdb->terms} t
                       JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                      WHERE tt.taxonomy = %s
                        AND t.name LIKE 'octowoo-ar-new-%%'",
                    $taxonomy
                )
            ) );

            $deleted += $this->forceDeleteTermIds( $placeholder_ids, $taxonomy );

            // 2. Duplicate Arabic terms — multiple icl_translations rows for the
            //    same trid with the same secondary language.  Keep lowest term_id.
            $icl_table = $wpdb->prefix . 'icl_translations';
            $has_icl   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $icl_table ) );
            if ( ! $has_icl ) {
                continue;
            }

            $element_type = 'tax_' . $taxonomy;

            // Fetch all secondary-language term rows that belong to a trid with >1 such row.
            $dup_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT i.trid, tt.term_id
                   FROM `{$icl_table}` i
                   JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = i.element_id
                  WHERE i.element_type  = %s
                    AND i.language_code != 'en'
                    AND i.trid IN (
                        SELECT i2.trid
                          FROM `{$icl_table}` i2
                         WHERE i2.element_type  = %s
                           AND i2.language_code != 'en'
                         GROUP BY i2.trid
                        HAVING COUNT(*) > 1
                    )
                  ORDER BY i.trid ASC, tt.term_id ASC",
                $element_type,
                $element_type
            ) );

            // Group by trid, keep first (lowest term_id), delete the rest.
            $trid_groups = [];
            foreach ( $dup_rows as $row ) {
                $trid_groups[ $row->trid ][] = (int) $row->term_id;
            }

            $to_delete = [];
            foreach ( $trid_groups as $term_ids ) {
                array_shift( $term_ids ); // keep lowest term_id
                $to_delete = array_merge( $to_delete, $term_ids );
            }

            $deleted += $this->forceDeleteTermIds( array_unique( $to_delete ), $taxonomy );
        }

        return $deleted;
    }

    /**
     * Hard-delete term IDs: removes from wp_terms, wp_term_taxonomy,
     * wp_term_relationships, wp_termmeta, and WPML icl_translations.
     *
     * @param  int[]   $term_ids
     * @param  string  $taxonomy
     * @return int     Number of terms deleted.
     */
    private function forceDeleteTermIds( array $term_ids, string $taxonomy ): int {
        if ( empty( $term_ids ) ) {
            return 0;
        }

        global $wpdb;

        $id_csv = implode( ',', array_map( 'intval', $term_ids ) );

        // Get the term_taxonomy_ids for these term_ids in this taxonomy.
        $tt_ids = array_map( 'intval', (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}
              WHERE term_id IN ({$id_csv}) AND taxonomy = '{$taxonomy}'"
        ) );

        if ( ! empty( $tt_ids ) ) {
            $tt_csv = implode( ',', $tt_ids );
            // Remove product → term assignments.
            $wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$tt_csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL
            // Remove taxonomy registration rows.
            $wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ({$tt_csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL

            // Remove WPML icl_translations rows.
            $icl_table = $wpdb->prefix . 'icl_translations';
            $has_icl   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $icl_table ) );
            if ( $has_icl ) {
                $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL
                    "DELETE FROM `{$icl_table}`
                      WHERE element_id   IN ({$tt_csv})
                        AND element_type  = 'tax_{$taxonomy}'"
                );
            }
        }

        // Remove all term meta.
        $wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE term_id IN ({$id_csv})" ); // phpcs:ignore WordPress.DB.PreparedSQL
        // Remove term rows (only those no longer referenced by any taxonomy).
        $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL
            "DELETE t FROM {$wpdb->terms} t
              LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.term_id IS NULL
               AND t.term_id IN ({$id_csv})"
        );

        // Flush WP object cache for affected terms.
        foreach ( $term_ids as $tid ) {
            clean_term_cache( $tid, $taxonomy );
        }
        clean_taxonomy_cache( $taxonomy );

        $this->logger->info( "[purge-orphan] Deleted " . count( $term_ids ) . " orphan term(s) from {$taxonomy}." );

        return count( $term_ids );
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

        // Comprehensive exclusion list: NEVER delete theme/builder/system post types.
        // This list is merged with any custom exclusions from the filter below.
        // Covers: WordPress core, WooCommerce, Porto, Divi, Elementor, Avada, Fusion,
        //         Beaver Builder, Bricks, WPBakery, Blocksy, Kadence, GeneratePress,
        //         and any other registered theme/builder post types.
        $protected_post_types = apply_filters( 'octowoo_purge_protected_post_types', [
            // ── WordPress core ──────────────────────────────────────────────────
            'post', 'page', 'attachment', 'revision', 'nav_menu_item',
            'custom_css', 'customize_changeset', 'wp_global_styles',
            'wp_template', 'wp_template_part', 'wp_navigation', 'wp_block',
            'wp_font_family', 'wp_font_face', 'oembed_cache',
            // ── WooCommerce ─────────────────────────────────────────────────────
            'product', 'product_variation', 'shop_order', 'shop_coupon',
            'shop_order_refund', 'wc_order', 'shop_webhook',
            // ── Porto theme ─────────────────────────────────────────────────────
            'porto_builder', 'porto_portfolio', 'porto_testimonial',
            'porto_slide', 'porto_shortcode',
            // ── Elementor ───────────────────────────────────────────────────────
            'elementor_library', 'e-landing-page', 'elementor-hf',
            'elementor_font', 'elementor_icons', 'elementor_snippet',
            // ── Divi / ET ───────────────────────────────────────────────────────
            'et_pb_layout', 'et_template', 'et_header_layout',
            'et_footer_layout', 'et_body_layout',
            // ── Avada / Fusion Builder ───────────────────────────────────────────
            'fusion_tb_section', 'fusion_tb_layout', 'fusion_element',
            'fusion_icons', 'fusion_template', 'fusion_form',
            // ── Beaver Builder ───────────────────────────────────────────────────
            'fl-builder-template', 'fl-theme-layout',
            // ── Bricks builder ───────────────────────────────────────────────────
            'bricks_template',
            // ── Oxygen Builder ───────────────────────────────────────────────────
            'ct_template',
            // ── WPBakery / JS Composer ───────────────────────────────────────────
            'vc_grid_item',
            // ── Kadence / Kadence Blocks ─────────────────────────────────────────
            'kadence_form', 'kadence_element', 'kadence_header', 'kadence_query',
            'kadence_wootemplate',
            // ── GenerateBlocks / GeneratePress ───────────────────────────────────
            'gblocks_templates',
            // ── Blocksy ─────────────────────────────────────────────────────────
            'ct_content_block',
            // ── Astra ───────────────────────────────────────────────────────────
            'astra-advanced-hook', 'astra-portfolio',
            // ── OceanWP / ExtendedOcean ──────────────────────────────────────────
            'oceanwp_library',
            // ── Hello/Elementor Kit ──────────────────────────────────────────────
            'elementor_font', 'kit',
            // ── WooCommerce blocks / FSE ─────────────────────────────────────────
            'wc_product_collection',
            // ── SiteOrigin ───────────────────────────────────────────────────────
            'siteorigin-css',
            // ── Toolset / CRED ───────────────────────────────────────────────────
            'cred-form', 'cred-user-form',
            // ── Popup plugins ────────────────────────────────────────────────────
            'popup', 'popups', 'spu-popups', 'ow-popup',
            // ── Header footer plugins ─────────────────────────────────────────────
            'hf-template', 'header_footer_shortcode',
        ] );

        if ( $force ) {
            // Build the NOT IN clause from the protected list.
            $protected_csv = "'" . implode( "','", array_map( 'esc_sql', $protected_post_types ) ) . "'";
            $ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL
                "SELECT DISTINCT ID FROM {$wpdb->posts}
                  WHERE post_type NOT IN ({$protected_csv})
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

    // ── Pre-purge safety audit ────────────────────────────────────────────────

    /**
     * Return a safety audit of what WOULD be deleted for the given entities.
     *
     * Called by the admin UI "Audit before purge" button so users can see
     * exact counts BEFORE they commit to deletion. Non-destructive.
     *
     * Returns array keyed by entity with:
     *   tagged_count  — items that carry _octowoo_oc_id (will be deleted in normal mode)
     *   total_count   — total WC items of this type (will be deleted in force mode)
     *   safe          — true when tagged_count matches total_count (force = same result)
     *   warnings      — array of human-readable safety warnings
     *
     * @param  string[] $entities
     * @param  bool     $force
     * @return array<string, array{tagged_count:int, total_count:int, safe:bool, warnings:string[]}>
     */
    public function audit( array $entities, bool $force = false ): array {
        global $wpdb;
        $report = [];

        foreach ( $entities as $entity ) {
            $tagged = 0;
            $total  = 0;
            $warnings = [];

            switch ( $entity ) {
                case 'products':
                    $total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status != 'auto-draft'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    $tagged = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE pm.meta_key = '_octowoo_oc_id' AND p.post_type = 'product'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    if ( $force && $total > $tagged ) {
                        $warnings[] = sprintf( __( '%d product(s) were NOT created by OctoWoo and will also be deleted in Force mode.', 'octowoo' ), $total - $tagged );
                    }
                    // Warn about shared media.
                    $shared_attachments = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL
                        "SELECT COUNT(*) FROM {$wpdb->posts} att
                         WHERE att.post_type = 'attachment'
                           AND att.post_parent IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product')
                           AND att.ID IN (SELECT DISTINCT CAST(meta_value AS UNSIGNED) FROM {$wpdb->postmeta} WHERE meta_value REGEXP '^[0-9]+$')"
                    );
                    if ( $force && $shared_attachments > 0 ) {
                        $warnings[] = sprintf( __( '%d product image(s) appear to be used elsewhere (pages, theme builder, etc.) and will be PROTECTED from deletion.', 'octowoo' ), $shared_attachments );
                    }
                    break;

                case 'categories':
                    $total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT term_id) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'product_cat' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
                    $tagged = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT tm.term_id) FROM {$wpdb->termmeta} tm JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id WHERE tm.meta_key = '_octowoo_oc_id' AND tt.taxonomy = %s", 'product_cat' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
                    if ( $force && $total > $tagged ) {
                        $warnings[] = sprintf( __( '%d category/categories were NOT created by OctoWoo and will also be deleted in Force mode.', 'octowoo' ), $total - $tagged );
                    }
                    break;

                case 'tags':
                    $total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT term_id) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'product_tag' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
                    $tagged = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT tm.term_id) FROM {$wpdb->termmeta} tm JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id WHERE tm.meta_key = '_octowoo_oc_id' AND tt.taxonomy = %s", 'product_tag' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
                    break;

                case 'customers':
                    $total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    $tagged = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = '_octowoo_oc_id'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    $warnings[] = __( 'Administrator accounts are always protected regardless of mode.', 'octowoo' );
                    break;

                case 'orders':
                    $hpos_table = $wpdb->prefix . 'wc_orders';
                    $has_hpos   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) );
                    if ( $has_hpos ) {
                        $total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$hpos_table} WHERE type = 'shop_order'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                        $tagged = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT o.id) FROM {$hpos_table} o JOIN {$wpdb->prefix}wc_orders_meta om ON om.order_id = o.id WHERE o.type = 'shop_order' AND om.meta_key = '_octowoo_oc_order_id'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    } else {
                        $total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status != 'auto-draft'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                        $tagged = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE pm.meta_key = '_octowoo_oc_order_id' AND p.post_type = 'shop_order'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    }
                    if ( $force && $total > $tagged ) {
                        $warnings[] = sprintf( __( '%d order(s) were NOT created by OctoWoo and will also be deleted in Force mode.', 'octowoo' ), $total - $tagged );
                    }
                    break;

                case 'coupons':
                    $total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_status != 'auto-draft'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    $tagged = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE pm.meta_key = '_octowoo_oc_id' AND p.post_type = 'shop_coupon'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    break;

                case 'reviews':
                    $total  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT c.comment_ID) FROM {$wpdb->comments} c JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID WHERE c.comment_type = 'review' AND p.post_type = 'product'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    $tagged = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT comment_id) FROM {$wpdb->commentmeta} WHERE meta_key = '_octowoo_oc_id'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    break;

                case 'information':
                    $total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status != 'auto-draft'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    $tagged = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE pm.meta_key IN ('_octowoo_oc_id','_octowoo_translation_of') AND p.post_type = 'page'" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    $warnings[] = __( 'Only pages tagged by OctoWoo are deleted. Theme header/footer templates and manually-built pages are ALWAYS protected.', 'octowoo' );
                    break;

                case 'downloads':
                    // Tagged path is always safe; force path uses protected post_type list.
                    $tagged = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE pm.meta_key = '_octowoo_oc_id' AND p.post_type NOT IN ('product','product_variation','shop_order','shop_coupon','page')" ); // phpcs:ignore WordPress.DB.PreparedSQL
                    $total  = $tagged; // Force is safe — protected list covers all themes.
                    $warnings[] = __( 'Theme and page builder post types (Porto, Elementor, Divi, Avada, etc.) are always excluded.', 'octowoo' );
                    break;

                case 'manufacturers':
                case 'filters':
                    $taxonomy = $entity === 'manufacturers' ? 'product_brand' : 'product_filter';
                    $total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT term_id) FROM {$wpdb->term_taxonomy} WHERE taxonomy LIKE %s", '%brand%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
                    $tagged = $total; // Always safe — taxonomy-scoped.
                    break;

                default:
                    continue 2;
            }

            $will_delete = $force ? $total : $tagged;
            $extra       = $force ? max( 0, $total - $tagged ) : 0;

            $report[ $entity ] = [
                'tagged_count' => $tagged,
                'total_count'  => $total,
                'will_delete'  => $will_delete,
                'extra_count'  => $extra,
                'safe'         => ( $tagged === $total ),
                'warnings'     => $warnings,
            ];
        }

        return $report;
    }

}
