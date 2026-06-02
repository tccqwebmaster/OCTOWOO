<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all plugin data: DB tables, options, logs.
 * This file is called automatically by WordPress on uninstall.
 */

// WordPress security check – abort if not uninstalling.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop custom tables.
$tables = [
    $wpdb->prefix . 'octowoo_logs',
    $wpdb->prefix . 'octowoo_checkpoints',
    $wpdb->prefix . 'octowoo_id_map',
];

foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

// Remove all plugin options.
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE 'octowoo_%'" );

// Remove plugin transients (both normal and timeout rows).
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '\_transient\_octowoo\_%' OR `option_name` LIKE '\_transient\_timeout\_octowoo\_%'" );

// Unschedule any pending Action Scheduler / WP-Cron events we registered.
foreach ( [ 'octowoo_run_chunk', 'octowoo_cron_import', 'octowoo_process_queue' ] as $hook ) {
    $ts = wp_next_scheduled( $hook );
    while ( $ts ) {
        wp_unschedule_event( $ts, $hook );
        $ts = wp_next_scheduled( $hook );
    }
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( $hook );
    }
}

// Remove log files from BOTH the new uploads location and the legacy in-plugin
// folder. Never touches anything outside our own log directories.
$log_dirs = [];
$uploads  = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : null;
if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
    $log_dirs[] = trailingslashit( $uploads['basedir'] ) . 'octowoo-logs/';
}
$log_dirs[] = plugin_dir_path( __FILE__ ) . 'logs/'; // legacy.

foreach ( $log_dirs as $log_dir ) {
    if ( ! is_dir( $log_dir ) ) {
        continue;
    }
    foreach ( (array) glob( $log_dir . '*' ) as $file ) {
        if ( is_file( $file ) ) {
            @unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
        }
    }
    @rmdir( $log_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
}
