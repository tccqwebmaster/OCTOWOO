<?php
/**
 * OctoWoo Add-on / Extension system.
 *
 * Provides a clean, name-spaced layer of WordPress actions & filters that
 * third-party plugins or custom code can hook into to extend or modify the
 * migration without patching core files.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * AVAILABLE HOOKS
 *
 * Filters (return modified value):
 *   octowoo_product_data          ($data, $oc_row, $config)
 *   octowoo_category_data         ($data, $oc_row, $config)
 *   octowoo_customer_data         ($data, $oc_row, $config)
 *   octowoo_order_data            ($data, $oc_row, $config)
 *   octowoo_coupon_data           ($data, $oc_row, $config)
 *   octowoo_information_data      ($data, $oc_row, $config)
 *   octowoo_manufacturer_data     ($data, $oc_row, $config)
 *   octowoo_should_skip_product   (bool $skip, $oc_row)
 *   octowoo_should_skip_customer  (bool $skip, $oc_row)
 *   octowoo_should_skip_order     (bool $skip, $oc_row)
 *   octowoo_brand_taxonomy        (string $taxonomy)
 *
 * Actions (do not return a value):
 *   octowoo_migration_started     ($run_id, $config)
 *   octowoo_migration_finished    ($run_id, $report)
 *   octowoo_after_migrate_product      ($oc_id, $wc_id, $oc_row, $config)
 *   octowoo_after_migrate_category     ($oc_id, $wc_term_id, $oc_row, $config)
 *   octowoo_after_migrate_customer     ($oc_id, $wc_user_id, $oc_row, $config)
 *   octowoo_after_migrate_order        ($oc_id, $wc_order_id, $oc_row, $config)
 *   octowoo_after_migrate_coupon       ($oc_id, $wc_coupon_id, $oc_row, $config)
 *   octowoo_after_migrate_information  ($oc_id, $wp_page_id, $oc_row, $config)
 *   octowoo_after_migrate_manufacturer ($oc_id, $term_id, $taxonomy, $oc_row, $config)
 *   octowoo_after_migrate_review       ($oc_id, $comment_id, $wc_product_id)
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Usage example in your theme's functions.php or a separate plugin:
 *
 *   // Skip products with zero price.
 *   add_filter( 'octowoo_should_skip_product', function( $skip, $oc_row ) {
 *       return $skip || (float) $oc_row['price'] === 0.0;
 *   }, 10, 2 );
 *
 *   // Add custom meta to every migrated product.
 *   add_action( 'octowoo_after_migrate_product', function( $oc_id, $wc_id, $oc_row ) {
 *       update_post_meta( $wc_id, '_my_plugin_synced_from_oc', $oc_id );
 *   }, 10, 3 );
 *
 * @package OctoWoo\Integration
 */

namespace OctoWoo\Integration;

defined( 'ABSPATH' ) || exit;

class AddonManager {

    /** @var array<string, callable[]>  Local registry for add-ons registered before WP is ready. */
    private static array $pending_hooks = [];

    // ── Registration API ──────────────────────────────────────────────────────

    /**
     * Register an OctoWoo action hook.
     *
     * Wraps add_action() so the caller does not need to know the full hook name.
     * Hook name is automatically prefixed with "octowoo_".
     *
     * @param string   $hook      Short hook name (without "octowoo_" prefix).
     * @param callable $callback  Callback function.
     * @param int      $priority  WordPress hook priority (default 10).
     * @param int      $args      Number of arguments accepted.
     */
    public static function onAction( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
        add_action( 'octowoo_' . $hook, $callback, $priority, $args );
    }

