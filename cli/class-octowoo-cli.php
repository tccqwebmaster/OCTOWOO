<?php
/**
 * WP-CLI commands for OctoWoo.
 *
 * Usage:
 *   wp octowoo migrate [--dry-run] [--resume] [--run-id=<id>] [--migrators=<list>]
 *   wp octowoo status  [--run-id=<id>]
 *   wp octowoo reset   [--run-id=<id>]
 *   wp octowoo test-connection
 *
 * @package OctoWoo
 */

defined( 'ABSPATH' ) || exit;

use OctoWoo\Core\MigrationManager;
use OctoWoo\Core\CheckpointManager;
use OctoWoo\Core\DatabaseConnector;

/**
 * Class OctoWoo_CLI
 *
 * Registered in octowoo.php via: WP_CLI::add_command( 'octowoo', 'OctoWoo_CLI' );
 */
class OctoWoo_CLI extends WP_CLI_Command {

    /**
     * Run the OpenCart → WooCommerce migration.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Simulate the migration without writing any data.
     *
     * [--resume]
     * : Resume a previously interrupted run.
     *
     * [--run-id=<id>]
     * : Specify a run ID (defaults to a new UUID when starting fresh).
     *
     * [--migrators=<list>]
     * : Comma-separated list of migrators to run.
     *   Default: all enabled in settings.
     *   Example: --migrators=categories,products,images
     *
     * ## EXAMPLES
     *
     *     wp octowoo migrate
     *     wp octowoo migrate --dry-run
     *     wp octowoo migrate --resume --run-id=abc123
     *     wp octowoo migrate --migrators=categories,products
     *
     * @when after_wp_load
     */
    public function migrate( array $args, array $assoc_args ): void {

        $dry_run    = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
        $resume     = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'resume', false );
        $run_id     = WP_CLI\Utils\get_flag_value( $assoc_args, 'run-id', null );
        $migrators_arg = WP_CLI\Utils\get_flag_value( $assoc_args, 'migrators', null );

        $overrides = [];
        if ( $dry_run ) {
            $overrides['migration']['dry_run'] = true;
        }
        if ( $migrators_arg ) {
            $list = array_map( 'trim', explode( ',', $migrators_arg ) );
            // Disable all, then enable only requested ones.
            $all_keys = [
                'run_categories', 'run_products', 'run_images', 'run_customers',
                'run_orders', 'run_coupons', 'run_seo', 'run_information',
                'run_tags', 'run_filters', 'run_downloads', 'run_manufacturers', 'run_reviews',
            ];
            foreach ( $all_keys as $k ) {
                $overrides['migration'][ $k ] = false;
            }
            foreach ( $list as $name ) {
                $key = 'run_' . strtolower( $name );
                $overrides['migration'][ $key ] = true;
            }
        }

        if ( $dry_run ) {
            WP_CLI::warning( '⚠  DRY RUN – no data will be written.' );
        }

        WP_CLI::line( '' );
        WP_CLI::line( '╔══════════════════════════════════════════════════╗' );
        WP_CLI::line( '║     OctoWoo – OpenCart → WooCommerce Migrator   ║' );
        WP_CLI::line( '╚══════════════════════════════════════════════════╝' );
        WP_CLI::line( '' );

        if ( $dry_run ) {
            WP_CLI::line( 'Mode: DRY RUN (simulating — no writes)' );
            WP_CLI::line( '' );
        }

        $manager = new MigrationManager( $overrides, $run_id );

        // v2.5.0: Wire up a progress callback — works for both dry-run and live.
        $progress_bar = null;
        $current_migrator = '';
        $current_total = 0;
        $dry_run_counts = []; // Accumulate dry-run per-migrator counts.

        $manager->onProgress( function ( string $migrator_name, int $processed, int $total ) use (
            &$progress_bar, &$current_migrator, &$current_total, &$dry_run_counts, $dry_run
        ) {
            if ( $migrator_name !== $current_migrator ) {
                if ( $progress_bar ) {
                    $progress_bar->finish();
                    WP_CLI::line( '' );
                }
                $current_migrator = $migrator_name;
                $current_total    = $total;
                // v2.5.0: Show progress bar even in dry-run mode.
                $label = sprintf( '  %-22s', ucfirst( $migrator_name ) . ( $dry_run ? ' [DRY]' : '' ) );
                $progress_bar = WP_CLI\Utils\make_progress_bar( $label, $total ?: 1 );
            }
            if ( $dry_run && isset( $dry_run_counts[ $migrator_name ] ) ) {
                $dry_run_counts[ $migrator_name ] = max( $dry_run_counts[ $migrator_name ], $processed );
            } elseif ( $dry_run ) {
                $dry_run_counts[ $migrator_name ] = $processed;
            }
            if ( $progress_bar && $current_total > 0 ) {
                $progress_bar->tick( $processed );
            }
        } );

