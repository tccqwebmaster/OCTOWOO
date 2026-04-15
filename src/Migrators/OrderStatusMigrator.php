<?php
/**
 * Order-status migrator.
 *
 * Reads the full list of order statuses from oc_order_status and builds a
 * dynamic OC status ID → WC status slug mapping stored in both:
 *   - WP option 'octowoo_order_status_map' — consumed by AbstractMigrator::mapOrderStatus()
 *   - WC custom post statuses — any OC status that doesn't map to a built-in
 *     WC status is registered as a custom WC status with the prefix 'wc-oc-'.
 *
 * Built-in OC statuses (IDs 1,2,3,5,7,8,9,10,11,15) are always mapped to WC
 * equivalents.  Custom store-specific statuses (e.g. "Awaiting Payment",
 * "In Production", etc.) become first-class WC order statuses so order
 * history is preserved accurately.
 *
 * OpenCart tables read:
 *   oc_order_status (order_status_id, language_id, name)
 *
 * Should run BEFORE orders so the map is available when orders are imported.
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class OrderStatusMigrator extends AbstractMigrator {

    private const KEY = 'order_statuses';

    /**
     * Hardcoded mappings for OpenCart's default status IDs.
     * IDs not in this list that also have no prior mapping will become
     * custom WC statuses registered as 'wc-oc-{slug}'.
     */
    private const DEFAULT_MAP = [
        1  => 'pending',
        2  => 'processing',
        3  => 'on-hold',
        5  => 'completed',
        7  => 'cancelled',
        8  => 'failed',
        9  => 'refunded',
        10 => 'refunded',
        11 => 'cancelled',
        15 => 'failed',
    ];

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        $pfx     = $this->pfx();
        $lang_id = $this->langId();

        // Fetch order statuses for the primary language.
        $rows = $this->oc->fetchAll(
            "SELECT order_status_id, name
             FROM `{$pfx}order_status`
             WHERE language_id = {$lang_id}
             ORDER BY order_status_id ASC"
        );

        // If language has no rows at all, fall back to any language.
        if ( empty( $rows ) ) {
            $rows = $this->oc->fetchAll(
                "SELECT order_status_id, name
                 FROM `{$pfx}order_status`
                 GROUP BY order_status_id
                 ORDER BY order_status_id ASC"
            );
        }

        $total = count( $rows );

        $this->checkpoint->init( self::KEY, $total );
        $this->checkpoint->start( self::KEY );

        if ( $total === 0 ) {
            $this->logger->info( '[order_statuses] No order statuses found in oc_order_status – skipping.' );
            $this->checkpoint->complete( self::KEY );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        // Load any custom statuses already registered by a previous run.
        $existing_map = get_option( 'octowoo_order_status_map', [] );
        $map          = self::DEFAULT_MAP; // Start from built-in defaults.

        // Overlay with any previously saved custom entries so resume works.
        foreach ( $existing_map as $id => $slug ) {
            $map[ (int) $id ] = $slug;
        }

        $processed = 0;
        $skipped   = 0;
        $failed    = 0;
        $last_id   = 0;

        // Native WC order statuses (the 'wc-' prefix is stripped for mapping).
        $wc_builtin = [
            'pending', 'processing', 'on-hold', 'completed',
            'cancelled', 'refunded', 'failed', 'checkout-draft',
        ];

        foreach ( $rows as $row ) {
            $oc_id     = (int) $row['order_status_id'];
            $oc_name   = $this->sanitizeText( $row['name'] ?? '' );
            $last_id   = max( $last_id, $oc_id );

            // Already resolved (from hardcoded map or prior run).
            if ( isset( $map[ $oc_id ] ) ) {
                $skipped++;
                continue;
            }

            // Derive a WC-compatible status slug.
            $slug = sanitize_title( $oc_name );

            // If it matches a built-in WC status, use it directly.
            if ( in_array( $slug, $wc_builtin, true ) ) {
                $map[ $oc_id ] = $slug;
                $processed++;
                $this->logger->info( "[order_statuses] OC #{$oc_id} '{$oc_name}' → built-in WC '{$slug}'." );
                continue;
            }

            // Otherwise register a custom WC status.
            $wc_slug = 'wc-oc-' . $slug;

            // WC order status slugs must be ≤ 20 chars (post_status DB column).
            if ( strlen( $wc_slug ) > 20 ) {
                $wc_slug = substr( $wc_slug, 0, 20 );
            }

            // The slug stored in the map omits the 'wc-' prefix (WC convention).
            $map_slug = ltrim( $wc_slug, 'wc-' );

            if ( $this->isDry() ) {
                $this->logger->debug( "[DRY-RUN][order_statuses] Would register custom status: '{$wc_slug}' for OC #{$oc_id} '{$oc_name}'." );
                $map[ $oc_id ] = $map_slug;
                $processed++;
                continue;
            }

            // Register the custom post status with WordPress.
            register_post_status( $wc_slug, [
                'label'                     => $oc_name,
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                /* translators: %s: number of orders */
                'label_count'               => _n_noop( $oc_name . ' <span class="count">(%s)</span>', $oc_name . ' <span class="count">(%s)</span>' ),
            ] );

            // Register with WooCommerce so it appears in the order status dropdown.
            add_filter( 'wc_order_statuses', static function ( array $statuses ) use ( $wc_slug, $oc_name ): array {
                $statuses[ $wc_slug ] = $oc_name;
                return $statuses;
            } );

            $map[ $oc_id ] = $map_slug;
            $processed++;

            $this->logger->info(
                "[order_statuses] OC #{$oc_id} '{$oc_name}' → custom WC status '{$wc_slug}'."
            );
        }

        // Persist the full map for all subsequent migrations in this run AND future runs.
        if ( ! $this->isDry() ) {
            update_option( 'octowoo_order_status_map', $map );
        }

        $this->checkpoint->update( self::KEY, $last_id, $processed + $skipped );
        $this->checkpoint->complete( self::KEY );

        $this->logger->info(
            "[order_statuses] Done. processed={$processed}, skipped={$skipped}, failed={$failed}."
        );

        return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed ];
    }
}