    /**
     * Register an OctoWoo filter.
     *
     * @param string   $hook      Short filter name (without "octowoo_" prefix).
     * @param callable $callback  Callback function.
     * @param int      $priority  WordPress hook priority (default 10).
     * @param int      $args      Number of arguments accepted.
     */
    public static function onFilter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
        add_filter( 'octowoo_' . $hook, $callback, $priority, $args );
    }

    /**
     * Remove a previously registered OctoWoo action.
     */
    public static function removeAction( string $hook, callable $callback, int $priority = 10 ): void {
        remove_action( 'octowoo_' . $hook, $callback, $priority );
    }

    /**
     * Remove a previously registered OctoWoo filter.
     */
    public static function removeFilter( string $hook, callable $callback, int $priority = 10 ): void {
        remove_filter( 'octowoo_' . $hook, $callback, $priority );
    }

    // ── Internal fire helpers (called by migrators / MigrationManager) ────────

    /**
     * Fire a named action hook.
     *
     * @param string $hook   Short name (without "octowoo_" prefix).
     * @param mixed  ...$args
     */
    public static function fireAction( string $hook, ...$args ): void {
        do_action( 'octowoo_' . $hook, ...$args );
    }

    /**
     * Apply a named filter hook.
     *
     * @param string $hook   Short name (without "octowoo_" prefix).
     * @param mixed  $value  The value to filter.
     * @param mixed  ...$args Additional arguments passed to filter callbacks.
     * @return mixed  Filtered value.
     */
    public static function applyFilter( string $hook, $value, ...$args ) {
        return apply_filters( 'octowoo_' . $hook, $value, ...$args );
    }

    // ── Skip-gate helpers (used by migrators) ─────────────────────────────────

    /**
     * Determine whether a given OC product should be skipped.
     *
     * @param array $oc_row  Raw OC product row.
     */
    public static function shouldSkipProduct( array $oc_row ): bool {
        return (bool) apply_filters( 'octowoo_should_skip_product', false, $oc_row );
    }

    /**
     * Determine whether a given OC customer should be skipped.
     */
    public static function shouldSkipCustomer( array $oc_row ): bool {
        return (bool) apply_filters( 'octowoo_should_skip_customer', false, $oc_row );
    }

    /**
     * Determine whether a given OC order should be skipped.
     */
    public static function shouldSkipOrder( array $oc_row ): bool {
        return (bool) apply_filters( 'octowoo_should_skip_order', false, $oc_row );
    }

    // ── Data modification helpers ─────────────────────────────────────────────

    /**
     * Allow add-ons to modify product post data before wp_insert_post/wp_update_post.
     *
     * @param  array $data    Post data array.
     * @param  array $oc_row  Raw OC product row.
     * @param  array $config  Full plugin config.
     * @return array
     */
    public static function filterProductData( array $data, array $oc_row, array $config ): array {
        return (array) apply_filters( 'octowoo_product_data', $data, $oc_row, $config );
    }

    /**
     * Allow add-ons to modify category term args before wp_insert_term.
     */
    public static function filterCategoryData( array $data, array $oc_row, array $config ): array {
        return (array) apply_filters( 'octowoo_category_data', $data, $oc_row, $config );
    }

    /**
     * Allow add-ons to modify customer data before wp_insert_user.
     */
    public static function filterCustomerData( array $data, array $oc_row, array $config ): array {
        return (array) apply_filters( 'octowoo_customer_data', $data, $oc_row, $config );
    }

    /**
     * Allow add-ons to modify order data before wc_create_order.
     */
    public static function filterOrderData( array $data, array $oc_row, array $config ): array {
        return (array) apply_filters( 'octowoo_order_data', $data, $oc_row, $config );
    }

    // ── Bootstrap ─────────────────────────────────────────────────────────────

    /**
     * Load any registered add-on plugins.
     * Called by MigrationManager during bootstrap.
     *
     * @param array $config
     */
    public static function loadAddons( array $config ): void {
        /**
         * Fires when OctoWoo add-ons should register themselves.
         *
         * Third-party plugins can use this hook to add their own actions/filters.
         *
         * @param array $config  Full resolved plugin config.
         */
        do_action( 'octowoo_register_addons', $config );
    }
}