        try {
            $report = $manager->run( $resume );
        } catch ( \Throwable $e ) {
            if ( $progress_bar ) {
                $progress_bar->finish();
            }
            WP_CLI::error( 'Migration failed: ' . $e->getMessage() );
            return;
        }

        if ( $progress_bar ) {
            $progress_bar->finish();
        }

        WP_CLI::line( '' );
        WP_CLI::line( '─────────────────────────────────────────────────' );
        if ( $dry_run ) {
            WP_CLI::success( sprintf( 'DRY RUN complete. Run ID: %s', $report['run_id'] ) );
            WP_CLI::line( '  Nothing was written to the database.' );
        } else {
            WP_CLI::success( sprintf( 'Migration complete. Run ID: %s', $report['run_id'] ) );
        }
        WP_CLI::line( '' );

        // Print summary table.
        $table_rows = [];
        foreach ( $report['results'] ?? [] as $name => $r ) {
            $table_rows[] = [
                'Migrator'  => ucfirst( $name ),
                'Processed' => $r['processed'] ?? 0,
                'Skipped'   => $r['skipped']   ?? 0,
                'Failed'    => $r['failed']     ?? 0,
                'Duration'  => isset( $r['duration'] ) ? round( $r['duration'], 2 ) . 's' : 'n/a',
            ];
        }
        if ( $table_rows ) {
            WP_CLI\Utils\format_items( 'table', $table_rows, [ 'Migrator', 'Processed', 'Skipped', 'Failed', 'Duration' ] );
        }

