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


    /**
     * Run ONLY the multilingual/Arabic translation pass.
     *
     * ## DESCRIPTION
     *
     * Translates all products, categories and brands to the secondary language
     * (Arabic by default). Runs directly — no cron, no browser needed.
     * Safe to run multiple times; already-translated products are skipped.
     *
     * ## EXAMPLES
     *
     *     wp octowoo multilingual
     *
     * @when after_wp_load
     */
    public function multilingual( array $args, array $assoc_args ): void {
        global $wpdb;

        WP_CLI::line( '' );
        WP_CLI::line( '╔══════════════════════════════════════════════════╗' );
        WP_CLI::line( '║   OctoWoo — Multilingual (Arabic) Pass          ║' );
        WP_CLI::line( '╚══════════════════════════════════════════════════╝' );
        WP_CLI::line( '' );

        // Clear stale state.
        delete_option( 'octowoo_active_run_id' );
        delete_option( 'octowoo_ml_terms_v2' );
        $wpdb->query( "UPDATE {$wpdb->prefix}octowoo_checkpoints SET status='pending', processed_count=0 WHERE migrator='multilingual'" );

        // Use a proper run_id and register it so the dashboard can track progress.
        $run_id = 'cli-ml-' . date( 'YmdHis' );
        $config = get_option( 'octowoo_settings', [] );
        $config['migration']['run_multilingual'] = true;
        $config['multilingual']['enabled']       = true;
        $config['migration']['batch_size']       = 500;

        // Register run as active so dashboard polling picks it up.
        update_option( 'octowoo_active_run_id', $run_id );
        delete_option( 'octowoo_ml_terms_v2' );

        $oc         = new \OctoWoo\Core\DatabaseConnector( $config['db'] ?? [] );
        $logger     = new \OctoWoo\Core\Logger( $run_id );
        $checkpoint = new \OctoWoo\Core\CheckpointManager( $run_id );
        $batch      = new \OctoWoo\Core\BatchProcessor( $logger );
        $checkpoint->init( 'multilingual', 9999 );
        $checkpoint->start( 'multilingual' );

        WP_CLI::line( "Run ID: {$run_id}" );
        WP_CLI::line( '' );

        $total_processed = 0;
        $chunk           = 0;

        do {
            $chunk++;
            $wpml   = new \OctoWoo\Integration\WpmlIntegration( $oc, $logger, $checkpoint, $batch, $config );
            $result = $wpml->migrate();

            $total_processed += (int) ( $result['processed'] ?? 0 );
            $is_done          = ! empty( $result['is_done'] );

            // Count untranslated remaining.
            $remaining = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 WHERE p.post_type='product' AND p.post_status IN ('publish','draft')
                 AND NOT EXISTS (
                     SELECT 1 FROM {$wpdb->postmeta} ps
                     WHERE ps.meta_key='_octowoo_translation_of' AND ps.meta_value=p.ID
                 )"
            );

            WP_CLI::line( sprintf(
                'Chunk %d — translated: %d | remaining: %d | done: %s',
                $chunk,
                (int) ( $result['processed'] ?? 0 ),
                $remaining,
                $is_done ? 'YES ✔' : 'no'
            ) );

            if ( $chunk > 200 ) {
                WP_CLI::warning( 'Safety limit reached (200 chunks). Run again if needed.' );
                break;
            }
        } while ( ! $is_done );

        WP_CLI::line( '' );
        WP_CLI::success( "Multilingual complete. Total translated: {$total_processed}. Remaining: {$remaining}" );
    }

    /**
     * Re-link product thumbnails from already-imported local media — NO remote downloads.
     *
     * Why this exists: some products were created without a _thumbnail_id even though
     * their image is already sitting in the media library (imported by the Images step
     * or a previous run, but never linked back to the product). English and Arabic
     * share the SAME image, so there is never a reason to re-download for Arabic —
     * we just point both posts at the existing attachment.
     *
     * Pass A: every English/primary product missing a thumbnail is matched to its
     *         existing attachment via _octowoo_oc_image_path (pure DB lookup) and linked.
     * Pass B: every Arabic translation is made to share its English parent's thumbnail
     *         and gallery, so the translated post is never missing an image the parent has.
     *
     * Remote download is NEVER attempted unless --download is passed explicitly.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what would change without writing.
     *
     * [--download]
     * : As a last resort, download from the remote shop for products with no local
     *   attachment at all. Slow — off by default.
     *
     * ## EXAMPLES
     *
     *     wp octowoo relink_images
     *     wp octowoo relink_images --dry-run
     *
     * @when after_wp_load
     */
    public function relink_images( array $args, array $assoc_args ): void {
        global $wpdb;

        $dry      = isset( $assoc_args['dry-run'] );
        $download = isset( $assoc_args['download'] );

        WP_CLI::line( '' );
        WP_CLI::line( '╔══════════════════════════════════════════════════╗' );
        WP_CLI::line( '║   OctoWoo — Re-link Product Images (local)       ║' );
        WP_CLI::line( '╚══════════════════════════════════════════════════╝' );
        WP_CLI::line( $dry ? 'Mode: DRY-RUN (no writes)' : 'Mode: LIVE' );
        WP_CLI::line( $download ? 'Remote download: ENABLED (slow fallback)' : 'Remote download: disabled (local only)' );
        WP_CLI::line( '' );

        $config = get_option( 'octowoo_settings', [] );
        $run_id = 'cli-relink-' . date( 'YmdHis' );
        $oc         = new \OctoWoo\Core\DatabaseConnector( $config['db'] ?? [] );
        $logger     = new \OctoWoo\Core\Logger( $run_id );
        $checkpoint = new \OctoWoo\Core\CheckpointManager( $run_id );
        $batch      = new \OctoWoo\Core\BatchProcessor( $logger );
        $img        = new \OctoWoo\Migrators\ImageMigrator( $oc, $logger, $checkpoint, $batch, $config );

        // ── Pass A: relink primary products missing a thumbnail ──────────────
        $missing = $wpdb->get_results( // phpcs:ignore WordPress.DB
            "SELECT p.ID AS pid, oip.meta_value AS oc_path
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} oip ON oip.post_id = p.ID AND oip.meta_key = '_octowoo_oc_image_path'
             LEFT JOIN {$wpdb->postmeta} th ON th.post_id = p.ID AND th.meta_key = '_thumbnail_id'
             WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft')
               AND ( th.meta_value IS NULL OR th.meta_value = '' OR th.meta_value = '0' )",
            ARRAY_A
        );

        $relinked = 0; $downloaded = 0; $still_missing = 0;
        WP_CLI::line( 'Pass A — products missing thumbnail: ' . count( $missing ) );
        $bar = \WP_CLI\Utils\make_progress_bar( 'Re-linking', max( 1, count( $missing ) ) );

        foreach ( $missing as $row ) {
            $pid     = (int) $row['pid'];
            $oc_path = (string) $row['oc_path'];
            $aid     = $oc_path !== '' ? $img->findAttachmentByOcPath( $oc_path ) : null;

            if ( ! $aid && $download && $oc_path !== '' ) {
                $aid = $img->importByOcPath( $oc_path );
                if ( $aid && $aid > 0 ) { $downloaded++; }
            }

            if ( $aid && $aid > 0 ) {
                if ( ! $dry ) { set_post_thumbnail( $pid, (int) $aid ); }
                $relinked++;
            } else {
                $still_missing++;
            }
            $bar->tick();
        }
        $bar->finish();

        // ── Pass B: make every Arabic translation share its parent's image ───
        $trans = $wpdb->get_results( // phpcs:ignore WordPress.DB
            "SELECT pm.post_id AS tid, pm.meta_value AS parent
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product'
             WHERE pm.meta_key = '_octowoo_translation_of'",
            ARRAY_A
        );

        $propagated = 0;
        WP_CLI::line( '' );
        WP_CLI::line( 'Pass B — translations to sync: ' . count( $trans ) );
        $bar2 = \WP_CLI\Utils\make_progress_bar( 'Syncing Arabic', max( 1, count( $trans ) ) );

        foreach ( $trans as $row ) {
            $tid    = (int) $row['tid'];
            $parent = (int) $row['parent'];
            if ( $parent <= 0 ) { $bar2->tick(); continue; }

            $parent_thumb = (int) get_post_meta( $parent, '_thumbnail_id', true );
            $own_thumb    = (int) get_post_meta( $tid, '_thumbnail_id', true );
            if ( $parent_thumb > 0 && $parent_thumb !== $own_thumb ) {
                if ( ! $dry ) {
                    set_post_thumbnail( $tid, $parent_thumb );
                    $gallery = (string) get_post_meta( $parent, '_product_image_gallery', true );
                    update_post_meta( $tid, '_product_image_gallery', $gallery );
                }
                $propagated++;
            }
            $bar2->tick();
        }
        $bar2->finish();

        WP_CLI::line( '' );
        WP_CLI::success( sprintf(
            'Done. Re-linked %d primary%s, synced %d Arabic translations. %d still missing (no image anywhere).',
            $relinked,
            $download ? " ({$downloaded} downloaded)" : '',
            $propagated,
            $still_missing
        ) );
        if ( $still_missing > 0 && ! $download ) {
            WP_CLI::line( 'Tip: the still-missing products have no imported image. Run the Images step, or add --download to fetch them from the shop.' );
        }
    }

    /**
     * Safely de-duplicate product categories and brands without losing SEO.
     *
     * Groups terms by (taxonomy, name). When a name has more than one term, ONE
     * keeper is chosen and the rest are merged into it and removed. Rules:
     *
     *   • Keeper = the term that has products assigned (highest count). Empty
     *     duplicates (count 0) are the ones removed — so no product loses its
     *     category/brand and no populated archive page disappears.
     *   • SEO is preserved: before removing an empty duplicate, if that duplicate
     *     holds the cleaner slug (no 'ow-t-' prefix) while the keeper's slug is the
     *     auto-generated 'ow-t-…' one, the clean slug is transferred to the keeper
     *     first. Your existing slugs/redirects stay on the surviving term.
     *   • Any product relationships on a duplicate are re-pointed to the keeper
     *     before deletion (belt-and-suspenders; duplicates are usually already 0).
     *   • WPML icl_translations rows for removed duplicates are cleaned up.
     *
     * DRY-RUN BY DEFAULT. Nothing is changed until you pass --apply.
     *
     * ## OPTIONS
     *
     * [--taxonomy=<tax>]
     * : Limit to one taxonomy. Default: both product_cat and the active brand taxonomy.
     *
     * [--apply]
     * : Actually perform the merge/cleanup. Without this, only reports.
     *
     * ## EXAMPLES
     *
     *     wp octowoo dedupe_terms                       # preview only
     *     wp octowoo dedupe_terms --taxonomy=product_cat
     *     wp octowoo dedupe_terms --apply               # do it
     *
     * @when after_wp_load
     */
    public function dedupe_terms( array $args, array $assoc_args ): void {
        global $wpdb;

        $apply = isset( $assoc_args['apply'] );

        // Resolve which taxonomies to process.
        $taxes = [];
        if ( ! empty( $assoc_args['taxonomy'] ) ) {
            $taxes[] = sanitize_key( $assoc_args['taxonomy'] );
        } else {
            $taxes[] = 'product_cat';
            foreach ( [ 'product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand', 'product_manufacturer' ] as $bt ) {
                if ( taxonomy_exists( $bt ) ) { $taxes[] = $bt; break; }
            }
        }

        WP_CLI::line( '' );
        WP_CLI::line( '╔══════════════════════════════════════════════════╗' );
        WP_CLI::line( '║   OctoWoo — De-duplicate Terms (SEO-safe)        ║' );
        WP_CLI::line( '╚══════════════════════════════════════════════════╝' );
        WP_CLI::line( $apply ? 'Mode: APPLY (changes will be written)' : 'Mode: DRY-RUN (no changes)' );
        WP_CLI::line( 'Taxonomies: ' . implode( ', ', $taxes ) );
        WP_CLI::line( '' );

        $icl          = $wpdb->prefix . 'icl_translations';
        $has_icl      = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$icl}'" ); // phpcs:ignore WordPress.DB
        $total_groups = 0; $total_removed = 0; $total_slug_moves = 0; $total_reassigned = 0;

        foreach ( $taxes as $tax ) {
            // Pull every term in this taxonomy with its name, slug, and product count.
            $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
                "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id AS tt_id, tt.count
                 FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 WHERE tt.taxonomy = %s
                 ORDER BY t.name ASC, tt.count DESC, t.term_id ASC",
                $tax
            ) );

            // Group by name.
            $groups = [];
            foreach ( $rows as $r ) {
                $groups[ $r->name ][] = $r;
            }

            foreach ( $groups as $name => $members ) {
                if ( count( $members ) < 2 ) { continue; }
                $total_groups++;

                // Keeper = highest count (already sorted DESC), tie broken by lowest term_id.
                $keeper = $members[0];
                $dups   = array_slice( $members, 1 );

                WP_CLI::line( sprintf( '• [%s] "%s" — %d copies, keeping #%d (count=%d, slug=%s)',
                    $tax, $name, count( $members ), $keeper->term_id, $keeper->count, $keeper->slug ) );

                foreach ( $dups as $d ) {
                    // Never remove a duplicate that still has products unless we first
                    // move its products to the keeper.
                    $reassign = (int) $d->count;

                    // SEO: if the keeper has an auto 'ow-t-' slug and the dup has a clean one, take it.
                    $slug_move = false;
                    if ( strpos( $keeper->slug, 'ow-t-' ) === 0 && strpos( $d->slug, 'ow-t-' ) !== 0 ) {
                        $slug_move = true;
                    }

                    WP_CLI::line( sprintf( '    └ remove #%d (count=%d, slug=%s)%s%s',
                        $d->term_id, $d->count, $d->slug,
                        $reassign > 0 ? "  [reassign {$reassign} products → keeper]" : '',
                        $slug_move ? '  [give its clean slug to keeper]' : '' ) );

                    if ( ! $apply ) { $total_removed++; $total_reassigned += $reassign; $total_slug_moves += $slug_move ? 1 : 0; continue; }

                    // 1) Re-point any object relationships from dup → keeper.
                    if ( $reassign > 0 ) {
                        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
                            "UPDATE IGNORE {$wpdb->term_relationships}
                             SET term_taxonomy_id = %d WHERE term_taxonomy_id = %d",
                            $keeper->tt_id, $d->tt_id ) );
                        // Drop any rows that collided (object already had the keeper term).
                        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
                            "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $d->tt_id ) );
                        $total_reassigned += $reassign;
                    }

                    // 2) Transfer clean slug to keeper if warranted (free the dup's slug first).
                    if ( $slug_move ) {
                        $clean = $d->slug;
                        $wpdb->update( $wpdb->terms, [ 'slug' => $clean . '-old-' . $d->term_id ], [ 'term_id' => $d->term_id ] ); // phpcs:ignore WordPress.DB
                        $wpdb->update( $wpdb->terms, [ 'slug' => $clean ], [ 'term_id' => $keeper->term_id ] ); // phpcs:ignore WordPress.DB
                        $keeper->slug = $clean;
                        $total_slug_moves++;
                    }

                    // 3) Remove WPML translation rows for the dup.
                    if ( $has_icl ) {
                        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
                            "DELETE FROM {$icl} WHERE element_type = %s AND element_id = %d",
                            'tax_' . $tax, $d->tt_id ) );
                    }

                    // 4) Delete the duplicate term (now empty).
                    wp_delete_term( (int) $d->term_id, $tax );
                    $total_removed++;
                }
            }

            if ( $apply ) {
                // Recount the keeper terms so admin counts are accurate.
                $keep_ids = array_map( fn( $m ) => (int) $m[0]->term_id, array_filter( $groups, fn( $g ) => count( $g ) > 0 ) );
                if ( $keep_ids ) { wp_update_term_count_now( array_map( fn( $m ) => (int) $m[0]->tt_id, array_filter( $groups, fn($g)=>count($g)>0 ) ), $tax ); }
            }
        }

        WP_CLI::line( '' );
        if ( $apply ) {
            WP_CLI::success( sprintf( 'De-dupe complete. %d duplicate groups, %d terms removed, %d slugs preserved to keeper, %d product links reassigned.',
                $total_groups, $total_removed, $total_slug_moves, $total_reassigned ) );
            WP_CLI::line( 'Recommended next: flush caches and re-check WPML → Taxonomy Translation.' );
        } else {
            WP_CLI::warning( sprintf( 'DRY-RUN: would remove %d duplicates across %d groups (preserve %d clean slugs, reassign %d product links). Re-run with --apply to perform it.',
                $total_removed, $total_groups, $total_slug_moves, $total_reassigned ) );
        }
    }

}
