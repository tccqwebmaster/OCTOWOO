<?php
/**
 * Product-bundle migrator.
 *
 * Imports OpenCart 4.x product bundles into WooCommerce via the
 * "WooCommerce Product Bundles" plugin by SomewhereWarm.
 *
 * REQUIREMENTS:
 *  1. WooCommerce Product Bundles plugin must be active.
 *     Detection: class_exists('WC_Bundles')
 *  2. OpenCart store must use OC 4.x (oc_product_bundle / oc_product_bundle_product tables).
 *     Graceful no-op if the table doesn't exist.
 *
 * OpenCart 4.x tables read:
 *   oc_product_bundle          (product_bundle_id, product_id, name, quantity, status)
 *   oc_product_bundle_product  (product_bundle_product_id, product_bundle_id,
 *                                product_id, default_quantity, auto_add)
 *
 * What is created in WooCommerce:
 *   - Each bundle definition in OC becomes a WooCommerce "bundle" type product.
 *   - Bundled items (_wc_pb_bundled_items post-meta) are written directly so the
 *     plugin can work with them on first serve.
 *   - The mapping is stored in checkpoint under the 'bundles' key.
 *
 * If the required plugin or the OC table is missing the migrator logs a warning
 * and exits cleanly without failing.
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class BundleMigrator extends AbstractMigrator {

    private const KEY = 'bundles';

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        // Guard: require WooCommerce Product Bundles plugin.
        if ( ! class_exists( 'WC_Bundles' ) ) {
            $this->logger->warning(
                '[bundles] WooCommerce Product Bundles plugin not active – skipping bundle migration. '
                . 'Install and activate "WooCommerce Product Bundles" by SomewhereWarm to enable this feature.'
            );
            $this->checkpoint->init( self::KEY, 0 );
            $this->checkpoint->start( self::KEY );
            $this->checkpoint->complete( self::KEY );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        // Guard: require OC 4.x bundle tables.
        if ( ! $this->ocBundleTableExists() ) {
            $this->logger->warning(
                '[bundles] oc_product_bundle table not found – this OpenCart installation may not support product bundles (requires OC 4.x). Skipping.'
            );
            $this->checkpoint->init( self::KEY, 0 );
            $this->checkpoint->start( self::KEY );
            $this->checkpoint->complete( self::KEY );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        $pfx = $this->pfx();

        $total_callback = fn() => (int) $this->oc->count( 'product_bundle' );

        $batch_callback = fn( int $offset, int $limit ) => $this->oc->fetchBatch(
            "SELECT pb.product_bundle_id, pb.product_id AS parent_product_id,
                    pb.name, pb.quantity AS bundle_qty, pb.status
             FROM `{$pfx}product_bundle` pb
             WHERE pb.status = 1
             ORDER BY pb.product_bundle_id ASC",
            [],
            $limit,
            $offset
        );

        // Pre-fetch all bundle items to avoid N+1 queries.
        $bundle_items = $this->fetchBundleItems();

        $item_callback = fn( array $row ) => $this->processBundle( $row, $bundle_items );

        $resume_id = $this->checkpoint->getLastId( self::KEY );

        if ( $resume_id === PHP_INT_MAX ) {
            $this->logger->info( '[bundles] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        return $this->batch->run(
            total_callback:  $total_callback,
            batch_callback:  $batch_callback,
            item_callback:   $item_callback,
            migrator:        self::KEY,
            checkpoint:      $this->checkpoint,
            resume_after_id: $resume_id,
            id_field:        'product_bundle_id'
        );
    }

    // ── Per-bundle processing ─────────────────────────────────────────────────

    private function processBundle( array $row, array $bundle_items ): bool {
        $oc_bundle_id = (int) $row['product_bundle_id'];

        // Duplicate check.
        if ( $this->checkpoint->getWcId( self::KEY, $oc_bundle_id ) ) {
            return false; // Already imported.
        }

        if ( $this->isDry() ) {
            $this->logger->debug( "[DRY-RUN][bundles] Would create bundle: '{$row['name']}' (OC #$oc_bundle_id)." );
            return true;
        }

        $name  = $this->sanitizeText( $row['name'] ?? 'Bundle ' . $oc_bundle_id );
        $items = $bundle_items[ $oc_bundle_id ] ?? [];

        if ( empty( $items ) ) {
            $this->logger->warning( "[bundles] OC bundle #{$oc_bundle_id} '{$name}' has no bundled items – skipping." );
            return false;
        }

        // Create the WP post typed as 'bundle'.
        $post_id = wp_insert_post( [
            'post_title'  => $name,
            'post_status' => 'publish',
            'post_type'   => 'product',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            $this->logger->error( "[bundles] wp_insert_post failed for OC bundle #{$oc_bundle_id}: " . $post_id->get_error_message() );
            return false;
        }

        // Set product type to 'bundle'.
        wp_set_object_terms( $post_id, 'bundle', 'product_type' );
        update_post_meta( $post_id, '_octowoo_oc_id', $oc_bundle_id );
        update_post_meta( $post_id, '_octowoo_bundle_parent_oc_id', (int) $row['parent_product_id'] );

        // Write bundled items meta.
        $bundled_items_meta = [];
        $menu_order         = 0;

        foreach ( $items as $item ) {
            $oc_product_id = (int) $item['product_id'];
            $wc_product_id = $this->checkpoint->getWcId( 'product', $oc_product_id );

            if ( ! $wc_product_id ) {
                $this->logger->warning(
                    "[bundles] Bundle #{$oc_bundle_id}: bundled OC product #{$oc_product_id} not found in WC – skipped."
                );
                continue;
            }

            $bundled_item_id = $oc_bundle_id . '_' . $oc_product_id;

            $bundled_items_meta[ $bundled_item_id ] = [
                'product_id'            => (int) $wc_product_id,
                'menu_order'            => $menu_order++,
                'quantity_min'          => max( 1, (int) $item['default_quantity'] ),
                'quantity_max'          => max( 1, (int) $item['default_quantity'] ),
                'quantity_default'      => max( 1, (int) $item['default_quantity'] ),
                'priced_individually'   => 'no',
                'shipped_individually'  => 'no',
                'override_title'        => 'no',
                'override_description'  => 'no',
                'optional'              => 'no',
                'auto_add'              => (int) $item['auto_add'] ? 'yes' : 'no',
                'discount'              => 0,
            ];
        }

        if ( empty( $bundled_items_meta ) ) {
            // All bundled products were unresolved; delete the post we created.
            wp_delete_post( $post_id, true );
            $this->logger->warning( "[bundles] Bundle #{$oc_bundle_id}: no resolvable bundled items – removed placeholder post." );
            return false;
        }

        update_post_meta( $post_id, '_wc_pb_bundled_items', $bundled_items_meta );
        update_post_meta( $post_id, '_wc_pb_layout_style', 'default' );
        update_post_meta( $post_id, '_wc_pb_add_to_cart_form_location', 'default' );
        update_post_meta( $post_id, '_manage_stock', 'no' );
        update_post_meta( $post_id, '_price', 0 );
        update_post_meta( $post_id, '_regular_price', 0 );

        $this->checkpoint->saveIdMap( self::KEY, $oc_bundle_id, $post_id );
        $this->logger->info( "[bundles] Created WC bundle #{$post_id} '{$name}' (OC #{$oc_bundle_id}, {$menu_order} items)." );

        return true;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Check whether the oc_product_bundle table exists in the OC database.
     */
    private function ocBundleTableExists(): bool {
        $pfx  = $this->pfx();
        $rows = $this->oc->fetchAll(
            "SHOW TABLES LIKE '{$pfx}product_bundle'"
        );
        return ! empty( $rows );
    }

    /**
     * Pre-fetch all bundle items indexed by bundle ID.
     *
     * @return array<int, array<int, array<string,mixed>>>
     */
    private function fetchBundleItems(): array {
        $pfx  = $this->pfx();
        $rows = $this->oc->fetchAll(
            "SELECT product_bundle_id, product_id, default_quantity, auto_add
             FROM `{$pfx}product_bundle_product`
             ORDER BY product_bundle_id ASC, product_bundle_product_id ASC"
        );

        $indexed = [];
        foreach ( $rows as $row ) {
            $indexed[ (int) $row['product_bundle_id'] ][] = $row;
        }
        return $indexed;
    }
}
