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

        // v2.5.0: Generate and store the plugin-specific encryption key on activation.
        // This ensures every site has a unique key rather than relying on AUTH_KEY.
        \OctoWoo\Core\Encryptor::generateAndStoreKey();

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
        // Canonical creation + web-protection lives in Logger::ensureLogDir(), which
        // uses OCTOWOO_LOG_DIR (now under wp-content/uploads/octowoo-logs/). Single
        // source of truth so activation and runtime stay consistent.
        if ( class_exists( '\OctoWoo\Core\Logger' ) ) {
            \OctoWoo\Core\Logger::ensureLogDir();
        }

        // One-time migration: move any legacy logs from the old in-plugin folder
        // (wp-content/plugins/octowoo/logs/) to the new uploads location, then
        // remove the old folder so the plugin directory stays clean.
        $legacy = OCTOWOO_PLUGIN_DIR . 'logs/';
        $target = OCTOWOO_LOG_DIR;
        if ( is_dir( $legacy ) && rtrim( $legacy, '/\\' ) !== rtrim( $target, '/\\' ) && is_dir( $target ) && is_writable( $target ) ) {
            foreach ( (array) glob( $legacy . '*.log' ) as $old ) {
                $dest = $target . basename( $old );
                if ( ! file_exists( $dest ) ) {
                    @rename( $old, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions
                }
            }
            // Remove leftover protection files + empty legacy dir (best-effort).
            @unlink( $legacy . '.htaccess' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions
            @unlink( $legacy . 'index.html' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions
            @rmdir( $legacy ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions
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
