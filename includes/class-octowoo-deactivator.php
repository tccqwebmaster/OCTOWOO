<?php
/**
 * Plugin deactivation handler.
 *
 * Cleans up scheduled hooks and rewrite rules.
 * Does NOT remove data – that is handled by uninstall.php.
 */

defined( 'ABSPATH' ) || exit;

class OctoWoo_Deactivator {

    /**
     * Entry point called by register_deactivation_hook().
     */
    public static function deactivate(): void {
        // Clear any scheduled background batches.
        wp_clear_scheduled_hook( 'octowoo_process_batch' );

        // Flush rewrite rules to remove any redirect rules we may have registered.
        flush_rewrite_rules();
    }
}
