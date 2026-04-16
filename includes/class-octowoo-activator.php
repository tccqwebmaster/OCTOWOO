<?php
/**
 * Plugin activation handler.
 *
 * Creates all required database tables and ensures the /logs/ directory exists.
 * Called once when the plugin is activated from the WP admin.
 */

defined( 'ABSPATH' ) || exit;

class OctoWoo_Activator {

    /**
     * Entry point called by register_activation_hook().
     */
    public static function activate(): void {
        self::create_tables();
        self::create_log_dir();
        self::set_default_options();

        // Flush rewrite rules so any new WP redirect rules take effect.
        flush_rewrite_rules();
    }

    /**
     * Ensure tables exist and are up to date.
     *
     * Safe to call on every migration run: dbDelta() is a no-op if the schema
     * already matches.  This covers plugin upgrades where files are replaced
     * without a WP deactivate/activate cycle (a common deployment pattern).
     */
    public static function maybeCreateTables(): void {
        $installed = get_option( 'octowoo_db_version', '' );
        if ( $installed === OCTOWOO_VERSION ) {
            return; // Already up to date — skip.
        }
        self::create_tables();
        update_option( 'octowoo_db_version', OCTOWOO_VERSION );
    }

    // ── Database tables ───────────────────────────────────────────────────────

    private static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // NOTE: dbDelta() requires exact formatting:
        //  - "CREATE TABLE" (no IF NOT EXISTS, no backticks on table name)
        //  - Two spaces between PRIMARY KEY and the column definition
        //  - KEY definitions on their own line

        // ── Log table ─────────────────────────────────────────────────────────
        $logs_table = $wpdb->prefix . 'octowoo_logs';
        $sql_logs   = "CREATE TABLE {$logs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(64) NOT NULL DEFAULT '',
            level varchar(20) NOT NULL DEFAULT 'INFO',
            migrator varchar(100) NOT NULL DEFAULT '',
            message text NOT NULL,
            context longtext,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_run_id (run_id),
            KEY idx_level (level),
            KEY idx_migrator (migrator)
        ) {$charset_collate};";

        // ── Checkpoint / resume table ─────────────────────────────────────────
        $cp_table = $wpdb->prefix . 'octowoo_checkpoints';
        $sql_cp   = "CREATE TABLE {$cp_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(64) NOT NULL DEFAULT '',
            migrator varchar(100) NOT NULL,
            last_oc_id bigint(20) NOT NULL DEFAULT 0,
            processed_count bigint(20) NOT NULL DEFAULT 0,
            total_count bigint(20) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_run_migrator (run_id,migrator)
        ) {$charset_collate};";

        // ── ID map table (OC ID → WC ID) ──────────────────────────────────────
        $map_table = $wpdb->prefix . 'octowoo_id_map';
        $sql_map   = "CREATE TABLE {$map_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entity_type varchar(50) NOT NULL,
            oc_id bigint(20) NOT NULL,
            wc_id bigint(20) NOT NULL,
            run_id varchar(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY uq_entity_oc (entity_type,oc_id),
            KEY idx_entity_wc (entity_type,wc_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_logs );
        dbDelta( $sql_cp );
        dbDelta( $sql_map );
    }

    // ── Filesystem ────────────────────────────────────────────────────────────

    private static function create_log_dir(): void {
        $log_dir = OCTOWOO_PLUGIN_DIR . 'logs/';
        if ( ! is_dir( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }

        // Prevent direct browsing.
        $htaccess = $log_dir . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Options -Indexes\nDeny from all\n" );
        }

        $index = $log_dir . 'index.html';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, '' );
        }
    }

    /**
     * Public helper to ensure the logs directory exists and is writable.
     * Safe to call during runtime (idempotent).
     */
    public static function ensure_log_dir(): void {
        self::create_log_dir();
    }

    // ── Default options ───────────────────────────────────────────────────────

    private static function set_default_options(): void {
        if ( false === get_option( 'octowoo_config' ) ) {
            add_option( 'octowoo_config', [] );
        }
        if ( false === get_option( 'octowoo_db_version' ) ) {
            add_option( 'octowoo_db_version', OCTOWOO_VERSION );
        }
    }
}
