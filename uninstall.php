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

// Remove log files.
$log_dir = plugin_dir_path( __FILE__ ) . 'logs/';
if ( is_dir( $log_dir ) ) {
    $files = glob( $log_dir . '*.log' );
    if ( $files ) {
        foreach ( $files as $file ) {
            if ( is_file( $file ) ) {
                @unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }
    }
}
