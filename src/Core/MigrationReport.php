<?php
/**
 * Migration report.
 *
 * Builds a structured, human-readable summary from per-migrator stats after
 * a migration run completes.  The report is always persisted to wp_options
 * so it can be retrieved on any subsequent page load via MigrationReport::load().
 *
 * Report shape:
 * {
 *   run_id:          string            – unique run identifier
 *   finished:        string            – MySQL datetime
 *   dry_run:         bool
 *   ok:              bool              – true when no migrator reported failures
 *   total_processed: int
 *   total_skipped:   int
 *   total_failed:    int
 *   migrators:       { [key]: { processed, skipped, failed, status } }
 *   warnings:        string[]          – migrator keys that had failures
 *   checkpoints:     array             – raw checkpoint rows
 * }
 */

namespace OctoWoo\Core;

defined( 'ABSPATH' ) || exit;

class MigrationReport {

    private const OPTION_KEY = 'octowoo_last_report';

    /** @var string */
    private string $run_id;

    /** @var array<string, array{processed:int,skipped:int,failed:int}> */
    private array $stats;

    /** @var array<int, array<string, mixed>> */
    private array $checkpoints;

    /** @var bool */
    private bool $dry_run;

    /** @var string */
    private string $finished_at;

    // ── Construction ──────────────────────────────────────────────────────────

    /**
     * @param string $run_id
     * @param array<string, array{processed:int,skipped:int,failed:int}> $stats  Per-migrator results.
     * @param array<int, array<string, mixed>> $checkpoints  Raw checkpoint rows.
     * @param bool   $dry_run
     * @param string $finished_at  MySQL datetime; defaults to current time.
     */
    public function __construct(
        string $run_id,
        array  $stats,
        array  $checkpoints,
        bool   $dry_run     = false,
        string $finished_at = ''
    ) {
        $this->run_id      = $run_id;
        $this->stats       = $stats;
        $this->checkpoints = $checkpoints;
        $this->dry_run     = $dry_run;
        $this->finished_at = $finished_at ?: current_time( 'mysql' );
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Structured array — stored in wp_options, returned via AJAX, consumed by admin UI.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array {
        $total_processed = 0;
        $total_skipped   = 0;
        $total_failed    = 0;
        $migrators       = [];
        $warnings        = [];

        foreach ( $this->stats as $key => $stat ) {
            $p = (int) ( $stat['processed'] ?? 0 );
            $s = (int) ( $stat['skipped']   ?? 0 );
            $f = (int) ( $stat['failed']    ?? 0 );

            $total_processed += $p;
            $total_skipped   += $s;
            $total_failed    += $f;

            $status = 'empty';
            if ( $f > 0 ) {
                $status     = 'warning';
                $warnings[] = $key;
            } elseif ( $p > 0 || $s > 0 ) {
                $status = 'ok';
            }

            $migrators[ $key ] = [
                'processed' => $p,
                'skipped'   => $s,
                'failed'    => $f,
                'status'    => $status,
            ];
        }

        return [
            'run_id'          => $this->run_id,
            'finished'        => $this->finished_at,
            'dry_run'         => $this->dry_run,
            'ok'              => $total_failed === 0,
            'total_processed' => $total_processed,
            'total_skipped'   => $total_skipped,
            'total_failed'    => $total_failed,
            'migrators'       => $migrators,
            'warnings'        => $warnings,
            'checkpoints'     => $this->checkpoints,
        ];
    }

    /**
     * Human-readable text summary suitable for log files.
     */
    public function toText(): string {
        $data  = $this->toArray();
        $lines = [];

        $lines[] = sprintf(
            'Run %s | finished: %s | dry_run: %s | processed: %d | skipped: %d | failed: %d',
            $data['run_id'],
            $data['finished'],
            $data['dry_run'] ? 'yes' : 'no',
            $data['total_processed'],
            $data['total_skipped'],
            $data['total_failed']
        );

        foreach ( $data['migrators'] as $key => $m ) {
            $icon    = match ( $m['status'] ) {
                'ok'      => '+',
                'warning' => '!',
                default   => '-',
            };
            $lines[] = sprintf(
                '  [%s] %-22s  processed=%d  skipped=%d  failed=%d',
                $icon,
                $key,
                $m['processed'],
                $m['skipped'],
                $m['failed']
            );
        }

        return implode( "\n", $lines );
    }

    /**
     * Persist to wp_options so the report survives across page loads.
     * Uses `false` for autoload — report data is only needed on-demand.
     */
    public function save(): void {
        $data = $this->toArray();
        update_option( self::OPTION_KEY, $data, false );

        // v2.5.0: Append to run history (last 10 runs).
        $history = (array) get_option( 'octowoo_run_history', [] );
        array_unshift( $history, [
            'run_id'          => $data['run_id'],
            'finished'        => $data['finished'],
            'dry_run'         => $data['dry_run'],
            'ok'              => $data['ok'],
            'total_processed' => $data['total_processed'],
            'total_failed'    => $data['total_failed'],
        ] );
        $history = array_slice( $history, 0, 10 ); // Keep only the 10 most recent.
        update_option( 'octowoo_run_history', $history, false );
    }

    /**
     * Retrieve migration run history (last 10 runs, most recent first).
     *
     * @return array<int, array<string,mixed>>
     */
    public static function loadHistory(): array {
        $history = get_option( 'octowoo_run_history', [] );
        return is_array( $history ) ? $history : [];
    }

    /**
     * Retrieve the most recently saved report.
     *
     * @return array<string, mixed>  Empty array when no report exists yet.
     */
    public static function load(): array {
        $data = get_option( self::OPTION_KEY, [] );
        return is_array( $data ) ? $data : [];
    }
}
