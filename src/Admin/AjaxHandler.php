<?php
/**
 * AJAX handler.
 *
 * Handles all admin-ajax.php requests for the OctoWoo dashboard.
 *
 * Actions registered:
 *  octowoo_start_migration   – launch a new migration run (or resume).
 *  octowoo_abort_migration   – flag the active run for abort.
 *  octowoo_get_progress      – return checkpoint snapshot for polling.
 *  octowoo_get_logs          – return recent log entries.
 *  octowoo_reset_migration   – clear all checkpoints / ID map for a fresh start.
 *
 * All actions require:
 *  - User capability: manage_woocommerce
 *  - Valid nonce: octowoo_ajax
 *
 * Responses are JSON objects: { success: bool, data: ... }
 */

namespace OctoWoo\Admin;

use OctoWoo\Core\CheckpointManager;
use OctoWoo\Core\MigrationManager;
use OctoWoo\Core\Logger;
use OctoWoo\Core\SqlImporter;
use OctoWoo\Core\DataPurger;

defined( 'ABSPATH' ) || exit;

class AjaxHandler {

    private const CAP   = 'manage_woocommerce';
    private const NONCE = 'octowoo_ajax';

    // ── Bootstrap ─────────────────────────────────────────────────────────────

    public function init(): void {
        $actions = [
            'octowoo_start_migration',
            'octowoo_abort_migration',
            'octowoo_get_progress',
            'octowoo_get_logs',
            'octowoo_reset_migration',
            'octowoo_test_connection',
            'octowoo_run_chunk',
            'octowoo_import_sql',
            'octowoo_import_images',
            'octowoo_purge_imported',
            'octowoo_scan_counts',
        ];

        foreach ( $actions as $action ) {
            // Only for logged-in users (no nopriv variant intentionally).
            add_action( 'wp_ajax_' . $action, [ $this, 'dispatch' ] );
        }
    }

    // ── Central dispatcher ────────────────────────────────────────────────────

    public function dispatch(): void {
        // Security: capability + nonce.
        if ( ! current_user_can( self::CAP ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'octowoo' ) ], 403 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $action = sanitize_key( $_REQUEST['action'] ?? '' );

        // Verify nonce for all actions.
        if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid security token.', 'octowoo' ) ], 403 );
        }

