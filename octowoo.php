<?php
/**
 * Plugin Name:       OctoWoo – OpenCart to WooCommerce Migrator
 * Plugin URI:        https://octowoo.com
 * Description:       Production-ready migration tool: migrate OpenCart 1/2/3/4 data
 *                    (categories, products, images, attributes, filters, downloads, tags,
 *                    manufacturers/brands, customers (inc. password compat.), orders, coupons,
 *                    reviews, information pages, SEO URLs, WPML/Polylang, Yoast SEO,
 *                    Rank Math SEO, and more) into WooCommerce. Supports batch processing,
 *                    resume, dry-run, background mode (Action Scheduler), cron auto-import,
 *                    WP-CLI, settings export/import, email reports, and an add-on hook system.
 * Version:           2.5.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            OctoWoo Team
 * Author URI:        https://octowoo.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       octowoo
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   9.8
 * Tested up to:      6.8
 */

defined( 'ABSPATH' ) || exit;

// ── Plugin constants ──────────────────────────────────────────────────────────
define( 'OCTOWOO_VERSION',    '2.5.0' );
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

register_activation_hook( __FILE__,   array( 'OctoWoo_Activator',   'activate'   ) );
register_deactivation_hook( __FILE__, array( 'OctoWoo_Deactivator', 'deactivate' ) );

// Declare WooCommerce HPOS (High-Performance Order Storage) compatibility.
add_action( 'before_woocommerce_init', function (): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_block_editor', __FILE__, true );
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
		// Ensure runtime safety for environments where activate() was not run.
		\OctoWoo_Activator::maybeCreateTables();
		\OctoWoo_Activator::ensure_log_dir();

		( new \OctoWoo\Admin\AdminPage() )->init();
		( new \OctoWoo\Admin\AjaxHandler() )->init();
	}

	// Register CronManager schedules + event (handles enable/disable automatically).
	\OctoWoo\Core\CronManager::register();

	// Register the Action Scheduler background processor callback.
	\OctoWoo\Core\BackgroundProcessor::register();

	// Register the OC-password compatibility filter when enabled.
	$saved_config = get_option( 'octowoo_config', array() );
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
 *
 * On the customer's first login after migration we verify against this formula,
 * upgrade to WP phpass, and delete the migration meta so this hook runs only once.
 *
 * @param  \WP_User|\WP_Error $user      WP_User object on success, WP_Error on failure.
 * @param  string             $password  Plaintext password the user typed.
 * @return \WP_User|\WP_Error
 */
function octowoo_oc_password_compat( $user, string $password ) {
	if ( is_wp_error( $user ) ) {
		return $user;
	}

	$oc_hash = get_user_meta( $user->ID, '_octowoo_oc_password_hash', true );
	$oc_salt = get_user_meta( $user->ID, '_octowoo_oc_password_salt', true );

	if ( ! $oc_hash || ! $oc_salt ) {
		return $user; // Already upgraded or no OC hash stored.
	}

	// Verify using OpenCart's sha1(salt.sha1(salt.sha1(plaintext))) formula.
	$computed = sha1( $oc_salt . sha1( $oc_salt . sha1( $password ) ) );

	if ( ! hash_equals( $oc_hash, $computed ) ) {
		return $user; // Wrong password — let WP handle the failure normally.
	}

	// Correct OC password — upgrade to WP native phpass immediately.
	wp_set_password( $password, $user->ID );
	delete_user_meta( $user->ID, '_octowoo_oc_password_hash' );
	delete_user_meta( $user->ID, '_octowoo_oc_password_salt' );

	// Reload user object so subsequent auth checks see the new hash.
	return get_user_by( 'id', $user->ID ) ?: $user;
}
