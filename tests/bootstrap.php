<?php
/**
 * PHPUnit bootstrap for OctoWoo unit tests.
 *
 * These are TRUE unit tests: they exercise the plugin's own logic in isolation
 * using Brain\Monkey to stub WordPress functions, so no live WordPress or MySQL
 * install is required. Run with:  composer install && composer test
 *
 * For full integration tests against a real WP+WC+WPML stack, a separate
 * wp-phpunit suite would be added under tests/Integration (future work).
 */

declare( strict_types=1 );

// Composer autoload (provides PSR-4 for OctoWoo\ and OctoWoo\Tests\, plus the
// phpunit / brain-monkey / mockery dev dependencies).
$autoload = __DIR__ . '/../vendor/autoload.php';
if ( ! is_file( $autoload ) ) {
	fwrite( STDERR, "\n[bootstrap] vendor/autoload.php not found. Run `composer install` first.\n\n" );
	exit( 1 );
}
require $autoload;

// Minimal plugin constants the source files reference at load time.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wp/' );
}
if ( ! defined( 'OCTOWOO_VERSION' ) ) {
	define( 'OCTOWOO_VERSION', 'test' );
}
if ( ! defined( 'OCTOWOO_PLUGIN_DIR' ) ) {
	define( 'OCTOWOO_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'OCTOWOO_LOG_DIR' ) ) {
	define( 'OCTOWOO_LOG_DIR', sys_get_temp_dir() . '/octowoo-test-logs/' );
}
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}