        $errors = $report['error_count'] ?? 0;
        if ( $errors > 0 ) {
            WP_CLI::warning( "There were {$errors} error(s). Run `wp octowoo logs` for details." );
        }
    }

    /**
     * Show migration status / checkpoint progress.
     *
     * ## OPTIONS
     *
     * [--run-id=<id>]
     * : Show status for a specific run ID (defaults to the last/active run).
     *
     * ## EXAMPLES
     *
     *     wp octowoo status
     *     wp octowoo status --run-id=abc123
     *
     * @when after_wp_load
     */
    public function status( array $args, array $assoc_args ): void {
        global $wpdb;

        $run_id = WP_CLI\Utils\get_flag_value( $assoc_args, 'run-id', null );
        if ( ! $run_id ) {
            $run_id = CheckpointManager::getActiveRunId()
                ?: get_option( 'octowoo_last_run_id', null );
        }

        if ( ! $run_id ) {
            WP_CLI::line( 'No migration runs found.' );
            return;
        }

        WP_CLI::line( "Run ID: {$run_id}" );
        WP_CLI::line( '' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT migrator, status, total, processed, failed, started_at, completed_at
                 FROM {$wpdb->prefix}octowoo_checkpoints
                 WHERE run_id = %s
                 ORDER BY id ASC",
                $run_id
            ),
            ARRAY_A
        );

        if ( ! $rows ) {
            WP_CLI::line( 'No checkpoints for this run ID.' );
            return;
        }

        $table_rows = [];
        foreach ( $rows as $row ) {
            $total     = (int) $row['total'];
            $processed = (int) $row['processed'];
            $pct       = $total > 0 ? round( $processed / $total * 100, 1 ) : 0;

            $table_rows[] = [
                'Migrator'     => ucfirst( $row['migrator'] ),
                'Status'       => strtoupper( $row['status'] ),
                'Processed'    => $processed,
                'Total'        => $total,
                'Failed'       => (int) $row['failed'],
                '%'            => $pct . '%',
                'Started'      => $row['started_at'] ?? '',
                'Completed'    => $row['completed_at'] ?? '',
            ];
        }

        WP_CLI\Utils\format_items(
            'table',
            $table_rows,
            [ 'Migrator', 'Status', 'Processed', 'Total', 'Failed', '%', 'Started', 'Completed' ]
        );
    }

    /**
     * Show recent migration log entries.
     *
     * ## OPTIONS
     *
     * [--level=<level>]
     * : Filter by log level: DEBUG, INFO, WARNING, ERROR, SUCCESS.
     *
     * [--limit=<n>]
     * : Number of entries to show (default: 50).
     *
     * [--run-id=<id>]
     * : Filter by run ID.
     *
     * ## EXAMPLES
     *
     *     wp octowoo logs
     *     wp octowoo logs --level=ERROR
     *     wp octowoo logs --limit=100
     *
     * @when after_wp_load
     */
    public function logs( array $args, array $assoc_args ): void {
        global $wpdb;

        $level  = WP_CLI\Utils\get_flag_value( $assoc_args, 'level', null );
        $limit  = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 50 );
        $run_id = WP_CLI\Utils\get_flag_value( $assoc_args, 'run-id', null );
        $limit  = max( 1, min( 1000, $limit ) );

        $where  = '1=1';
        $params = [];

        if ( $level ) {
            $where    .= ' AND level = %s';
            $params[]  = strtoupper( $level );
        }
        if ( $run_id ) {
            $where    .= ' AND run_id = %s';
            $params[]  = $run_id;
        }

        $sql = "SELECT level, migrator, message, created_at
                FROM {$wpdb->prefix}octowoo_logs
                WHERE {$where}
                ORDER BY id DESC
                LIMIT %d";
        $params[] = $limit;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        if ( ! $rows ) {
            WP_CLI::line( 'No log entries found.' );
            return;
        }

        $rows = array_reverse( $rows );
        foreach ( $rows as $row ) {
            $color = match ( $row['level'] ) {
                'ERROR'   => '%R',
                'WARNING' => '%Y',
                'SUCCESS' => '%G',
                'DEBUG'   => '%K',
                default   => '%w',
            };
            $line = sprintf(
                '[%s] [%-8s] [%-12s] %s',
                $row['created_at'],
                $row['level'],
                $row['migrator'] ?: 'core',
                $row['message']
            );
            WP_CLI::line( WP_CLI::colorize( $color . $line . '%n' ) );
        }
    }

    /**
     * Reset all migration progress (checkpoints, ID maps, logs).
     *
     * ## OPTIONS
     *
     * [--run-id=<id>]
     * : Reset only a specific run (defaults to all data).
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp octowoo reset
     *     wp octowoo reset --yes
     *
     * @when after_wp_load
     */
    public function reset( array $args, array $assoc_args ): void {
        global $wpdb;

        $run_id = WP_CLI\Utils\get_flag_value( $assoc_args, 'run-id', null );
        $yes    = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

        $msg = $run_id
            ? "This will delete all checkpoints, ID maps, and logs for run '{$run_id}'."
            : 'This will delete ALL migration checkpoints, ID maps, and log entries.';

        WP_CLI::warning( $msg );

        if ( ! $yes ) {
            WP_CLI::confirm( 'Are you sure you want to proceed?' );
        }

        if ( $run_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete( $wpdb->prefix . 'octowoo_checkpoints', [ 'run_id' => $run_id ], [ '%s' ] );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete( $wpdb->prefix . 'octowoo_id_map', [ 'run_id' => $run_id ], [ '%s' ] );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete( $wpdb->prefix . 'octowoo_logs', [ 'run_id' => $run_id ], [ '%s' ] );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}octowoo_checkpoints" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}octowoo_id_map" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}octowoo_logs" );
            delete_option( 'octowoo_active_run_id' );
            delete_option( 'octowoo_last_run_id' );
            delete_option( 'octowoo_last_run_at' );
        }

        WP_CLI::success( 'Migration data reset.' );
    }

    /**
     * Test the OpenCart database connection.
     *
     * ## EXAMPLES
     *
     *     wp octowoo test-connection
     *
     * @when after_wp_load
     */
    public function test_connection( array $args, array $assoc_args ): void {
        WP_CLI::line( 'Testing OpenCart database connection…' );

        try {
            $connector = new DatabaseConnector();
            $connector->connect();
            $version   = $connector->fetchColumn( 'SELECT @@version' );
            $count     = $connector->count( 'product' );
            WP_CLI::success( "Connected! MySQL version: {$version}" );
            WP_CLI::line( "  Products found in OpenCart: {$count}" );
        } catch ( \Throwable $e ) {
            WP_CLI::error( 'Connection failed: ' . $e->getMessage() );
        }
    }
}
