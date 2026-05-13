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

        // Clear the image-import circuit-breaker transient so a fresh run
        // always re-attempts remote image downloads even if the last run
        // tripped the breaker (e.g. source server was temporarily down).
        delete_transient( 'octowoo_img_remote_down' );

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

            // ── v2.4.72: Send email summary report on completion ──────────────
            if ( ! empty( $result['done_all'] ) ) {
                self::sendCompletionEmail( $run_id );
            }

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

    // ── Email report ──────────────────────────────────────────────────────────

    /**
     * Send a migration summary email to the WordPress admin address on completion.
     *
     * The email is sent only when:
     *  - The run completed successfully (done_all=true, not aborted).
     *  - wp_mail() is available (always true in WP).
     *  - The admin_email option is a valid address.
     *
     * @param string $run_id
     */
    private static function sendCompletionEmail( string $run_id ): void {
        $admin_email = get_option( 'admin_email', '' );
        if ( ! is_email( $admin_email ) ) {
            return;
        }

        $report_data = MigrationReport::load();

        // Ensure the loaded report matches this run.
        if ( empty( $report_data['run_id'] ) || $report_data['run_id'] !== $run_id ) {
            // Try loading directly from the checkpoint data.
            $report_data = [
                'run_id'          => $run_id,
                'finished'        => current_time( 'mysql' ),
                'total_processed' => 0,
                'total_failed'    => 0,
                'migrators'       => [],
            ];
        }

        $site_name       = get_bloginfo( 'name' );
        $admin_url       = admin_url( 'admin.php?page=octowoo-migration&tab=migration' );
        $total_processed = number_format( (int) ( $report_data['total_processed'] ?? 0 ) );
        $total_failed    = (int) ( $report_data['total_failed'] ?? 0 );
        $finished_at     = $report_data['finished'] ?? current_time( 'mysql' );
        $status_text     = $total_failed > 0 ? 'Completed with warnings' : 'Completed successfully';
        $status_icon     = $total_failed > 0 ? '⚠' : '✔';

        // Build per-migrator rows.
        $migrator_rows = '';
        $label_map = [
            'tax'           => 'Tax Classes',        'order_statuses' => 'Order Statuses',
            'categories'    => 'Categories',         'images'         => 'Images',
            'products'      => 'Products',           'manufacturers'  => 'Manufacturers / Brands',
            'related'       => 'Related Products',   'bundles'        => 'Bundles',
            'customers'     => 'Customers',          'orders'         => 'Orders',
            'coupons'       => 'Coupons',            'seo'            => 'SEO URLs',
            'information'   => 'Information Pages',  'tags'           => 'Tags',
            'filters'       => 'Product Filters',    'downloads'      => 'Downloads',
            'reviews'       => 'Reviews',            'multilingual'   => 'Multilingual',
        ];
        foreach ( $report_data['migrators'] ?? [] as $key => $m ) {
            $label     = $label_map[ $key ] ?? ucfirst( $key );
            $p         = number_format( (int) ( $m['processed'] ?? 0 ) );
            $s         = number_format( (int) ( $m['skipped']   ?? 0 ) );
            $f         = (int) ( $m['failed'] ?? 0 );
            $f_text    = $f > 0 ? "<strong style='color:#c62828;'>{$f}</strong>" : '0';
            $migrator_rows .= "<tr>
                <td style='padding:5px 12px;border-bottom:1px solid #f0f0f0;'>{$label}</td>
                <td style='padding:5px 12px;border-bottom:1px solid #f0f0f0;text-align:center;color:#2e7d32;'>{$p}</td>
                <td style='padding:5px 12px;border-bottom:1px solid #f0f0f0;text-align:center;color:#757575;'>{$s}</td>
                <td style='padding:5px 12px;border-bottom:1px solid #f0f0f0;text-align:center;'>{$f_text}</td>
            </tr>";
        }

        $subject = sprintf( '[%s] %s OctoWoo Migration — %s', $site_name, $status_icon, $status_text );

        $html_body = "<!DOCTYPE html><html><body style='font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;font-size:14px;color:#1d2327;background:#f6f7f8;margin:0;padding:24px;'>
<div style='max-width:600px;margin:0 auto;background:#fff;border-radius:8px;border:1px solid #ddd;overflow:hidden;'>
  <div style='background:" . ( $total_failed > 0 ? '#fff8f0' : '#edf7ed' ) . ";padding:20px 24px;border-bottom:1px solid #ddd;'>
    <h1 style='margin:0;font-size:18px;color:" . ( $total_failed > 0 ? '#854f0b' : '#2e7d32' ) . ";'>{$status_icon} OctoWoo Migration {$status_text}</h1>
    <p style='margin:4px 0 0;font-size:13px;color:#666;'>{$site_name} &middot; {$finished_at}</p>
  </div>
  <div style='padding:20px 24px;'>
    <table style='width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;'>
      <tr style='background:#f9f9f9;'>
        <td style='padding:8px 12px;'><strong>Total Processed</strong></td>
        <td style='padding:8px 12px;font-weight:700;color:#2e7d32;'>{$total_processed}</td>
      </tr>
      <tr>
        <td style='padding:8px 12px;'><strong>Failed Items</strong></td>
        <td style='padding:8px 12px;font-weight:700;color:" . ( $total_failed > 0 ? '#c62828' : '#2e7d32' ) . ";'>{$total_failed}</td>
      </tr>
      <tr style='background:#f9f9f9;'>
        <td style='padding:8px 12px;'><strong>Run ID</strong></td>
        <td style='padding:8px 12px;font-family:monospace;font-size:11px;'>" . esc_html( $run_id ) . "</td>
      </tr>
    </table>
    <h3 style='margin:0 0 8px;font-size:14px;'>Per-entity breakdown</h3>
    <table style='width:100%;border-collapse:collapse;font-size:12px;'>
      <thead><tr style='background:#f5f5f5;'>
        <th style='padding:6px 12px;text-align:left;border-bottom:2px solid #ddd;'>Entity</th>
        <th style='padding:6px 12px;border-bottom:2px solid #ddd;'>Processed</th>
        <th style='padding:6px 12px;border-bottom:2px solid #ddd;'>Skipped</th>
        <th style='padding:6px 12px;border-bottom:2px solid #ddd;'>Failed</th>
      </tr></thead>
      <tbody>{$migrator_rows}</tbody>
    </table>
    <div style='margin-top:20px;text-align:center;'>
      <a href='{$admin_url}' style='display:inline-block;padding:10px 20px;background:#7952b3;color:#fff;text-decoration:none;border-radius:5px;font-weight:600;font-size:13px;'>View Migration Details →</a>
    </div>
    " . ( $total_failed > 0 ? "<p style='margin-top:16px;font-size:12px;color:#666;'>Some items failed to migrate. Check the <a href='{$admin_url}'>Logs tab</a> for details and use the Resume function to retry failed items.</p>" : '' ) . "
  </div>
  <div style='padding:12px 24px;background:#f9f9f9;border-top:1px solid #ddd;font-size:11px;color:#999;text-align:center;'>
    Sent by OctoWoo Migration Plugin &mdash; <a href='{$admin_url}' style='color:#999;'>Manage</a>
  </div>
</div>
</body></html>";

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'X-Mailer: OctoWoo/' . OCTOWOO_VERSION,
        ];

        // Allow filtering the recipient (e.g. for testing).
        $recipient = apply_filters( 'octowoo_report_email_recipient', $admin_email, $run_id );

        if ( is_email( $recipient ) ) {
            wp_mail( $recipient, $subject, $html_body, $headers );
        }
    }

    private static function transientKey( string $run_id ): string {
        // Transient keys are limited to 172 chars; run IDs are UUIDs (36 chars).
        return 'octowoo_as_cfg_' . $run_id;
    }
}
