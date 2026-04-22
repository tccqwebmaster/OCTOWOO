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
    private const STALE_LOCK_SECONDS = 7200;

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

            default:
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
            wp_send_json_error( [
                'message' => __( 'A migration is already in progress. Abort it first or use Resume.', 'octowoo' ),
                'run_id'  => $active_run,
            ] );
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
        $run_id = sanitize_text_field(
            filter_input( INPUT_POST, 'run_id', FILTER_SANITIZE_SPECIAL_CHARS )
                ?? CheckpointManager::getActiveRunId()
                ?? ''
        );

        if ( ! $run_id ) {
            wp_send_json_error( [ 'message' => __( 'No paused migration found.', 'octowoo' ) ] );
        }

        MigrationManager::clearPause( $run_id );

        wp_send_json_success( [
            'message' => __( 'Migration resumed.', 'octowoo' ),
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
                        $text = is_string( $message ) ? wp_strip_all_tags( (string) $message ) : 'Server error';
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

        $should_clear_orders = $clear_orders_raw !== null
            ? filter_var( $clear_orders_raw, FILTER_VALIDATE_BOOLEAN )
            : false;
        if ( $should_clear_orders && $run_id === null ) {
            $this->purgeOrdersBeforeRun();
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $demo_limit_chunk = filter_input( INPUT_POST, 'demo_limit', FILTER_VALIDATE_INT );

        $overrides = [];
        if ( $dry_run_raw !== null ) {
            $overrides['migration']['dry_run'] = filter_var( $dry_run_raw, FILTER_VALIDATE_BOOLEAN );
        }
        if ( $demo_limit_chunk !== null && $demo_limit_chunk !== false ) {
            $overrides['migration']['demo_limit'] = max( 0, (int) $demo_limit_chunk );
        }
        if ( $migrators_raw !== '' ) {
            $allowed  = [ 'tax', 'order_statuses', 'categories', 'manufacturers', 'images', 'products', 'related', 'bundles', 'customers', 'orders', 'coupons', 'seo', 'information', 'tags', 'filters', 'downloads', 'reviews', 'multilingual' ];
            $selected = array_filter( explode( ',', $migrators_raw ), fn( $k ) => in_array( $k, $allowed, true ) );
            foreach ( $allowed as $key ) {
                $overrides['migration'][ 'run_' . $key ] = in_array( $key, $selected, true );
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

        // Delete ALL checkpoint and ID-map rows for every known run.
        // Using both active_run and last_run handles the edge case where they differ.
        $run_ids = array_filter( array_unique( [
            $active_run ?? '',
            get_option( 'octowoo_last_run_id', '' ),
        ] ) );

        foreach ( $run_ids as $rid ) {
            $wpdb->delete( $cp_table,  [ 'run_id' => $rid ], [ '%s' ] );
            $wpdb->delete( $map_table, [ 'run_id' => $rid ], [ '%s' ] );
        }

        // Clean up all migration state options.
        delete_option( 'octowoo_last_run_id' );
        delete_option( 'octowoo_last_run_at' );
        delete_option( 'octowoo_active_run_id' );
        delete_option( 'octowoo_run_started_at' );
        delete_option( 'octowoo_db_lock' );

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
            $allowed  = [ 'tax', 'order_statuses', 'categories', 'manufacturers', 'images', 'products', 'related', 'bundles', 'customers', 'orders', 'coupons', 'seo', 'information', 'tags', 'filters', 'downloads', 'reviews', 'multilingual' ];
            $selected = array_filter( explode( ',', $migrators_raw ), fn( $k ) => in_array( $k, $allowed, true ) );
            foreach ( $allowed as $key ) {
                $overrides['migration'][ 'run_' . $key ] = in_array( $key, $selected, true );
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
}