        switch ( $action ) {
            case 'octowoo_start_migration':
                $this->actionStartMigration();
                break;

            case 'octowoo_abort_migration':
                $this->actionAbortMigration();
                break;

            case 'octowoo_get_progress':
                $this->actionGetProgress();
                break;

            case 'octowoo_get_logs':
                $this->actionGetLogs();
                break;

            case 'octowoo_reset_migration':
                $this->actionResetMigration();
                break;

            case 'octowoo_test_connection':
                $this->actionTestConnection();
                break;

            case 'octowoo_run_chunk':
                $this->actionRunChunk();
                break;

            case 'octowoo_import_sql':
                $this->actionImportSql();
                break;

            case 'octowoo_import_images':
                $this->actionImportImages();
                break;

            case 'octowoo_purge_imported':
                $this->actionPurgeImported();
                break;

            case 'octowoo_scan_counts':
                $this->actionScanCounts();
                break;

            default:
                wp_send_json_error( [ 'message' => 'Unknown action.' ], 400 );
        }
    }

    // ── Action: start / resume ────────────────────────────────────────────────

    private function actionStartMigration(): void {
        // Prevent parallel runs.
        $active_run = CheckpointManager::getActiveRunId();

        // Auto-clear stale lock: if the active run ID has no checkpoint rows,
        // it was never actually started (e.g. a previous failed boot) and is safe to drop.
        if ( $active_run ) {
            global $wpdb;
            $cp_table = $wpdb->prefix . 'octowoo_checkpoints';
            $has_data = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM `{$cp_table}` WHERE run_id = %s", $active_run ) // phpcs:ignore WordPress.DB.PreparedSQL
            );
            if ( $has_data === 0 ) {
                delete_option( 'octowoo_active_run_id' );
                $active_run = null;
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $resume = filter_input( INPUT_POST, 'resume', FILTER_VALIDATE_BOOLEAN );

        if ( $active_run && ! $resume ) {
            wp_send_json_error( [
                'message' => __( 'A migration is already in progress. Abort it first or use Resume.', 'octowoo' ),
                'run_id'  => $active_run,
            ] );
        }

        // Resolve run ID: use existing if resuming, generate new otherwise.
        $run_id = $resume && $active_run ? $active_run : null;

        // phpcs:ignore WordPress.Security.NonceVerification
        $dry_run_flag = filter_input( INPUT_POST, 'dry_run', FILTER_VALIDATE_BOOLEAN );
        // phpcs:ignore WordPress.Security.NonceVerification
        $demo_limit_raw = (int) filter_input( INPUT_POST, 'demo_limit', FILTER_VALIDATE_INT );

        // Build runtime overrides: dry-run flag + demo limit + per-migrator run flags.
        $overrides = [];
        if ( $dry_run_flag ) {
            $overrides['migration']['dry_run'] = true;
        }
        if ( $demo_limit_raw > 0 ) {
            $overrides['migration']['demo_limit'] = $demo_limit_raw;
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $migrators_raw = sanitize_text_field( (string) filter_input( INPUT_POST, 'migrators', FILTER_SANITIZE_SPECIAL_CHARS ) );
        if ( $migrators_raw !== '' ) {
            $allowed   = [ 'tax', 'order_statuses', 'categories', 'manufacturers', 'images', 'products', 'related', 'bundles', 'customers', 'orders', 'coupons', 'seo', 'information', 'tags', 'filters', 'downloads', 'reviews', 'multilingual' ];
            $selected  = array_filter( explode( ',', $migrators_raw ), fn( $k ) => in_array( $k, $allowed, true ) );
            foreach ( $allowed as $key ) {
                $overrides['migration'][ 'run_' . $key ] = in_array( $key, $selected, true );
            }
        }

        try {
            $manager = new MigrationManager( $overrides, $run_id );

            // Run migration synchronously.
            // For production use on large catalogs, schedule via WP Cron or WP-CLI.
            // This AJAX handler is suitable for small-to-medium catalogs within max_execution_time.
            set_time_limit( 0 );
            $report = $manager->run();

            wp_send_json_success( [
                'message' => __( 'Migration completed.', 'octowoo' ),
                'run_id'  => $manager->getRunId(),
                'report'  => $report,
            ] );

        } catch ( \Throwable $e ) {
            wp_send_json_error( [
                'message'   => __( 'Migration failed: ', 'octowoo' ) . $e->getMessage(),
                'exception' => get_class( $e ),
            ] );
        }
    }

    // ── Action: abort ─────────────────────────────────────────────────────────

    private function actionAbortMigration(): void {
        $run_id = sanitize_text_field(
            filter_input( INPUT_POST, 'run_id', FILTER_SANITIZE_SPECIAL_CHARS )
                ?? CheckpointManager::getActiveRunId()
                ?? ''
        );

        if ( ! $run_id ) {
            wp_send_json_error( [ 'message' => __( 'No active migration to abort.', 'octowoo' ) ] );
        }

        // Signal any still-running PHP process to stop gracefully.
        MigrationManager::requestAbort( $run_id );

        // Force-clear the state immediately so the next "Start" isn't blocked.
        // This covers the case where the PHP process has already died (e.g. AJAX timeout)
        // and will never read the transient signal itself.
        global $wpdb;
        $cp_table = $wpdb->prefix . 'octowoo_checkpoints';
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$cp_table}` SET status = 'aborted' WHERE run_id = %s AND status = 'running'", // phpcs:ignore WordPress.DB.PreparedSQL
                $run_id
            )
        );

        $cp = new CheckpointManager( $run_id );
        $cp->markRunFinished();

        wp_send_json_success( [
            'message' => __( 'Migration aborted and lock cleared. You can start a new migration.', 'octowoo' ),
            'run_id'  => $run_id,
        ] );
    }

    // ── Action: get progress ──────────────────────────────────────────────────

    private function actionGetProgress(): void {
        $run_id = sanitize_text_field(
            filter_input( INPUT_GET, 'run_id', FILTER_SANITIZE_SPECIAL_CHARS )
                ?? CheckpointManager::getActiveRunId()
                ?? get_option( 'octowoo_last_run_id', '' )
        );

        if ( ! $run_id ) {
            wp_send_json_success( [ 'checkpoints' => [], 'active' => false ] );
        }

        $checkpoint = new CheckpointManager( $run_id );

        wp_send_json_success( [
            'run_id'      => $run_id,
            'active'      => CheckpointManager::getActiveRunId() === $run_id,
            'checkpoints' => $checkpoint->getAll(),
            'started_at'  => get_option( 'octowoo_run_started_at', '' ),
        ] );
    }

    // ── Action: get logs ──────────────────────────────────────────────────────

    private function actionGetLogs(): void {
        $run_id = sanitize_text_field(
            filter_input( INPUT_GET, 'run_id', FILTER_SANITIZE_SPECIAL_CHARS )
                ?? CheckpointManager::getActiveRunId()
                ?? get_option( 'octowoo_last_run_id', '' )
        );

        $level    = sanitize_key( filter_input( INPUT_GET, 'level', FILTER_SANITIZE_SPECIAL_CHARS ) ?? '' );
        $migrator = sanitize_key( filter_input( INPUT_GET, 'migrator', FILTER_SANITIZE_SPECIAL_CHARS ) ?? '' );
        $limit    = min( 500, max( 10, (int) filter_input( INPUT_GET, 'limit', FILTER_VALIDATE_INT ) ?: 100 ) );

        if ( ! $run_id ) {
            wp_send_json_success( [ 'logs' => [] ] );
        }

        $logger  = new Logger( $run_id );
        $entries = $logger->getRecentEntries( $limit, $level, $migrator );

        wp_send_json_success( [
            'run_id' => $run_id,
            'logs'   => $entries,
        ] );
    }

    // ── Action: run one chunk ─────────────────────────────────────────────────

    private function actionRunChunk(): void {
        // phpcs:ignore WordPress.Security.NonceVerification
        $run_id   = sanitize_text_field( (string) filter_input( INPUT_POST, 'run_id', FILTER_SANITIZE_SPECIAL_CHARS ) );
        // phpcs:ignore WordPress.Security.NonceVerification
        $resume   = filter_input( INPUT_POST, 'resume', FILTER_VALIDATE_BOOLEAN );
        // phpcs:ignore WordPress.Security.NonceVerification
        $dry_run  = filter_input( INPUT_POST, 'dry_run', FILTER_VALIDATE_BOOLEAN );
        // phpcs:ignore WordPress.Security.NonceVerification
        $migrators_raw = sanitize_text_field( (string) filter_input( INPUT_POST, 'migrators', FILTER_SANITIZE_SPECIAL_CHARS ) );

        // ── Stale-lock guard (same logic as actionStartMigration) ────────────
        $active_run = CheckpointManager::getActiveRunId();
        if ( $active_run ) {
            global $wpdb;
            $cp_table = $wpdb->prefix . 'octowoo_checkpoints';
            $has_data = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM `{$cp_table}` WHERE run_id = %s", $active_run ) // phpcs:ignore WordPress.DB.PreparedSQL
            );
            if ( $has_data === 0 ) {
                delete_option( 'octowoo_active_run_id' );
                $active_run = null;
            }
        }

        // If a run_id was sent, treat this as a resume of that specific run.
        // If empty, it's a fresh start — block if another run is truly active.
        if ( $run_id === '' ) {
            if ( $active_run ) {
                wp_send_json_error( [
                    'message' => __( 'A migration is already in progress. Abort it first or use Resume.', 'octowoo' ),
                    'run_id'  => $active_run,
                ] );
            }
            $run_id = null; // let MigrationManager generate a fresh ID
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $demo_limit_chunk = (int) filter_input( INPUT_POST, 'demo_limit', FILTER_VALIDATE_INT );

        $overrides = [];
        if ( $dry_run ) {
            $overrides['migration']['dry_run'] = true;
        }
        if ( $demo_limit_chunk > 0 ) {
            $overrides['migration']['demo_limit'] = $demo_limit_chunk;
        }
        if ( $migrators_raw !== '' ) {
            $allowed  = [ 'tax', 'order_statuses', 'categories', 'manufacturers', 'images', 'products', 'related', 'bundles', 'customers', 'orders', 'coupons', 'seo', 'information', 'tags', 'filters', 'downloads', 'reviews', 'multilingual' ];
            $selected = array_filter( explode( ',', $migrators_raw ), fn( $k ) => in_array( $k, $allowed, true ) );
            foreach ( $allowed as $key ) {
                $overrides['migration'][ 'run_' . $key ] = in_array( $key, $selected, true );
            }
        }

        try {
            set_time_limit( 60 ); // One chunk = at most 60 s.
            $manager = new MigrationManager( $overrides, $run_id ?: null );
            $result  = $manager->runNextChunk();

            wp_send_json_success( [
                'run_id'      => $manager->getRunId(),
                'done_all'    => $result['done_all'],
                'aborted'     => $result['aborted'] ?? false,
                'migrator'    => $result['migrator'],
                'checkpoints' => $result['checkpoints'],
                'report'      => $result['report'],
            ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [
                'message'   => __( 'Chunk failed: ', 'octowoo' ) . $e->getMessage(),
                'exception' => get_class( $e ),
            ] );
        }
    }

    // ── Action: import SQL dump ───────────────────────────────────────────────

    private function actionImportSql(): void {
        // Validate upload.
        if ( empty( $_FILES['sql_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['sql_file']['tmp_name'] ) ) {
            wp_send_json_error( [ 'message' => 'No SQL file uploaded.' ] );
        }

        $file     = $_FILES['sql_file'];
        $tmp_path = $file['tmp_name'];
        $name     = sanitize_file_name( $file['name'] );
        $ext      = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

        if ( ! in_array( $ext, [ 'sql', 'gz' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Only .sql or .gz files are accepted.' ] );
        }

        // For .gz, decompress to a temp file first.
        if ( $ext === 'gz' ) {
            $unzipped = tempnam( sys_get_temp_dir(), 'ow_sql_' ) . '.sql';
            $gz_in    = gzopen( $tmp_path, 'rb' );
            $fp_out   = fopen( $unzipped, 'wb' );
            if ( ! $gz_in || ! $fp_out ) {
                wp_send_json_error( [ 'message' => 'Failed to decompress .gz file.' ] );
            }
            while ( ! gzeof( $gz_in ) ) {
                fwrite( $fp_out, gzread( $gz_in, 65536 ) );
            }
            gzclose( $gz_in );
            fclose( $fp_out );
            $tmp_path = $unzipped;
        }

        $prefix = sanitize_text_field( $_POST['source_prefix'] ?? 'oc_' );
        $prefix = preg_replace( '/[^a-zA-Z0-9_]/', '', $prefix ) ?: 'oc_';

        set_time_limit( 0 );

        try {
            $importer = new SqlImporter( $prefix );
            $importer->dropImportedTables(); // clean slate

            $last = null;
            foreach ( $importer->import( $tmp_path ) as $progress ) {
                $last = $progress;
            }

            // Auto-save source = local so migration knows to use WP DB.
            // NOTE: db.prefix is NOT overwritten here — DatabaseConnector automatically
            // uses SqlImporter::IMPORT_PREFIX ('octowoo_oc_') at runtime when source=local.
            // The user's original prefix (e.g. 'oc_') stays in Settings for display.
            $saved = get_option( 'octowoo_config', [] );
            $saved['source'] = 'local';
            update_option( 'octowoo_config', $saved, false );

            wp_send_json_success( [
                'message' => $last['message'] ?? 'Import complete.',
            ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => 'SQL import failed: ' . $e->getMessage() ] );
        } finally {
            if ( $ext === 'gz' && isset( $unzipped ) && file_exists( $unzipped ) ) {
                unlink( $unzipped );
            }
        }
    }

    // ── Action: import images ZIP ─────────────────────────────────────────────

    private function actionImportImages(): void {
        if ( empty( $_FILES['images_zip']['tmp_name'] ) || ! is_uploaded_file( $_FILES['images_zip']['tmp_name'] ) ) {
            wp_send_json_error( [ 'message' => 'No ZIP file uploaded.' ] );
        }

        $name = sanitize_file_name( $_FILES['images_zip']['name'] );
        $ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        if ( $ext !== 'zip' ) {
            wp_send_json_error( [ 'message' => 'Only .zip files are accepted.' ] );
        }

        $dest = SqlImporter::getImagesDir();
        if ( ! wp_mkdir_p( $dest ) ) {
            wp_send_json_error( [ 'message' => 'Could not create images directory: ' . $dest ] );
        }

        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_send_json_error( [ 'message' => 'PHP ZipArchive extension is not available on this server.' ] );
        }

        $zip = new \ZipArchive();
        if ( $zip->open( $_FILES['images_zip']['tmp_name'] ) !== true ) {
            wp_send_json_error( [ 'message' => 'Could not open ZIP file.' ] );
        }

        // Security: only extract safe file extensions, reject ../ paths.
        $allowed_exts = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico' ];
        $extracted    = 0;
        $skipped      = 0;

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry     = $zip->getNameIndex( $i );
            $entry_ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );

            // Reject path traversal and non-image files.
            if ( strpos( $entry, '..' ) !== false ) {
                $skipped++;
                continue;
            }
            if ( $entry_ext !== '' && ! in_array( $entry_ext, $allowed_exts, true ) ) {
                $skipped++;
                continue;
            }

            $out_path = $dest . $entry;
            $out_dir  = dirname( $out_path );
            wp_mkdir_p( $out_dir );

            if ( $entry_ext !== '' ) {
                $content = $zip->getFromIndex( $i );
                if ( $content !== false ) {
                    file_put_contents( $out_path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
                    $extracted++;
                }
            }
        }
        $zip->close();

        // Auto-save image_source = local.
        $saved = get_option( 'octowoo_config', [] );
        if ( ! isset( $saved['opencart'] ) ) {
            $saved['opencart'] = [];
        }
        $saved['opencart']['image_source'] = 'local';
        update_option( 'octowoo_config', $saved, false );

        wp_send_json_success( [
            'message'   => "Images extracted: {$extracted} files (skipped: {$skipped}).",
            'path'      => $dest,
            'extracted' => $extracted,
        ] );
    }

    // ── Action: test DB connection ─────────────────────────────────────────────

    private function actionTestConnection(): void {
        $defaults = require OCTOWOO_PLUGIN_DIR . 'config/default-config.php';
        $saved    = get_option( 'octowoo_config', [] );

        // Merge: defaults ← saved ← live form values posted with the request.
        // This lets the test run against what the user typed, before saving.
        $db_config = array_merge(
            $defaults['db'],
            is_array( $saved['db'] ?? null ) ? $saved['db'] : []
        );

        // Override with any live values sent from the form fields.
        if ( ! empty( $_POST['db_host'] ) ) {
            $db_config['host']     = sanitize_text_field( $_POST['db_host'] );
            $db_config['port']     = (int) ( $_POST['db_port'] ?? 3306 );
            $db_config['database'] = sanitize_text_field( $_POST['db_name'] ?? '' );
            $db_config['username'] = sanitize_text_field( $_POST['db_user'] ?? '' );
            // Use posted password; fall back to saved if left blank.
            $db_config['password'] = ( isset( $_POST['db_pass'] ) && $_POST['db_pass'] !== '' )
                ? $_POST['db_pass']
                : ( $db_config['password'] ?? '' );
            $db_config['prefix']   = sanitize_text_field( $_POST['db_prefix'] ?? 'oc_' );
            $db_config['socket']   = sanitize_text_field( $_POST['db_socket'] ?? '' );
        }

        $connector = new \OctoWoo\Core\DatabaseConnector( $db_config );
        $error     = $connector->testConnection();

        $debug = sprintf(
            'Trying: %s@%s:%d / db=%s',
            $db_config['username'] ?? '?',
            $db_config['host']     ?? '?',
            $db_config['port']     ?? 3306,
            $db_config['database'] ?? '?'
        );

        if ( $error === null ) {
            wp_send_json_success( [ 'message' => '✔ Connection successful!' ] );
        } else {
            wp_send_json_error( [ 'message' => $error . ' | ' . $debug ] );
        }
    }

    // ── Action: reset ─────────────────────────────────────────────────────────

    private function actionResetMigration(): void {
        global $wpdb;

        // Must not be currently running.
        if ( CheckpointManager::getActiveRunId() ) {
            wp_send_json_error( [
                'message' => __( 'Cannot reset while a migration is active. Abort it first.', 'octowoo' ),
            ] );
        }

        // Clear ID maps and checkpoints for the last run.
        $run_id = get_option( 'octowoo_last_run_id', '' );

        if ( $run_id ) {
            $cp_table  = $wpdb->prefix . 'octowoo_checkpoints';
            $map_table = $wpdb->prefix . 'octowoo_id_map';

            $wpdb->delete( $cp_table,  [ 'run_id' => $run_id ], [ '%s' ] );
            $wpdb->delete( $map_table, [ 'run_id' => $run_id ], [ '%s' ] );
        }

        delete_option( 'octowoo_last_run_id' );
        delete_option( 'octowoo_last_run_at' );
        delete_option( 'octowoo_active_run_id' );

        wp_send_json_success( [
            'message' => __( 'Migration data reset. You can start a fresh migration.', 'octowoo' ),
        ] );
    }

    // ── Action: scan source entity counts ─────────────────────────────────────

    private function actionScanCounts(): void {
        $defaults  = require OCTOWOO_PLUGIN_DIR . 'config/default-config.php';
        $saved     = get_option( 'octowoo_config', [] );

        $db_config           = array_merge(
            $defaults['db'],
            is_array( $saved['db'] ?? null ) ? $saved['db'] : []
        );
        $db_config['source'] = $saved['source'] ?? 'remote';

        $connector = new \OctoWoo\Core\DatabaseConnector( $db_config );
        $error     = $connector->testConnection();

        if ( $error !== null ) {
            wp_send_json_error( [ 'message' => 'Cannot connect to source database: ' . $error ] );
            return;
        }

        try {
            $counts = $connector->scanSourceCounts();
            wp_send_json_success( [ 'counts' => $counts ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => 'Scan failed: ' . $e->getMessage() ] );
        }
    }

    // ── Action: purge imported data ───────────────────────────────────────────

    private function actionPurgeImported(): void {
        // Must not be currently running.
        if ( CheckpointManager::getActiveRunId() ) {
            wp_send_json_error( [
                'message' => __( 'Cannot purge while a migration is active. Abort it first.', 'octowoo' ),
            ] );
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $raw_entities = (array) ( $_POST['entities'] ?? [] );
        $allowed      = [ 'products', 'categories', 'tags', 'customers', 'orders', 'coupons', 'reviews', 'manufacturers', 'information', 'downloads', 'filters' ];
        $entities     = array_values( array_filter( $raw_entities, fn( $e ) => in_array( sanitize_key( $e ), $allowed, true ) ) );
        $entities     = array_map( 'sanitize_key', $entities );

        if ( empty( $entities ) ) {
            wp_send_json_error( [ 'message' => __( 'No entity types selected.', 'octowoo' ) ] );
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $force = filter_input( INPUT_POST, 'force', FILTER_VALIDATE_BOOLEAN ) === true;

        set_time_limit( 0 ); // Bulk deletion can take a while on large stores.

        $run_id = get_option( 'octowoo_last_run_id', 'purge-' . time() );
        $logger = new Logger( $run_id );
        $purger = new DataPurger( $logger );

        $results = $purger->purge( $entities, $force );

        $total = array_sum( $results );

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %d number of items deleted */
                __( 'Purge complete: %d item(s) deleted.', 'octowoo' ),
                $total
            ),
            'results' => $results,
        ] );
    }
}
