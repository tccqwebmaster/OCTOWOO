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

use OctoWoo\Core\BackgroundProcessor;
use OctoWoo\Core\CheckpointManager;
use OctoWoo\Core\DataPurger;
use OctoWoo\Core\DatabaseConnector;
use OctoWoo\Core\Logger;
use OctoWoo\Core\MigrationManager;
use OctoWoo\Core\MigrationReport;
use OctoWoo\Core\PreMigrationScanner;
use OctoWoo\Core\SqlImporter;
use OctoWoo\Core\Validator;

defined( 'ABSPATH' ) || exit;

class AjaxHandler {

    private const CAP   = 'manage_woocommerce';
    private const NONCE = 'octowoo_ajax';

    /**
     * Stale lock threshold in seconds (2 hours).
     * An active run whose last checkpoint update is older than this is
     * considered orphaned and auto-cleared on the next Start or chunk.
     */
    private const STALE_LOCK_SECONDS = 900; // 15 min without a heartbeat → assume dead

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
            'octowoo_drop_sql',
            'octowoo_prescan',
            'octowoo_get_report',
            // Premium additions.
            'octowoo_validate',
            'octowoo_start_background',
            'octowoo_cancel_background',
            'octowoo_pause_migration',
            'octowoo_resume_migration',
            'octowoo_skip_migrator',
            'octowoo_cleanup_ml_terms',
            'octowoo_repair_order_items',
            'octowoo_rerun_seo',
            // v2.4.70 additions.
            'octowoo_export_settings',
            'octowoo_import_settings',
            // v2.5.0 additions.
            'octowoo_run_cron_now',
            'octowoo_repair_categories',
        ];

        foreach ( $actions as $action ) {
            // Only for logged-in users (no nopriv variant intentionally).
            add_action( 'wp_ajax_' . $action, [ $this, 'dispatch' ] );
        }
    }

    // ── Central dispatcher ────────────────────────────────────────────────────

    public function dispatch(): void {
        // Security: capability + nonce.
        if ( ! ( current_user_can( self::CAP ) || current_user_can( 'manage_options' ) ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'octowoo' ) ], 403 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $action = sanitize_key( $_REQUEST['action'] ?? '' );

        // Verify nonce for all actions.
        if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
            // Send HTTP 200 so jQuery routes to .done(), not .fail(), giving the
            // user a readable error message rather than a bare "✘ error".
            wp_send_json_error( [ 'message' => __( 'Invalid security token. Please refresh the page and try again.', 'octowoo' ) ] );
        }

        // v2.4.72: Destructive actions require full Administrator role (manage_options).
        // WooCommerce shop managers (manage_woocommerce only) must not be able to
        // wipe data or reset migration state.
        $admin_only = [
            'octowoo_reset_migration',
            'octowoo_drop_sql',
        ];
        $is_force_purge = ( $action === 'octowoo_purge_imported' )
            && ! empty( $_POST['force'] ); // phpcs:ignore WordPress.Security.NonceVerification

        if ( ( in_array( $action, $admin_only, true ) || $is_force_purge )
             && ! current_user_can( 'manage_options' )
        ) {
            wp_send_json_error(
                [ 'message' => __( 'This action requires Administrator permissions.', 'octowoo' ) ],
                403
            );
        }

        // Lightweight request logging to aid debugging (non-blocking).
        // Skip read-only polling actions to avoid flooding logs with noise.
        $skip_log_actions = [ 'octowoo_get_progress', 'octowoo_get_logs' ];
        if ( ! in_array( $action, $skip_log_actions, true ) ) {
            try {
                $log_run = \OctoWoo\Core\CheckpointManager::getActiveRunId() ?? get_option( 'octowoo_last_run_id', 'no-run' );
                $logger = new \OctoWoo\Core\Logger( $log_run, AdminPage::getConfig()['logging'] ?? [] );
                $safe_req = $_REQUEST;
                // Mask common sensitive fields.
                $mask_keys = [ 'db_pass', 'password', 'db_password' ];
                $recursive_mask = function (&$arr) use (&$recursive_mask, $mask_keys) {
                    if ( ! is_array( $arr ) ) { return; }
                    foreach ( $arr as $k => &$v ) {
                        if ( in_array( strtolower( $k ), $mask_keys, true ) ) {
                            $v = '***';
                            continue;
                        }
                        if ( is_array( $v ) ) { $recursive_mask( $v ); }
                    }
                };
                $recursive_mask( $safe_req );
                $logger->info( 'AJAX dispatch: ' . $action, [ 'user' => get_current_user_id() ] );
                $logger->flush();
            } catch ( \Throwable $e ) {
                // Ignore logging failures — do not block AJAX.
            }
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

            case 'octowoo_drop_sql':
                $this->actionDropSql();
                break;

            case 'octowoo_prescan':
                $this->actionPrescan();
                break;

            case 'octowoo_get_report':
                $this->actionGetReport();
                break;

            case 'octowoo_validate':
                $this->actionValidate();
                break;

            case 'octowoo_start_background':
                $this->actionStartBackground();
                break;

            case 'octowoo_cancel_background':
                $this->actionCancelBackground();
                break;

            case 'octowoo_pause_migration':
                $this->actionPauseMigration();
                break;

            case 'octowoo_resume_migration':
                $this->actionResumeMigration();
                break;

            case 'octowoo_skip_migrator':
                $this->actionSkipMigrator();
                break;

            case 'octowoo_cleanup_ml_terms':
                $this->actionCleanupMlTerms();
                break;

            case 'octowoo_repair_order_items':
                $this->actionRepairOrderItems();
                break;

            case 'octowoo_rerun_seo':
                $this->actionRerunSeo();
                break;

            case 'octowoo_export_settings':
                $this->actionExportSettings();
                break;

            case 'octowoo_import_settings':
                $this->actionImportSettings();
                break;

            case 'octowoo_run_cron_now':
                $this->actionRunCronNow();
                break;

            case 'octowoo_repair_categories':
                $this->actionRepairCategories();
                break;
                wp_send_json_error( [ 'message' => 'Unknown action.' ], 400 );
        }
    }

    // ── Action: start / resume ────────────────────────────────────────────────

    private function actionStartMigration(): void {
        // Prevent parallel runs.
        $active_run = CheckpointManager::getActiveRunId();

        // Auto-clear stale lock:
        //  (a) Run ID exists but has no checkpoint rows (never actually started).
        //  (b) Run ID exists and checkpoints exist but the last update is older
        //      than STALE_LOCK_SECONDS (orphaned PHP process / crashed server).
        if ( $active_run ) {
            global $wpdb;
            $cp_table = $wpdb->prefix . 'octowoo_checkpoints';
            $has_data = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM `{$cp_table}` WHERE run_id = %s", $active_run ) // phpcs:ignore WordPress.DB.PreparedSQL
            );
            if ( $has_data === 0 ) {
                delete_option( 'octowoo_active_run_id' );
                $active_run = null;
            } else {
                $started_at = get_option( 'octowoo_run_started_at', '' );
                if ( $started_at && ( time() - strtotime( $started_at ) ) > self::STALE_LOCK_SECONDS ) {
                    delete_option( 'octowoo_active_run_id' );
                    $active_run = null;
                }
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $resume = filter_input( INPUT_POST, 'resume', FILTER_VALIDATE_BOOLEAN );

        if ( $active_run && ! $resume ) {
            // Auto-abort the stale run so Background-Scheduler-driven runs never
            // block the user from starting a fresh migration.
            BackgroundProcessor::abort( $active_run );
            MigrationManager::requestAbort( $active_run );
            global $wpdb;
            $cp_table_s = $wpdb->prefix . 'octowoo_checkpoints'; // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "UPDATE `{$cp_table_s}` SET status = 'aborted' WHERE run_id = %s AND status IN ('running','pending')", // phpcs:ignore WordPress.DB.PreparedSQL
                    $active_run
                )
            );
            $cp_stale_s = new CheckpointManager( $active_run );
            $cp_stale_s->markRunFinished();
            MigrationManager::clearRuntimeSignals( $active_run );
            $active_run = null;
        }

        // Resolve run ID: use existing if resuming, generate new otherwise.
        $run_id = $resume && $active_run ? $active_run : null;

        // phpcs:ignore WordPress.Security.NonceVerification
        $dry_run_raw = filter_input( INPUT_POST, 'dry_run', FILTER_UNSAFE_RAW );
        // phpcs:ignore WordPress.Security.NonceVerification
        $demo_limit_raw = filter_input( INPUT_POST, 'demo_limit', FILTER_VALIDATE_INT );
        // phpcs:ignore WordPress.Security.NonceVerification
        $clear_orders_raw = filter_input( INPUT_POST, 'clear_orders', FILTER_UNSAFE_RAW );

        // Build runtime overrides: dry-run flag + demo limit + per-migrator run flags.
        $overrides = [];
        if ( $dry_run_raw !== null ) {
            $overrides['migration']['dry_run'] = filter_var( $dry_run_raw, FILTER_VALIDATE_BOOLEAN );
        }
        if ( $demo_limit_raw !== null && $demo_limit_raw !== false ) {
            $overrides['migration']['demo_limit'] = max( 0, (int) $demo_limit_raw );
        }

        $should_clear_orders = $clear_orders_raw !== null
            ? filter_var( $clear_orders_raw, FILTER_VALIDATE_BOOLEAN )
            : false;
        if ( $should_clear_orders && ! $resume ) {
            $this->purgeOrdersBeforeRun();
        }

        // Clear image circuit-breaker so a fresh run always reattempts remote images.
        if ( ! $resume ) {
            delete_transient( 'octowoo_img_remote_down' );
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
        // Accept run_id from POST, fall back to the active one in DB.
        $raw_run_id = filter_input( INPUT_POST, 'run_id', FILTER_SANITIZE_SPECIAL_CHARS );
        $run_id = sanitize_text_field(
            ( $raw_run_id !== null && $raw_run_id !== '' )
                ? $raw_run_id
                : ( CheckpointManager::getActiveRunId() ?? '' )
        );

        if ( ! $run_id ) {
            wp_send_json_error( [ 'message' => __( 'No active migration to abort.', 'octowoo' ) ] );
        }

        // 1. Cancel any pending Action Scheduler background jobs for this run.
        //    Without this, AS fires the next queued batch ≈5 s after abort,
        //    which calls markRunActive() again and makes the banner re-appear.
        BackgroundProcessor::abort( $run_id );

        // 2. Signal any still-running synchronous PHP process to stop gracefully.
        MigrationManager::requestAbort( $run_id );

        // 3. Force-clear all checkpoint rows so the state is terminal.
        //    Covers the case where the PHP process has already died (AJAX timeout,
        //    server crash) and will never read the transient signal itself.
        global $wpdb;
        $cp_table = $wpdb->prefix . 'octowoo_checkpoints';
        $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->prepare(
                "UPDATE `{$cp_table}` SET status = 'aborted' WHERE run_id = %s AND status IN ('running','pending')", // phpcs:ignore WordPress.DB.PreparedSQL
                $run_id
            )
        );

        // 4. Release the lock option so the next Start isn't blocked.
        $cp = new CheckpointManager( $run_id );
        $cp->markRunFinished();
        MigrationManager::clearRuntimeSignals( $run_id );

        wp_send_json_success( [
            'message' => __( 'Migration aborted and lock cleared. You can start a new migration.', 'octowoo' ),
            'run_id'  => $run_id,
        ] );
    }

    private function actionPauseMigration(): void {
        $run_id = sanitize_text_field(
            filter_input( INPUT_POST, 'run_id', FILTER_SANITIZE_SPECIAL_CHARS )
                ?? CheckpointManager::getActiveRunId()
                ?? ''
        );

        if ( ! $run_id ) {
            wp_send_json_error( [ 'message' => __( 'No active migration to pause.', 'octowoo' ) ] );
        }

        MigrationManager::requestPause( $run_id );
        BackgroundProcessor::cancelPending( $run_id );

        wp_send_json_success( [
            'message' => __( 'Migration paused.', 'octowoo' ),
            'run_id'  => $run_id,
        ] );
    }

    private function actionResumeMigration(): void {
        // Accept explicit run_id from POST; fall back to active → last run so
        // an accidentally aborted migration can always be resumed.
        $raw = filter_input( INPUT_POST, 'run_id', FILTER_SANITIZE_SPECIAL_CHARS );
        $run_id = sanitize_text_field(
            ( $raw !== null && $raw !== '' )
                ? $raw
                : ( CheckpointManager::getActiveRunId()
                    ?? get_option( 'octowoo_last_run_id', '' ) )
        );

        if ( ! $run_id ) {
            wp_send_json_error( [ 'message' => __( 'No migration found to resume.', 'octowoo' ) ] );
        }

        // Clear any pause signal so the chunk loop re-enters immediately.
        MigrationManager::clearPause( $run_id );

        // Re-activate aborted migrators: restore their status to 'pending' while
        // preserving processed_count and last_oc_id so the chunk loop continues
        // from exactly where it left off — not from the beginning.
        // This is the key recovery step when the user accidentally clicks Abort.
        global $wpdb;
        $cp_table = $wpdb->prefix . 'octowoo_checkpoints';
        $reactivated = (int) $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "UPDATE `{$cp_table}` SET status = 'pending', updated_at = %s WHERE run_id = %s AND status = 'aborted'", // phpcs:ignore WordPress.DB.PreparedSQL
                current_time( 'mysql' ),
                $run_id
            )
        );

        // Re-register the run as active so the chunk loop and heartbeat work.
        $cp = new CheckpointManager( $run_id );
        $cp->markRunActive();

        $msg = $reactivated > 0
            ? sprintf(
                /* translators: number of migrators reactivated */
                __( 'Migration resumed (%d aborted migrator(s) reactivated — will continue from last checkpoint).', 'octowoo' ),
                $reactivated
            )
            : __( 'Migration resumed.', 'octowoo' );

        wp_send_json_success( [
            'message' => $msg,
            'run_id'  => $run_id,
        ] );
    }

    private function actionSkipMigrator(): void {
        $run_id = sanitize_text_field(
            filter_input( INPUT_POST, 'run_id', FILTER_SANITIZE_SPECIAL_CHARS )
                ?? CheckpointManager::getActiveRunId()
                ?? ''
        );

        if ( ! $run_id ) {
            wp_send_json_error( [ 'message' => __( 'No active migration found.', 'octowoo' ) ] );
        }

        $migrator = sanitize_key( (string) filter_input( INPUT_POST, 'migrator', FILTER_SANITIZE_SPECIAL_CHARS ) );

        if ( $migrator === '' ) {
            global $wpdb;
            $cp_table = $wpdb->prefix . 'octowoo_checkpoints';
            $migrator = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT migrator FROM `{$cp_table}` WHERE run_id = %s AND status IN ('running','pending') ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
                    $run_id
                )
            );
        }

        if ( $migrator === '' ) {
            wp_send_json_error( [ 'message' => __( 'No current migrator to skip.', 'octowoo' ) ] );
        }

        MigrationManager::requestSkipCurrentMigrator( $run_id, $migrator );

        wp_send_json_success( [
            'message'  => sprintf( __( 'Skip requested for %s. Next chunk will continue with the next entity.', 'octowoo' ), $migrator ),
            'run_id'   => $run_id,
            'migrator' => $migrator,
        ] );
    }

    // ── Action: get progress ──────────────────────────────────────────────────

    private function actionGetProgress(): void {
        $run_id = sanitize_text_field(
            filter_input( INPUT_GET, 'run_id', FILTER_SANITIZE_SPECIAL_CHARS ) ?? ''
        );

        // Fall back to the active run, then the last finished run.
        if ( ! $run_id ) {
            $run_id = CheckpointManager::getActiveRunId()
                ?? get_option( 'octowoo_last_run_id', '' );
        }

        if ( ! $run_id ) {
            wp_send_json_success( [ 'checkpoints' => [], 'active' => false ] );
        }

        $checkpoint = new CheckpointManager( $run_id );

        wp_send_json_success( [
            'run_id'      => $run_id,
            'active'      => CheckpointManager::getActiveRunId() === $run_id,
            'paused'      => MigrationManager::checkPaused( $run_id ),
            'checkpoints' => $checkpoint->getAll(),
            'started_at'  => get_option( 'octowoo_run_started_at', '' ),
        ] );
    }

    // ── Action: get logs ──────────────────────────────────────────────────────

    private function actionGetLogs(): void {
        $run_id = sanitize_text_field(
            filter_input( INPUT_GET, 'run_id', FILTER_SANITIZE_SPECIAL_CHARS ) ?? ''
        );

        if ( ! $run_id ) {
            $run_id = CheckpointManager::getActiveRunId()
                ?? get_option( 'octowoo_last_run_id', '' );
        }

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
        // Extend resource limits for chunk processing. A single chunk may sideload
        // media, run complex SQL joins, or process large product/order datasets.
        @ini_set( 'memory_limit', '512M' );

        // Override WP's AJAX wp_die handler so that if WP's own fatal-error shutdown
        // handler fires (e.g. on execution timeout or OOM), it returns parseable JSON
        // (HTTP 200) instead of an HTML error page. Without this, the JS chunk loop
        // receives an HTML 500 response it cannot parse and retries 3× before aborting.
        add_filter(
            'wp_die_ajax_handler',
            static function ( $default_handler ) {
                return static function ( $message, $title, $args ) use ( $default_handler ) {
                    $status = isset( $args['response'] ) ? (int) $args['response'] : 200;
                    if ( $status >= 500 ) {
                        // Extract human-readable text from any message type WP may pass.
                        if ( $message instanceof \WP_Error ) {
                            $text = $message->get_error_message();
                        } elseif ( is_string( $message ) && $message !== '' ) {
                            $text = wp_strip_all_tags( $message );
                        } elseif ( is_numeric( $message ) ) {
                            $text = 'wp_die code: ' . $message;
                        } else {
                            $text = '';
                        }

                        // Always try to enrich with PHP's last recorded error.
                        $last_err = error_get_last();
                        if ( $last_err && in_array( $last_err['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
                            $php_msg = 'PHP Fatal: ' . $last_err['message']
                                . ' in ' . basename( $last_err['file'] ) . ':' . $last_err['line'];
                            $text = $text !== '' ? $text . ' | ' . $php_msg : $php_msg;
                        }

                        if ( $text === '' ) {
                            $text = 'Fatal error (type: ' . gettype( $message ) . ') — check server error log.';
                        }

                        // Persist the real error to the OctoWoo Logs tab.
                        try {
                            $run    = \OctoWoo\Core\CheckpointManager::getActiveRunId()
                                    ?? (string) get_option( 'octowoo_last_run_id', 'fatal' );
                            $logger = new \OctoWoo\Core\Logger( $run );
                            $logger->error( 'wp_die (HTTP 500): ' . $text );
                            $logger->flush();
                        } catch ( \Throwable $ignore ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
                        }

                        if ( ! headers_sent() ) {
                            header( 'Content-Type: application/json; charset=utf-8' );
                        }
                        echo wp_json_encode( [
                            'success' => false,
                            'data'    => [
                                'message' => $text,
                                'fatal'   => true,
                            ],
                        ] );
                        die();
                    }
                    call_user_func( $default_handler, $message, $title, $args );
                };
            },
            PHP_INT_MAX
        );

        // Register a shutdown handler to capture fatal errors that bypass try/catch
        // (e.g. memory exhaustion, class-not-found, parse errors in loaded files).
        register_shutdown_function( function (): void {
            $error = error_get_last();
            if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
                // If headers already sent (normal JSON response), don't interfere.
                if ( headers_sent() ) {
                    return;
                }
                // Log the fatal error to OctoWoo's own log table.
                try {
                    $run = \OctoWoo\Core\CheckpointManager::getActiveRunId() ?? get_option( 'octowoo_last_run_id', 'fatal' );
                    $logger = new \OctoWoo\Core\Logger( $run );
                    $logger->error( 'PHP Fatal in chunk: ' . $error['message'], [
                        'file' => $error['file'],
                        'line' => $error['line'],
                        'type' => $error['type'],
                    ] );
                    $logger->flush();
                } catch ( \Throwable $ignore ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
                }
                // Return a JSON error so the JS retry logic sees a meaningful message.
                http_response_code( 500 );
                header( 'Content-Type: application/json; charset=utf-8' );
                echo wp_json_encode( [
                    'success' => false,
                    'data'    => [
                        'message' => 'PHP Fatal: ' . $error['message']
                            . ' in ' . basename( $error['file'] ) . ':' . $error['line'],
                        'fatal'   => true,
                    ],
                ] );
            }
        } );

        // phpcs:ignore WordPress.Security.NonceVerification
        $run_id   = sanitize_text_field( (string) filter_input( INPUT_POST, 'run_id', FILTER_SANITIZE_SPECIAL_CHARS ) );
        // phpcs:ignore WordPress.Security.NonceVerification
        $resume   = filter_input( INPUT_POST, 'resume', FILTER_VALIDATE_BOOLEAN );
        // phpcs:ignore WordPress.Security.NonceVerification
        $dry_run_raw  = filter_input( INPUT_POST, 'dry_run', FILTER_UNSAFE_RAW );
        // phpcs:ignore WordPress.Security.NonceVerification
        $migrators_raw = sanitize_text_field( (string) filter_input( INPUT_POST, 'migrators', FILTER_SANITIZE_SPECIAL_CHARS ) );
        // phpcs:ignore WordPress.Security.NonceVerification
        $clear_orders_raw = filter_input( INPUT_POST, 'clear_orders', FILTER_UNSAFE_RAW );

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
            } else {
                $started_at = get_option( 'octowoo_run_started_at', '' );
                if ( $started_at && ( time() - strtotime( $started_at ) ) > self::STALE_LOCK_SECONDS ) {
                    delete_option( 'octowoo_active_run_id' );
                    $active_run = null;
                }
            }
        }

        // If a run_id was sent, treat this as a resume of that specific run.
        // If empty, it's a fresh start.  Auto-abort any stale lock so recovery
        // buttons never block on a crashed/orphaned or Background-Scheduler run.
        // A truly live run always sends its run_id, so this only fires for stale ones.
        if ( $run_id === '' ) {
            if ( $active_run ) {
                // Force-abort the stale run in-line (same logic as actionAbortMigration).
                global $wpdb;
                $cp_table = $cp_table ?? $wpdb->prefix . 'octowoo_checkpoints'; // phpcs:ignore WordPress.DB.PreparedSQL
                BackgroundProcessor::abort( $active_run );
                MigrationManager::requestAbort( $active_run );
                $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
                    $wpdb->prepare(
                        "UPDATE `{$cp_table}` SET status = 'aborted' WHERE run_id = %s AND status IN ('running','pending')", // phpcs:ignore WordPress.DB.PreparedSQL
                        $active_run
                    )
                );
                $cp_stale = new CheckpointManager( $active_run );
                $cp_stale->markRunFinished();
                MigrationManager::clearRuntimeSignals( $active_run );
            }
            $run_id = null; // let MigrationManager generate a fresh ID
        }

        $should_clear_orders = $clear_orders_raw !== null
            ? filter_var( $clear_orders_raw, FILTER_VALIDATE_BOOLEAN )
            : false;
        if ( $should_clear_orders && $run_id === null ) {
            $this->purgeOrdersBeforeRun();
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $demo_limit_chunk = filter_input( INPUT_POST, 'demo_limit', FILTER_VALIDATE_INT );

        // phpcs:ignore WordPress.Security.NonceVerification
        $on_duplicate_raw = filter_input( INPUT_POST, 'on_duplicate', FILTER_SANITIZE_SPECIAL_CHARS );

        $overrides = [];
        if ( $dry_run_raw !== null ) {
            $overrides['migration']['dry_run'] = filter_var( $dry_run_raw, FILTER_VALIDATE_BOOLEAN );
        }
        if ( $demo_limit_chunk !== null && $demo_limit_chunk !== false ) {
            $overrides['migration']['demo_limit'] = max( 0, (int) $demo_limit_chunk );
        }
        // on_duplicate sent live from the UI dropdown — takes precedence over saved settings.
        if ( $on_duplicate_raw !== null && in_array( $on_duplicate_raw, [ 'skip', 'update' ], true ) ) {
            $overrides['migration']['on_duplicate'] = $on_duplicate_raw;
        }
        if ( $migrators_raw !== '' ) {
            $allowed    = [ 'tax', 'order_statuses', 'categories', 'manufacturers', 'images', 'products', 'related', 'bundles', 'customers', 'orders', 'coupons', 'seo', 'information', 'tags', 'filters', 'downloads', 'reviews', 'multilingual' ];
            $selected   = array_filter( explode( ',', $migrators_raw ), fn( $k ) => in_array( $k, $allowed, true ) );
            // Respect saved Settings: a migrator disabled there can never be re-enabled
            // by the Migration-tab UI checkboxes alone — the user must save Settings first.
            $saved_cfg  = AdminPage::getConfig();
            $saved_mig  = is_array( $saved_cfg['migration'] ?? null ) ? $saved_cfg['migration'] : [];
            foreach ( $allowed as $key ) {
                $in_ui      = in_array( $key, $selected, true );
                $in_settings = $key === 'multilingual'
                    ? ! empty( $saved_cfg['multilingual']['enabled'] )
                    : ( isset( $saved_mig[ 'run_' . $key ] ) ? (bool) $saved_mig[ 'run_' . $key ] : true );
                $overrides['migration'][ 'run_' . $key ] = $in_ui && $in_settings;
            }
        }

        try {
            set_time_limit( 300 ); // One chunk = at most 5 min (image sideloads can be slow).
            $manager = new MigrationManager( $overrides, $run_id ?: null );
            $result  = $manager->runNextChunk();

            // Another request is already processing this run — tell JS to retry shortly.
            if ( ! empty( $result['busy'] ) ) {
                wp_send_json_success( [
                    'run_id'      => $manager->getRunId(),
                    'done_all'    => false,
                    'busy'        => true,
                    'migrator'    => '',
                    'checkpoints' => [],
                    'report'      => null,
                ] );
                return;
            }

            wp_send_json_success( [
                'run_id'      => $manager->getRunId(),
                'done_all'    => $result['done_all'],
                'aborted'     => $result['aborted'] ?? false,
                'paused'      => $result['paused'] ?? false,
                'skipped'     => $result['skipped'] ?? false,
                'migrator'    => $result['migrator'],
                'checkpoints' => $result['checkpoints'],
                'report'      => $result['report'],
            ] );
        } catch ( \RuntimeException $e ) {
            // RuntimeException from DatabaseConnector means the OC database is unreachable.
            wp_send_json_error( [
                'message'   => __( 'Cannot connect to OpenCart database – check your Settings tab. ', 'octowoo' ) . $e->getMessage(),
                'exception' => get_class( $e ),
                'db_error'  => true,
            ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [
                'message'   => __( 'Chunk failed: ', 'octowoo' ) . $e->getMessage(),
                'exception' => get_class( $e ),
            ] );
        }
    }

    // ── Action: pre-migration scan ────────────────────────────────────────────

    /**
     * Run the pre-migration scanner and return potential issues + source counts.
     * Safe to call multiple times — read-only against both databases.
     */
    private function actionPrescan(): void {
        $config    = AdminPage::getConfig();
        $db_config = $config['db'];
        $db_config['source'] = $config['source'] ?? 'remote';

        try {
            $db      = new \OctoWoo\Core\DatabaseConnector( $db_config );
            $db->connect();
            $scanner = new \OctoWoo\Core\PreMigrationScanner( $db, $config );
            $scan = $scanner->scan();

            // Augment scan with detected images dir (if present) so the
            // admin Auto-detect button can populate the Settings field.
            try {
                $img_dir = \OctoWoo\Core\SqlImporter::getImagesDir();
                if ( is_dir( $img_dir ) && count( glob( $img_dir . '*' ) ) > 0 ) {
                    $scan['images'] = $scan['images'] ?? [];
                    $scan['images']['detected_path'] = $img_dir;
                    $scan['images']['detected_count'] = count( glob( $img_dir . '*' ) );
                }
            } catch ( \Throwable $e ) {
                // ignore
            }

            // Provide logs directory status.
            $log_dir = rtrim( OCTOWOO_LOG_DIR, '/\\' ) . DIRECTORY_SEPARATOR;
            $logs    = [ 'path' => $log_dir, 'writable' => is_writable( $log_dir ) ];
            $scan['logs'] = $logs;

            wp_send_json_success( $scan );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    // ── Action: get last report ───────────────────────────────────────────────

    /**
     * Return the most recently saved migration report from wp_options.
     */
    private function actionGetReport(): void {
        wp_send_json_success( \OctoWoo\Core\MigrationReport::load() );
    }

    /**
     * Delete orphaned / duplicate WPML translation terms (placeholder and retry
     * duplicates created by WpmlIntegration during failed chunk runs).
     */
    private function actionCleanupMlTerms(): void {
        $logger  = new Logger( 'cleanup-ml', AdminPage::getConfig()['logging'] ?? [] );
        $purger  = new DataPurger( $logger, AdminPage::getConfig() );
        $deleted = $purger->purgeOrphanTranslationTerms();
        $logger->flush();
        wp_send_json_success( [
            'deleted' => $deleted,
            'message' => sprintf(
                /* translators: %d = number of terms deleted */
                __( 'Cleanup complete. %d orphan translation term(s) removed.', 'octowoo' ),
                $deleted
            ),
        ] );
    }

    /**
     * Repair order-item → product links for all OctoWoo-migrated orders.
     *
     * Processes orders in batches. Each AJAX call handles one batch and returns
     * done=false until the last batch, at which point done=true is returned and
     * the page transient is cleared so the next button click starts fresh.
     */
    private function actionRepairOrderItems(): void {
        global $wpdb;

        $config     = AdminPage::getConfig();
        $batch_size = max( 20, min( 100, (int) ( $config['migration']['batch_size'] ?? 20 ) * 2 ) );

        // Support fresh start vs. continuation: JS sends reset=1 on first click.
        if ( ! empty( $_POST['reset'] ) ) {
            delete_transient( 'octowoo_repair_order_page' );
        }

        $page = max( 1, (int) ( get_transient( 'octowoo_repair_order_page' ) ?: 1 ) );

        /** @var int[] $order_ids */
        $order_ids = wc_get_orders( [
            'meta_key'     => '_octowoo_oc_order_id',
            'meta_compare' => 'EXISTS',
            'limit'        => $batch_size,
            'paged'        => $page,
            'return'       => 'ids',
            'type'         => 'shop_order',
        ] );

        if ( empty( $order_ids ) ) {
            delete_transient( 'octowoo_repair_order_page' );
            wp_send_json_success( [
                'done'      => true,
                'relinked'  => 0,
                'processed' => ( $page - 1 ) * $batch_size,
                'message'   => __( 'All migrated orders scanned — no more items to process.', 'octowoo' ),
            ] );
            return;
        }

        $run_id = get_option( 'octowoo_last_run_id', 'repair' );
        $logger = new Logger( $run_id, $config['logging'] ?? [] );
        $chk    = new CheckpointManager( $run_id );

        $relinked = 0;

        foreach ( $order_ids as $wc_order_id ) {
            $order = wc_get_order( (int) $wc_order_id );
            if ( ! $order ) {
                continue;
            }

            foreach ( $order->get_items() as $item_id => $item ) {
                if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
                    continue;
                }

                $oc_prod  = (int) $item->get_meta( '_octowoo_oc_product_id', true );
                $oc_model = trim( (string) $item->get_meta( '_octowoo_oc_product_model', true ) );

                if ( $oc_prod <= 0 ) {
                    continue; // Not tracked by OctoWoo.
                }

                // Fast path: id_map lookup.
                $wc_id   = $chk->getWcId( 'product', $oc_prod );
                $product = $wc_id ? wc_get_product( $wc_id ) : null;

                // SKU / model fallback.
                if ( ( ! $product || ! $product->get_id() ) && $oc_model !== '' ) {
                    $found_id = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT pm.post_id
                             FROM {$wpdb->postmeta} pm
                             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                             WHERE pm.meta_key   = '_sku'
                               AND pm.meta_value = %s
                               AND p.post_type   IN ('product','product_variation')
                               AND p.post_status != 'trash'
                             LIMIT 1",
                            $oc_model
                        )
                    );
                    if ( $found_id > 0 ) {
                        $product = wc_get_product( $found_id );
                        if ( $product ) {
                            $chk->saveIdMap( 'product', $oc_prod, $found_id );
                        }
                    }
                }

                if ( ! $product || ! $product->get_id() ) {
                    continue;
                }

                $new_id = $product->get_id();
                $old_id = (int) $item->get_product_id();

                if ( $new_id === $old_id ) {
                    continue; // Already correct.
                }

                wc_update_order_item_meta( (int) $item_id, '_product_id',   $new_id );
                wc_update_order_item_meta( (int) $item_id, '_variation_id', 0 );
                $relinked++;

                $logger->debug( "Repaired order #{$wc_order_id} item #{$item_id}: _product_id {$old_id} → {$new_id}." );
            }
        }

        // Advance or close the transient.
        $is_last = count( $order_ids ) < $batch_size;
        if ( $is_last ) {
            delete_transient( 'octowoo_repair_order_page' );
        } else {
            set_transient( 'octowoo_repair_order_page', $page + 1, HOUR_IN_SECONDS );
        }

        $logger->flush();

        wp_send_json_success( [
            'done'      => $is_last,
            'relinked'  => $relinked,
            'processed' => $page * $batch_size,
            /* translators: 1: batch number 2: items re-linked */
            'message'   => sprintf(
                __( 'Batch %1$d complete. Re-linked %2$d order item(s) in this batch.', 'octowoo' ),
                $page,
                $relinked
            ),
        ] );
    }

    /**
     * Reset the SEO migrator checkpoint and clear stored redirect maps so
     * the SEO pass can be re-run without doing a full "Reset Progress".
     *
     * After this action the user clicks "Resume" and the migration loop will
     * find only the SEO checkpoint as pending (all others remain completed)
     * and re-run it — rebuilding all product/category slugs and redirects.
     */
    private function actionRerunSeo(): void {
        // Refuse if a migration is currently running.
        if ( CheckpointManager::getActiveRunId() ) {
            wp_send_json_error( [
                'message' => __( 'A migration is currently running. Please wait for it to finish or abort it first.', 'octowoo' ),
            ] );
            return;
        }

        $run_id = get_option( 'octowoo_last_run_id', '' );

        if ( ! $run_id ) {
            wp_send_json_error( [
                'message' => __( 'No previous migration found. Run a full migration first.', 'octowoo' ),
            ] );
            return;
        }

        // Warn if WordPress permalink structure is still "Plain".
        $permalink_plain = empty( get_option( 'permalink_structure' ) );

        // Reset SEO checkpoint: sets status → pending, last_oc_id → 0, processed_count → 0.
        $chk = new CheckpointManager( $run_id );
        $chk->init( 'seo', 0 );

        // Clear previously stored redirect maps — they may contain wrong query-string targets.
        delete_option( 'octowoo_redirects' );

        // Re-activate the run so "Resume" works immediately.
        $chk->markRunActive();

        $message = __( 'SEO checkpoint reset and redirect map cleared. Click "Resume" to re-run the SEO migrator.', 'octowoo' );

        if ( $permalink_plain ) {
            $message .= ' ' . __( 'WARNING: WordPress permalink structure is still "Plain". Go to Settings → Permalinks → Post name → Save Changes BEFORE resuming, otherwise URLs will still be wrong.', 'octowoo' );
        }

        wp_send_json_success( [
            'message'         => $message,
            'run_id'          => $run_id,
            'permalink_plain' => $permalink_plain,
        ] );
    }

    /**
     * Purge WooCommerce orders before starting a new migration run.
     */
    private function purgeOrdersBeforeRun(): void {
        $log_run = CheckpointManager::getActiveRunId()
            ?? get_option( 'octowoo_last_run_id', 'pre-run' );

        $logger = new Logger( (string) $log_run, AdminPage::getConfig()['logging'] ?? [] );
        $purger = new DataPurger( $logger );

        $logger->info( '[pre-run] Clearing WooCommerce orders before migration start.' );
        $purger->purge( [ 'orders' ], true );
        $logger->info( '[pre-run] WooCommerce orders cleared.' );
        $logger->flush();
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

            // Persist import metadata so the Settings page can show an 'already imported' status.
            $table_count = SqlImporter::getImportedInfo()['tables'];
            update_option( 'octowoo_sql_import_meta', [
                'filename'    => $name,
                'imported_at' => current_time( 'mysql' ),
                'prefix'      => $prefix,
                'tables'      => $table_count,
            ], false );

            wp_send_json_success( [
                'message'  => $last['message'] ?? 'Import complete.',
                'tables'   => $table_count,
                'filename' => $name,
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

        $cp_table  = $wpdb->prefix . 'octowoo_checkpoints';
        $map_table = $wpdb->prefix . 'octowoo_id_map';

        // If a migration appears to be active, force-abort it first rather than
        // hard-blocking the reset. This handles stale locks (crash, orphaned
        // Background jobs) so the user is never stuck.
        $active_run = CheckpointManager::getActiveRunId();
        if ( $active_run ) {
            BackgroundProcessor::abort( $active_run );
            MigrationManager::requestAbort( $active_run );
            $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL
                $wpdb->prepare(
                    "UPDATE `{$cp_table}` SET status = 'aborted' WHERE run_id = %s AND status IN ('running','pending')", // phpcs:ignore WordPress.DB.PreparedSQL
                    $active_run
                )
            );
            $cp = new CheckpointManager( $active_run );
            $cp->markRunFinished();
            MigrationManager::clearRuntimeSignals( $active_run );
        }

        // Delete ALL checkpoint rows for every known run.
        $run_ids = array_filter( array_unique( [
            $active_run ?? '',
            get_option( 'octowoo_last_run_id', '' ),
        ] ) );

        foreach ( $run_ids as $rid ) {
            $wpdb->delete( $cp_table, [ 'run_id' => $rid ], [ '%s' ] );
        }

        // Truncate the entire id_map — it only stores OC→WC ID cross-references
        // and the run_id filter would miss rows from older runs with different IDs.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query( "TRUNCATE TABLE `{$map_table}`" );

        // Clean up all migration state options.
        delete_option( 'octowoo_last_run_id' );
        delete_option( 'octowoo_last_run_at' );
        delete_option( 'octowoo_active_run_id' );
        delete_option( 'octowoo_run_started_at' );
        delete_option( 'octowoo_db_lock' );

        // Invalidate category transients (both old and new key names) so the
        // next run re-builds the sorted list from scratch.
        $reset_config = AdminPage::getConfig();
        $oc_pfx       = $reset_config['db']['prefix'] ?? 'oc_';
        delete_transient( 'octowoo_cat_topo_' . md5( $oc_pfx ) ); // legacy key
        delete_transient( 'octowoo_cat_all_'  . md5( $oc_pfx ) ); // current key

        wp_send_json_success( [
            'message' => __( 'Migration data reset. You can start a fresh migration.', 'octowoo' ),
        ] );
    }

    // ── Action: pre-migration system validator ─────────────────────────────────

    private function actionValidate(): void {
        $defaults = require OCTOWOO_PLUGIN_DIR . 'config/default-config.php';
        $saved    = get_option( 'octowoo_config', [] );
        $config   = array_replace_recursive( $defaults, $saved );

        $validator = new Validator( $config );
        $results   = $validator->run();

        wp_send_json_success( [
            'results'     => $results,
            'all_passed'  => $validator->allPassed( $results ),
            'has_warnings'=> $validator->hasWarningsOnly( $results ),
            'as_available'=> BackgroundProcessor::isAvailable(),
        ] );
    }

    // ── Action: start background migration (Action Scheduler) ─────────────────

    private function actionStartBackground(): void {
        if ( ! BackgroundProcessor::isAvailable() ) {
            wp_send_json_error( [
                'message' => __( 'Background mode requires WooCommerce 4.0+ (Action Scheduler).', 'octowoo' ),
            ] );
        }

        $active_run = CheckpointManager::getActiveRunId();
        // phpcs:ignore WordPress.Security.NonceVerification
        $resume     = filter_input( INPUT_POST, 'resume', FILTER_VALIDATE_BOOLEAN );

        if ( $active_run && ! $resume ) {
            wp_send_json_error( [
                'message' => __( 'A migration is already active. Abort it first or use Resume.', 'octowoo' ),
                'run_id'  => $active_run,
            ] );
        }

        $run_id = $resume && $active_run ? $active_run : null;

        if ( $run_id ) {
            MigrationManager::clearPause( $run_id );
        }

        $overrides = [];
        // phpcs:ignore WordPress.Security.NonceVerification
        $dry_run_bg_raw = filter_input( INPUT_POST, 'dry_run', FILTER_UNSAFE_RAW );
        if ( $dry_run_bg_raw !== null ) {
            $overrides['migration']['dry_run'] = filter_var( $dry_run_bg_raw, FILTER_VALIDATE_BOOLEAN );
        }
        // phpcs:ignore WordPress.Security.NonceVerification
        $demo_limit = filter_input( INPUT_POST, 'demo_limit', FILTER_VALIDATE_INT );
        if ( $demo_limit !== null && $demo_limit !== false ) {
            $overrides['migration']['demo_limit'] = max( 0, (int) $demo_limit );
        }
        // phpcs:ignore WordPress.Security.NonceVerification
        $migrators_raw = sanitize_text_field( (string) filter_input( INPUT_POST, 'migrators', FILTER_SANITIZE_SPECIAL_CHARS ) );
        // phpcs:ignore WordPress.Security.NonceVerification
        $clear_orders_bg_raw = filter_input( INPUT_POST, 'clear_orders', FILTER_UNSAFE_RAW );
        if ( $migrators_raw !== '' ) {
            $allowed    = [ 'tax', 'order_statuses', 'categories', 'manufacturers', 'images', 'products', 'related', 'bundles', 'customers', 'orders', 'coupons', 'seo', 'information', 'tags', 'filters', 'downloads', 'reviews', 'multilingual' ];
            $selected   = array_filter( explode( ',', $migrators_raw ), fn( $k ) => in_array( $k, $allowed, true ) );
            // Respect saved Settings: a migrator disabled there can never be re-enabled
            // by the Migration-tab UI checkboxes alone — the user must save Settings first.
            $saved_cfg  = AdminPage::getConfig();
            $saved_mig  = is_array( $saved_cfg['migration'] ?? null ) ? $saved_cfg['migration'] : [];
            foreach ( $allowed as $key ) {
                $in_ui       = in_array( $key, $selected, true );
                $in_settings = $key === 'multilingual'
                    ? ! empty( $saved_cfg['multilingual']['enabled'] )
                    : ( isset( $saved_mig[ 'run_' . $key ] ) ? (bool) $saved_mig[ 'run_' . $key ] : true );
                $overrides['migration'][ 'run_' . $key ] = $in_ui && $in_settings;
            }
        }

        $should_clear_orders = $clear_orders_bg_raw !== null
            ? filter_var( $clear_orders_bg_raw, FILTER_VALIDATE_BOOLEAN )
            : false;
        if ( $should_clear_orders && ! $resume ) {
            $this->purgeOrdersBeforeRun();
        }

        try {
            $enqueued_run_id = BackgroundProcessor::enqueue( $overrides, $run_id );
            wp_send_json_success( [
                'message' => __( 'Background migration queued. Progress will update automatically.', 'octowoo' ),
                'run_id'  => $enqueued_run_id,
                'mode'    => 'background',
            ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    // ── Action: cancel background migration ──────────────────────────────────

    private function actionCancelBackground(): void {
        $run_id = sanitize_text_field(
            filter_input( INPUT_POST, 'run_id', FILTER_SANITIZE_SPECIAL_CHARS )
                ?? CheckpointManager::getActiveRunId()
                ?? ''
        );

        if ( ! $run_id ) {
            wp_send_json_error( [ 'message' => __( 'No active migration run found.', 'octowoo' ) ] );
        }

        BackgroundProcessor::abort( $run_id );

        // Also update DB checkpoints so the UI reflects the aborted state.
        global $wpdb;
        $cp_table = $wpdb->prefix . 'octowoo_checkpoints';
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$cp_table}` SET status = 'aborted' WHERE run_id = %s AND status IN ('running','pending')", // phpcs:ignore WordPress.DB.PreparedSQL
                $run_id
            )
        );

        $cp = new CheckpointManager( $run_id );
        $cp->markRunFinished();
        MigrationManager::clearRuntimeSignals( $run_id );

        wp_send_json_success( [
            'message' => __( 'Background migration cancelled.', 'octowoo' ),
            'run_id'  => $run_id,
        ] );
    }

    // ── Action: drop imported SQL tables ─────────────────────────────────────

    private function actionDropSql(): void {
        if ( CheckpointManager::getActiveRunId() ) {
            wp_send_json_error( [ 'message' => __( 'Cannot drop tables while a migration is active. Abort it first.', 'octowoo' ) ] );
            return;
        }

        $importer = new SqlImporter();
        $importer->dropImportedTables();
        delete_option( 'octowoo_sql_import_meta' );

        // Switch source back to remote since local tables are gone.
        $config = get_option( 'octowoo_config', [] );
        if ( ( $config['source'] ?? '' ) === 'local' ) {
            $config['source'] = 'remote';
            update_option( 'octowoo_config', $config, false );
        }

        wp_send_json_success( [ 'message' => __( 'Imported tables dropped. Source mode reset to Remote.', 'octowoo' ) ] );
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
        // If there is a genuinely active migration (not a stale lock), block the purge
        // to avoid deleting data that's currently being written.
        // getActiveRunId() is now self-healing: stale locks return null automatically.
        if ( CheckpointManager::getActiveRunId() ) {
            wp_send_json_error( [
                'message' => __( 'Cannot purge while a migration is actively running. Abort it first.', 'octowoo' ),
            ] );
        }

        // Prevent concurrent purge operations — two simultaneous AJAX requests can
        // interleave SQL deletions, produce corrupted log output, and leave orphaned
        // WPML translation terms that appear as duplicate categories after the next
        // migration run.
        $lock_key = 'octowoo_purge_in_progress';
        if ( get_transient( $lock_key ) ) {
            wp_send_json_error( [
                'message' => __( 'A purge operation is already running. Please wait for it to complete before starting another.', 'octowoo' ),
            ] );
        }
        set_transient( $lock_key, 1, 600 ); // 10-minute safety TTL to self-heal stale locks.
        // Always release the lock when this request ends — including after wp_die() / exit.
        register_shutdown_function( function () use ( $lock_key ) {
            delete_transient( $lock_key );
        } );

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

        $config = AdminPage::getConfig();
        $run_id = get_option( 'octowoo_last_run_id', 'purge-' . time() );
        $logger = new Logger( $run_id, $config['logging'] ?? [] );
        $purger = new DataPurger( $logger, $config );

        try {
            $results = $purger->purge( $entities, $force );
        } catch ( \Throwable $e ) {
            $logger->error( '[purge] Unexpected exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            wp_send_json_error( [
                'message' => sprintf(
                    /* translators: error message */
                    __( 'Purge failed with an unexpected error: %s', 'octowoo' ),
                    $e->getMessage()
                ),
            ] );
        }

        // purge() now returns [ 'results' => [...], 'diagnostics' => [...] ]
        $breakdown   = $results['results']     ?? $results; // BC-safe if array is flat
        $diagnostics = $results['diagnostics'] ?? [];

        // Sum only integer results; string values are per-entity error messages.
        $total        = 0;
        $entity_errors = [];
        foreach ( $breakdown as $entity => $val ) {
            if ( is_int( $val ) ) {
                $total += $val;
            } else {
                $entity_errors[] = $entity . ': ' . $val;
            }
        }

        // Build human-readable hints for entities where 0 were deleted but
        // WC items do exist (id_map has been reset / meta was never saved).
        $hints = [];
        foreach ( $diagnostics as $entity => $counts ) {
            if ( $counts['total'] > 0 && $counts['tagged'] === 0 ) {
                /* translators: 1: entity name, 2: total WC count */
                $hints[] = sprintf(
                    __( '%1$s: %2$d item(s) exist in WooCommerce but have no OctoWoo tag (id-map was reset or meta was never saved). Enable ☢ Force Purge to remove them.', 'octowoo' ),
                    $entity,
                    $counts['total']
                );
            }
        }
        foreach ( $entity_errors as $err ) {
            $hints[] = '⚠ Entity error — ' . $err . ' (check OctoWoo log for details)';
        }

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %d number of items deleted */
                __( 'Purge complete: %d item(s) deleted.', 'octowoo' ),
                $total
            ),
            'results'  => $breakdown,
            'hints'    => $hints,
        ] );
    }

    // ── Action: export settings as JSON ──────────────────────────────────────────

    private function actionExportSettings(): void {
        $defaults = require OCTOWOO_PLUGIN_DIR . 'config/default-config.php';
        $saved    = get_option( 'octowoo_config', [] );
        $config   = array_replace_recursive( $defaults, $saved );

        // Strip the encrypted DB password — it cannot be used on another site.
        if ( isset( $config['db']['password'] ) ) {
            $config['db']['password'] = '';
        }

        wp_send_json_success( [
            'config'  => $config,
            'version' => OCTOWOO_VERSION,
        ] );
    }

    // ── Action: import settings from JSON ─────────────────────────────────────

    private function actionImportSettings(): void {
        $raw = isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! $raw ) {
            wp_send_json_error( [ 'message' => __( 'No config data received.', 'octowoo' ) ] );
        }

        $config = json_decode( (string) $raw, true );
        if ( ! is_array( $config ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid JSON — could not parse settings file.', 'octowoo' ) ] );
        }

        // Only allow expected top-level keys.
        $allowed = [ 'source', 'db', 'opencart', 'migration', 'seo', 'multilingual', 'cron', 'woocommerce', 'logging' ];
        $filtered = array_intersect_key( $config, array_flip( $allowed ) );

        if ( empty( $filtered ) ) {
            wp_send_json_error( [ 'message' => __( 'File does not appear to be a valid OctoWoo settings export.', 'octowoo' ) ] );
        }

        // Keep the existing (encrypted) DB password if the export stripped it.
        $existing = get_option( 'octowoo_config', [] );
        if ( ! empty( $existing['db']['password'] ) && empty( $filtered['db']['password'] ) ) {
            $filtered['db']['password'] = $existing['db']['password'];
        }

        update_option( 'octowoo_config', $filtered );

        wp_send_json_success( [ 'message' => __( 'Settings imported successfully.', 'octowoo' ) ] );
    }


    // ── Action: run cron migration now ───────────────────────────────────────

    private function actionRunCronNow(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Administrator permission required.', 'octowoo' ) ], 403 );
        }

        $active = \OctoWoo\Core\CheckpointManager::getActiveRunId();
        if ( $active ) {
            wp_send_json_error( [
                'message' => __( 'A migration is already running. Stop it before triggering a cron run.', 'octowoo' ),
            ] );
        }

        \OctoWoo\Core\CronManager::runNow();

        wp_send_json_success( [
            'message' => __( 'Cron migration triggered. Check the Logs tab for progress.', 'octowoo' ),
        ] );
    }


    // ── Action: repair product→category assignments ───────────────────────────

    /**
     * Re-assigns WooCommerce product categories for ALL products imported by OctoWoo.
     *
     * This fixes the common problem where categories were migrated AFTER products
     * (wrong order), leaving products uncategorised. Safe to run multiple times.
     * Uses the octowoo_id_map table to resolve OC category IDs → WC term IDs,
     * with a DB meta fallback for terms created before the map was built.
     *
     * Works in pages of 50 products; returns done=false until all are processed.
     */
    private function actionRepairCategories(): void {
        global $wpdb;

        $page  = max( 1, (int) filter_input( INPUT_POST, 'page', FILTER_VALIDATE_INT ) );
        $limit = 50;
        $offset = ( $page - 1 ) * $limit;

        // Fetch products with their OC IDs.
        $products = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT p.ID AS post_id, pm.meta_value AS oc_id
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_octowoo_oc_id'
             WHERE p.post_type IN ('product','product_variation')
               AND p.post_status != 'trash'
             ORDER BY p.ID ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A );

        if ( empty( $products ) ) {
            wp_send_json_success( [ 'done' => true, 'repaired' => 0, 'page' => $page ] );
        }

        $total_repaired = 0;

        foreach ( $products as $prod ) {
            $post_id   = (int) $prod['post_id'];
            $oc_prod_id = (int) $prod['oc_id'];

            // Fetch OC product-to-category assignments.
            $oc_cat_ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "SELECT category_id FROM {$wpdb->prefix}octowoo_id_map
                 WHERE entity_type = 'oc_product_category' AND oc_id = %d",
                $oc_prod_id
            ) );

            // If not in id_map, try oc_product_to_category directly (local import or remote).
            if ( empty( $oc_cat_ids ) ) {
                // Skip if we can't determine OC categories.
                continue;
            }

            $wc_term_ids = [];
            foreach ( $oc_cat_ids as $oc_cat_id ) {
                $wc_term_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "SELECT wc_id FROM {$wpdb->prefix}octowoo_id_map
                     WHERE entity_type = 'category' AND oc_id = %d LIMIT 1",
                    (int) $oc_cat_id
                ) );

                if ( ! $wc_term_id ) {
                    // Term meta fallback.
                    $wc_term_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        "SELECT tm.term_id FROM {$wpdb->termmeta} tm
                         JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                         WHERE tm.meta_key = '_octowoo_oc_id' AND tm.meta_value = %s
                           AND tt.taxonomy = 'product_cat' LIMIT 1",
                        (string) $oc_cat_id
                    ) );
                }

                if ( $wc_term_id > 0 ) {
                    $wc_term_ids[] = $wc_term_id;
                }
            }

            if ( ! empty( $wc_term_ids ) ) {
                $current = wp_get_object_terms( $post_id, 'product_cat', [ 'fields' => 'ids' ] );
                $current = is_wp_error( $current ) ? [] : $current;
                $merged  = array_unique( array_merge( $current, $wc_term_ids ) );
                wp_set_object_terms( $post_id, $merged, 'product_cat' );
                $total_repaired++;
            }
        }

        $is_done = count( $products ) < $limit;
        wp_send_json_success( [
            'done'     => $is_done,
            'repaired' => $total_repaired,
            'page'     => $page,
        ] );
    }

}
