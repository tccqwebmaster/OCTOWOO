<?php
/**
 * Background processor using WooCommerce Action Scheduler.
 *
 * Queues migration batches as individual AS single-actions so they run
 * via WordPress cron or server cron entirely in the background.
 * The browser tab does NOT need to stay open — the user polls progress
 * via the normal octowoo_get_progress AJAX call.
 *
 * Flow:
 *  1. Admin clicks "Start in Background" (or WP-CLI / cron triggers).
 *  2. BackgroundProcessor::enqueue() schedules the first AS action.
 *  3. Each action calls MigrationManager::runNextChunk() then schedules
 *     the next action 5 seconds later until done_all === true.
 *  4. Admin UI polls octowoo_get_progress; progress renders normally.
 *
 * Requirement: WooCommerce 4.0+ (bundles Action Scheduler).
 *
 * @package OctoWoo\Core
 */

namespace OctoWoo\Core;

defined( 'ABSPATH' ) || exit;

class BackgroundProcessor {

    /** AS action hook name. */
    const AS_HOOK = 'octowoo_process_as_chunk';

    /** AS action group — keeps all OctoWoo jobs together. */
    const AS_GROUP = 'octowoo';

    /** Seconds between consecutive AS chunks. */
    const CHUNK_DELAY = 5;

    /** Transient TTL for storing per-run overrides. */
    const TRANSIENT_TTL = DAY_IN_SECONDS;

    // ── Availability ──────────────────────────────────────────────────────────

    /**
     * Returns true when Action Scheduler is available (WooCommerce 4.0+).
     */
    public static function isAvailable(): bool {
        return function_exists( 'as_schedule_single_action' );
    }

    // ── Registration ──────────────────────────────────────────────────────────

    /**
     * Register the AS callback. Called from the main plugin bootstrap.
     */
    public static function register(): void {
        add_action( self::AS_HOOK, [ self::class, 'processChunk' ] );
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Enqueue a new background migration (or resume an existing one).
     *
     * @param  array<string,mixed> $overrides  Runtime config overrides.
     * @param  string|null         $run_id     Supply to resume, null for a fresh run.
     * @return string  The run ID used.
     * @throws \RuntimeException  When Action Scheduler is not available.
     */
    public static function enqueue( array $overrides = [], ?string $run_id = null ): string {
        if ( ! self::isAvailable() ) {
            throw new \RuntimeException(
                __( 'Action Scheduler is not available. Ensure WooCommerce is active and up to date.', 'octowoo' )
            );
        }

        // Bootstrap a MigrationManager only to resolve/generate a run_id;
        // the actual migration runs inside the AS callback.
        $manager = new MigrationManager( $overrides, $run_id );
        $run_id  = $manager->getRunId();

        // Fresh (re)enqueue should clear stale pause/skip/abort runtime flags.
        MigrationManager::clearRuntimeSignals( $run_id );

        // Persist overrides so each AS callback can rebuild the same manager.
        set_transient( self::transientKey( $run_id ), $overrides, self::TRANSIENT_TTL );

        // Mark run active before the first AS job fires so the UI shows it immediately.
        $checkpoint = new CheckpointManager( $run_id );
        $checkpoint->markRunActive();

        // Cancel any orphaned AS actions for this run before scheduling fresh ones.
        self::cancelPending( $run_id );

        // Schedule the first chunk immediately.
        as_schedule_single_action(
            time(),
            self::AS_HOOK,
            [ 'run_id' => $run_id ],
            self::AS_GROUP
        );

        return $run_id;
    }

    /**
     * Cancel all pending (not yet executed) AS actions for a run.
     *
     * @param  string $run_id
     */
    public static function cancelPending( string $run_id ): void {
        if ( ! self::isAvailable() ) {
            return;
        }

        as_unschedule_all_actions(
            self::AS_HOOK,
            [ 'run_id' => $run_id ],
            self::AS_GROUP
        );
    }

    /**
     * Cancel a run's pending actions AND signal the migration to abort.
     *
     * @param  string $run_id
     */
    public static function abort( string $run_id ): void {
        self::cancelPending( $run_id );
        MigrationManager::requestAbort( $run_id );
        delete_transient( self::transientKey( $run_id ) );
    }

    /**
     * Return status of the background processor for a given run.
     *
     * @param  string $run_id
     * @return array{pending:int,running:int,completed:int,failed:int}
     */
    public static function statusFor( string $run_id ): array {
        if ( ! self::isAvailable() ) {
            return [ 'pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0 ];
        }

        return [
            'pending'   => (int) as_get_scheduled_actions(
                [ 'hook' => self::AS_HOOK, 'args' => [ 'run_id' => $run_id ], 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ],
                'count'
            ),
            'running'   => (int) as_get_scheduled_actions(
                [ 'hook' => self::AS_HOOK, 'args' => [ 'run_id' => $run_id ], 'status' => \ActionScheduler_Store::STATUS_RUNNING, 'per_page' => -1 ],
                'count'
            ),
            'completed' => (int) as_get_scheduled_actions(
                [ 'hook' => self::AS_HOOK, 'args' => [ 'run_id' => $run_id ], 'status' => \ActionScheduler_Store::STATUS_COMPLETE, 'per_page' => -1 ],
                'count'
            ),
            'failed'    => (int) as_get_scheduled_actions(
                [ 'hook' => self::AS_HOOK, 'args' => [ 'run_id' => $run_id ], 'status' => \ActionScheduler_Store::STATUS_FAILED, 'per_page' => -1 ],
                'count'
            ),
        ];
    }

    // ── AS callback ───────────────────────────────────────────────────────────

    /**
     * Called by Action Scheduler for each queued chunk.
     *
     * @param  string $run_id  The migration run ID.
     */
    public static function processChunk( string $run_id ): void {
        // Guard: abort was requested externally.
        if ( MigrationManager::checkAborted( $run_id ) ) {
            self::finish( $run_id );
            return;
        }

        // Pause halts scheduling; resume will enqueue again from AJAX/UI.
        if ( MigrationManager::checkPaused( $run_id ) ) {
            return;
        }

        $overrides = get_transient( self::transientKey( $run_id ) );
        if ( ! is_array( $overrides ) ) {
            $overrides = [];
        }

        try {
            $manager = new MigrationManager( $overrides, $run_id );
            $result  = $manager->runNextChunk();
        } catch ( \Throwable $e ) {
            // Log the error but do NOT re-throw: letting AS mark this action
            // as failed would stop the chain entirely.
            $logger = new Logger( $run_id );
            $logger->error( '[BackgroundProcessor] Chunk error: ' . $e->getMessage() );

            // Schedule a retry after a longer delay so transient failures recover.
            as_schedule_single_action(
                time() + 30,
                self::AS_HOOK,
                [ 'run_id' => $run_id ],
                self::AS_GROUP
            );
            return;
        }

        if ( ! empty( $result['done_all'] ) || ! empty( $result['aborted'] ) ) {
            delete_transient( self::transientKey( $run_id ) );
            self::finish( $run_id );
            return;
        }

        // Schedule the next chunk.
        as_schedule_single_action(
            time() + self::CHUNK_DELAY,
            self::AS_HOOK,
            [ 'run_id' => $run_id ],
            self::AS_GROUP
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function finish( string $run_id ): void {
        $checkpoint = new CheckpointManager( $run_id );
        $checkpoint->markRunFinished();
        MigrationManager::clearRuntimeSignals( $run_id );
    }

    private static function transientKey( string $run_id ): string {
        // Transient keys are limited to 172 chars; run IDs are UUIDs (36 chars).
        return 'octowoo_as_cfg_' . $run_id;
    }
}
