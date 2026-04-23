<?php
/**
 * Plugin Name:       OctoWoo – OpenCart to WooCommerce Migrator
 * Plugin URI:        https://github.com/octowoo/octowoo
 * Description:       Production-ready migration tool: migrate OpenCart 1/2/3/4 data
 *                    (categories, products, images, attributes, filters, downloads, tags,
 *                    manufacturers/brands, customers (inc. password compat.), orders, coupons,
 *                    reviews, information pages, SEO URLs, WPML/Polylang, Yoast SEO, and more)
 *                    into WooCommerce. Supports batch processing, resume, dry-run, cron
 *                    auto-import, WP-CLI, and an add-on hook system.
 * Version:           2.4.48
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            OctoWoo Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       octowoo
 * Domain Path:       /languages
 * WC requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

// ── Plugin constants ──────────────────────────────────────────────────────────
define( 'OCTOWOO_VERSION',    '2.4.48' );
define( 'OCTOWOO_FILE',       __FILE__ );
define( 'OCTOWOO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OCTOWOO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OCTOWOO_LOG_DIR',    OCTOWOO_PLUGIN_DIR . 'logs/' );

/**
 * PSR-4 autoloader.
 * Namespace OctoWoo\ maps to /src/ directory.
 * e.g. OctoWoo\Core\Logger → src/Core/Logger.php
 */
spl_autoload_register( function ( string $class ): void {
    $prefix   = 'OctoWoo\\';
    $base_dir = OCTOWOO_PLUGIN_DIR . 'src/';

    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
        return;
    }

    $relative = substr( $class, strlen( $prefix ) );
    $file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// Include lifecycle classes before hooks fire.
require_once OCTOWOO_PLUGIN_DIR . 'includes/class-octowoo-activator.php';
require_once OCTOWOO_PLUGIN_DIR . 'includes/class-octowoo-deactivator.php';

register_activation_hook( __FILE__,   [ 'OctoWoo_Activator',   'activate'   ] );
register_deactivation_hook( __FILE__, [ 'OctoWoo_Deactivator', 'deactivate' ] );

// Declare WooCommerce HPOS (High-Performance Order Storage) compatibility.
add_action( 'before_woocommerce_init', function (): void {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

/**
 * Bootstrap: fires after all plugins are loaded so WooCommerce is definitely available.
 */
add_action( 'plugins_loaded', function (): void {

    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function (): void {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__( 'OctoWoo requires WooCommerce to be installed and active.', 'octowoo' )
            );
        } );
        return;
    }

    load_plugin_textdomain( 'octowoo', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    if ( is_admin() ) {
        // Ensure runtime safety for environments where activate() was not run
        // (some hosts only copy files and don't trigger activation). Create
        // DB tables if needed and ensure the logs directory exists so the
        // admin UI doesn't warn unnecessarily.
        \OctoWoo_Activator::maybeCreateTables();
        \OctoWoo_Activator::ensure_log_dir();

        ( new \OctoWoo\Admin\AdminPage() )->init();
        ( new \OctoWoo\Admin\AjaxHandler() )->init();
    }

    // Register CronManager schedules + event (handles enable/disable automatically).
    \OctoWoo\Core\CronManager::register();

    // Register the Action Scheduler background processor callback.
    // BackgroundProcessor::isAvailable() is false before WC loads its AS copy,
    // but the add_action() call is cheap and safe to make unconditionally.
    \OctoWoo\Core\BackgroundProcessor::register();

    // Register the OC-password compatibility filter when enabled.
    $saved_config = get_option( 'octowoo_config', [] );
    if ( ! empty( $saved_config['woocommerce']['migrate_oc_passwords'] ) ) {
        add_filter( 'wp_authenticate_user', 'octowoo_oc_password_compat', 30, 2 );
    }

    // WP-CLI integration.
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        require_once OCTOWOO_PLUGIN_DIR . 'cli/class-octowoo-cli.php';
        \WP_CLI::add_command( 'octowoo', 'OctoWoo_CLI' );
    }
} );

/**
 * OpenCart password compatibility filter.
 *
 * OpenCart 2.x / 3.x stores passwords as:
 *   sha1( $salt . sha1( $salt . sha1( $plaintext ) ) )
 * (see system/library/customer.php in all OC 2.x / 3.x releases)
 *
 * On the customer's first login after migration we verify against this formula,
 * upgrade to WP phpass, and delete the migration meta so this hook runs only once.
 *
 * This filter is only registered when woocommerce.migrate_oc_passwords = true.
 *
 * @param  \WP_User|\WP_Error $user      WP_User object on success, WP_Error on pre-auth failure.
 * @param  string             $password  Plaintext password the user typed.
 * @return \WP_User|\WP_Error
 */
function octowoo_oc_password_compat( $user, string $password ) {
    if ( is_wp_error( $user ) ) {
        return $user;
    }

    // Only act when the account still has an OC hash stored.
    $oc_hash = (string) get_user_meta( $user->ID, '_octowoo_oc_password_hash', true );
    $oc_salt = (string) get_user_meta( $user->ID, '_octowoo_oc_password_salt', true );

    if ( ! $oc_hash ) {
        return $user;
    }

    // OC 2.x / 3.x formula: sha1( $salt . sha1( $salt . sha1( $plaintext ) ) )
    $candidate = sha1( $oc_salt . sha1( $oc_salt . sha1( $password ) ) );

    if ( ! hash_equals( $oc_hash, $candidate ) ) {
        return new \WP_Error(
            'incorrect_password',
            __( 'The password you entered is incorrect.', 'octowoo' )
        );
    }

    // Correct OC password - upgrade to WP phpass so this filter won't run again.
    wp_set_password( $password, $user->ID );

    // wp_set_password() updates the DB but NOT the in-memory $user object.
    // wp_authenticate_username_password() calls wp_check_password( $password,
    // $user->user_pass ) immediately after this filter returns, so we must
    // refresh the property - otherwise the login fails even on a correct password.
    clean_user_cache( $user->ID );
    $refreshed = get_userdata( $user->ID );
    if ( $refreshed ) {
        $user->user_pass = $refreshed->user_pass;
    }

    // Clean up all migration meta - account is now a normal WP account.
    delete_user_meta( $user->ID, '_octowoo_oc_password_hash' );
    delete_user_meta( $user->ID, '_octowoo_oc_password_salt' );
    delete_user_meta( $user->ID, '_octowoo_password_reset_required' );
    delete_user_meta( $user->ID, 'default_password_nag' );

    return $user;
}
