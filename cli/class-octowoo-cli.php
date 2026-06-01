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
        $total_groups = 0; $total_removed = 0; $total_reassigned = 0;

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

            // Group by name+slug TOGETHER. We only treat rows as duplicates when
            // they agree on BOTH — that is a true duplicate created by re-runs.
            // Rows that share a name but have different slugs (or share a slug across
            // different names) indicate scrambled data from an earlier bad run and
            // are NEVER auto-merged here — merging them would destroy distinct brands.
            $groups = [];
            foreach ( $rows as $r ) {
                $key = $r->name . "\x00" . $r->slug;
                $groups[ $key ][] = $r;
            }

            // Also detect slugs shared across DIFFERENT names — flag, never touch.
            $slug_names = [];
            foreach ( $rows as $r ) { $slug_names[ $r->slug ][ $r->name ] = true; }

            foreach ( $groups as $key => $members ) {
                if ( count( $members ) < 2 ) { continue; }

                [ $grp_name, $grp_slug ] = explode( "\x00", $key, 2 );

                // Safety: if this slug is used by more than one distinct name, the
                // data is scrambled — skip and report for manual review.
                if ( isset( $slug_names[ $grp_slug ] ) && count( $slug_names[ $grp_slug ] ) > 1 ) {
                    WP_CLI::warning( sprintf(
                        'SKIPPED [%s] "%s": slug "%s" is shared by %d different names — scrambled data, not auto-merging. Review manually.',
                        $tax, $grp_name, $grp_slug, count( $slug_names[ $grp_slug ] )
                    ) );
                    continue;
                }

                $total_groups++;

                // Keeper = highest count (already sorted DESC), tie broken by lowest term_id.
                $keeper = $members[0];
                $dups   = array_slice( $members, 1 );

                WP_CLI::line( sprintf( '• [%s] "%s" — %d identical copies (slug=%s), keeping #%d (count=%d)',
                    $tax, $grp_name, count( $members ), $grp_slug, $keeper->term_id, $keeper->count ) );

                foreach ( $dups as $d ) {
                    // All members share the same slug, so there is no slug to transfer;
                    // we simply reassign any product links to the keeper, then delete.
                    $reassign = (int) $d->count;

                    WP_CLI::line( sprintf( '    └ remove #%d (count=%d)%s',
                        $d->term_id, $d->count,
                        $reassign > 0 ? "  [reassign {$reassign} products → keeper #{$keeper->term_id}]" : '' ) );

                    if ( ! $apply ) { $total_removed++; $total_reassigned += $reassign; continue; }

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

                    // 2) Remove WPML translation rows for the dup.
                    if ( $has_icl ) {
                        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
                            "DELETE FROM {$icl} WHERE element_type = %s AND element_id = %d",
                            'tax_' . $tax, $d->tt_id ) );
                    }

                    // 3) Delete the duplicate term.
                    wp_delete_term( (int) $d->term_id, $tax );
                    $total_removed++;

                    // Recount the keeper so the admin count is accurate.
                    if ( $reassign > 0 ) { wp_update_term_count_now( [ (int) $keeper->tt_id ], $tax ); }
                }
            }
        }

        WP_CLI::line( '' );
        if ( $apply ) {
            WP_CLI::success( sprintf( 'De-dupe complete. %d true-duplicate groups, %d terms removed, %d product links reassigned. Scrambled/inconsistent groups were skipped (see warnings above).',
                $total_groups, $total_removed, $total_reassigned ) );
            WP_CLI::line( 'Recommended next: flush caches and re-check WPML → Taxonomy Translation.' );
        } else {
            WP_CLI::warning( sprintf( 'DRY-RUN: would remove %d true duplicates across %d groups (reassign %d product links). Groups with mismatched name/slug were SKIPPED for manual review. Re-run with --apply to perform the safe merges only.',
                $total_removed, $total_groups, $total_reassigned ) );
        }
    }

    /**
     * Audit (and optionally clean) orphan secondary-language term stubs.
     *
     * On a healthy WPML site every primary-language (English) term has exactly one
     * secondary-language (Arabic) translation, so the counts should match. When the
     * multilingual pass was run repeatedly it created extra Arabic term rows that are
     * NOT linked to any English parent ("orphan stubs") — which is why Arabic shows
     * far more terms than English. This command finds them.
     *
     * An Arabic term is an ORPHAN when it is EITHER:
     *   • flagged secondary-language in icl_translations but its trid has no
     *     primary-language row (its English parent is missing/never linked), OR
     *   • carries no product (count 0) AND has an auto 'ow-t-…' slug AND no
     *     product relationships — a leftover stub from a re-run.
     *
     * Orphans with products assigned are NEVER touched (they would be real data).
     *
     * DRY-RUN BY DEFAULT. Pass --apply to delete the orphan stubs.
     *
     * ## OPTIONS
     *
     * [--taxonomy=<tax>]
     * : Taxonomy to audit. Default: product_cat.
     *
     * [--apply]
     * : Delete the orphan stubs. Without this, only reports counts + samples.
     *
     * ## EXAMPLES
     *
     *     wp octowoo audit_translations --taxonomy=product_cat
     *     wp octowoo audit_translations --taxonomy=product_cat --apply
     *
     * @when after_wp_load
     */
    public function audit_translations( array $args, array $assoc_args ): void {
        global $wpdb;

        $apply = isset( $assoc_args['apply'] );
        $tax   = ! empty( $assoc_args['taxonomy'] ) ? sanitize_key( $assoc_args['taxonomy'] ) : 'product_cat';
        $icl   = $wpdb->prefix . 'icl_translations';
        $et    = 'tax_' . $tax;

        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$icl}'" ) ) { // phpcs:ignore WordPress.DB
            WP_CLI::error( 'WPML icl_translations table not found — nothing to audit.' );
        }

        $settings  = get_option( 'octowoo_settings', [] );
        $primary   = $settings['multilingual']['primary_locale']   ?? 'en';
        $secondary = $settings['multilingual']['secondary_locale'] ?? 'ar';

        WP_CLI::line( '' );
        WP_CLI::line( '╔══════════════════════════════════════════════════╗' );
        WP_CLI::line( '║   OctoWoo — Audit Orphan Translation Stubs       ║' );
        WP_CLI::line( '╚══════════════════════════════════════════════════╝' );
        WP_CLI::line( "Taxonomy: {$tax} | Primary: {$primary} | Secondary: {$secondary}" );
        WP_CLI::line( $apply ? 'Mode: APPLY' : 'Mode: DRY-RUN (no changes)' );
        WP_CLI::line( '' );

        // Counts by language.
        $by_lang = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
            "SELECT language_code, COUNT(*) c FROM `{$icl}` WHERE element_type=%s GROUP BY language_code", $et ) );
        foreach ( $by_lang as $r ) { WP_CLI::line( "  {$r->language_code}: {$r->c} terms" ); }
        WP_CLI::line( '' );

        // Orphan = secondary-language row whose trid has NO primary-language row,
        // AND the term has no products (count 0).
        $orphans = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
            "SELECT tt.term_id, t.name, t.slug, tt.count, icl.element_id AS tt_id, icl.trid
             FROM `{$icl}` icl
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = icl.element_id
             INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE icl.element_type = %s
               AND icl.language_code = %s
               AND tt.count = 0
               AND NOT EXISTS (
                   SELECT 1 FROM `{$icl}` p
                   WHERE p.trid = icl.trid AND p.language_code = %s
               )",
            $et, $secondary, $primary
        ) );

        $n = count( $orphans );
        WP_CLI::line( "Orphan {$secondary} stubs (no English parent, 0 products): {$n}" );
        WP_CLI::line( '' );

        // Show a sample so the user can sanity-check before deleting.
        $sample = array_slice( $orphans, 0, 15 );
        foreach ( $sample as $o ) {
            WP_CLI::line( sprintf( '   #%d  count=%d  slug=%s  name=%s', $o->term_id, $o->count, $o->slug, $o->name ) );
        }
        if ( $n > count( $sample ) ) { WP_CLI::line( '   … (' . ( $n - count( $sample ) ) . ' more)' ); }
        WP_CLI::line( '' );

        if ( ! $apply ) {
            WP_CLI::warning( "DRY-RUN: {$n} orphan stubs would be deleted. Review the sample above, then re-run with --apply." );
            return;
        }

        $deleted = 0;
        $bar = \WP_CLI\Utils\make_progress_bar( 'Deleting orphan stubs', max( 1, $n ) );
        foreach ( $orphans as $o ) {
            // Double-guard: never delete if it somehow has relationships.
            $rel = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $o->tt_id ) );
            if ( $rel > 0 ) { $bar->tick(); continue; }

            $wpdb->query( $wpdb->prepare( "DELETE FROM `{$icl}` WHERE element_type=%s AND element_id=%d", $et, $o->tt_id ) ); // phpcs:ignore WordPress.DB
            wp_delete_term( (int) $o->term_id, $tax );
            $deleted++;
            $bar->tick();
        }
        $bar->finish();

        WP_CLI::success( "Removed {$deleted} orphan {$secondary} stubs. {$secondary} term count should now match {$primary}." );
    }

    /**
     * Repair leftover 'ow-t-…' temporary term slugs back to clean slugs from the term name.
     *
     * The multilingual pass uses a temporary 'ow-t-{id}-{time}' slug while updating a
     * translated term, then overwrites it. When that overwrite failed on a re-run, the
     * temp slug stuck. This converts every 'ow-t-…' slug to a clean sanitized slug from
     * the term's name (skipping any that would collide with an existing clean slug).
     *
     * Runs in the terminal with no execution-time limit — unlike the admin button,
     * which can hit a PHP timeout ("request failed") on large catalogs.
     *
     * DRY-RUN BY DEFAULT. Pass --apply to write the slug changes.
     *
     * ## OPTIONS
     *
     * [--taxonomy=<tax>]
     * : Limit to one taxonomy. Default: product_cat + the active brand taxonomy.
     *
     * [--apply]
     * : Actually write the slug fixes. Without this, only reports.
     *
     * ## EXAMPLES
     *
     *     wp octowoo fix_slugs
     *     wp octowoo fix_slugs --apply
     *
     * @when after_wp_load
     */
    /**
     * Clean up leftover 'ow-t-…' temporary-slug terms.
     *
     * These are duplicate translation stubs from repeated runs. For each one we look
     * for an existing "clean" term with the SAME NAME in the same taxonomy:
     *
     *   • If a clean original exists (the normal case) → the ow-t- term is a
     *     DUPLICATE: any products on it are moved to the original, its WPML row is
     *     removed, and the duplicate term is deleted. The original (with its real
     *     slug and SEO) is kept untouched.
     *   • If NO clean original exists (the ow-t- term is the only copy of that name)
     *     → its slug is repaired in place using the Arabic-preserving slug format
     *     (NOT percent-encoded), so the URL stays readable.
     *
     * Runs in the terminal with no execution-time limit. DRY-RUN by default.
     *
     * ## OPTIONS
     *
     * [--taxonomy=<tax>]
     * : Limit to one taxonomy. Default: product_cat + the active brand taxonomy.
     *
     * [--apply]
     * : Actually perform the merge/repair. Without this, only reports.
     *
     * ## EXAMPLES
     *
     *     wp octowoo fix_slugs --taxonomy=product_cat
     *     wp octowoo fix_slugs --taxonomy=product_cat --apply
     *
     * @when after_wp_load
     */
    public function fix_slugs( array $args, array $assoc_args ): void {
        global $wpdb;
        @set_time_limit( 0 );

        $apply = isset( $assoc_args['apply'] );
        $taxes = [];
        if ( ! empty( $assoc_args['taxonomy'] ) ) {
            $taxes[] = sanitize_key( $assoc_args['taxonomy'] );
        } else {
            $taxes[] = 'product_cat';
            foreach ( [ 'product_brand', 'pwb-brand', 'yith_product_brand', 'product_manufacturer' ] as $bt ) {
                if ( taxonomy_exists( $bt ) ) { $taxes[] = $bt; break; }
            }
        }

        $icl     = $wpdb->prefix . 'icl_translations';
        $has_icl = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$icl}'" ); // phpcs:ignore WordPress.DB

        WP_CLI::line( '' );
        WP_CLI::line( '╔══════════════════════════════════════════════════╗' );
        WP_CLI::line( '║   OctoWoo — Clean ow-t- Duplicate Stubs          ║' );
        WP_CLI::line( '╚══════════════════════════════════════════════════╝' );
        WP_CLI::line( $apply ? 'Mode: APPLY' : 'Mode: DRY-RUN (no changes)' );
        WP_CLI::line( 'Taxonomies: ' . implode( ', ', $taxes ) );
        WP_CLI::line( '' );

        $merged = 0; $repaired = 0; $reassigned = 0; $skipped = 0;

        foreach ( $taxes as $tax ) {
            $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
                "SELECT t.term_id, t.slug, t.name, tt.term_taxonomy_id AS tt_id, tt.count
                 FROM {$wpdb->terms} t
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 WHERE tt.taxonomy = %s AND t.slug LIKE %s",
                $tax, $wpdb->esc_like( 'ow-t-' ) . '%'
            ) );

            WP_CLI::line( sprintf( '[%s] %d ow-t- terms to process', $tax, count( $rows ) ) );

            foreach ( $rows as $r ) {
                // Determine the duplicate's WPML language (if registered).
                $dup_lang = $has_icl ? (string) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
                    "SELECT language_code FROM `{$icl}` WHERE element_type=%s AND element_id=%d LIMIT 1",
                    'tax_' . $tax, $r->tt_id ) ) : '';

                // Find a clean (non ow-t-) term with the SAME name = the real original.
                // SAFETY: when both are WPML-registered, require the SAME language so we
                // never attach (e.g.) Arabic products to an English category term.
                $orig = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
                    "SELECT t.term_id, t.slug, tt.term_taxonomy_id AS tt_id,
                            (SELECT language_code FROM `{$icl}` WHERE element_type=%s AND element_id=tt.term_taxonomy_id LIMIT 1) AS lang
                     FROM {$wpdb->terms} t
                     JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                     WHERE tt.taxonomy = %s AND t.name = %s AND t.slug NOT LIKE %s
                     ORDER BY tt.count DESC, t.term_id ASC LIMIT 1",
                    'tax_' . $tax, $tax, $r->name, $wpdb->esc_like( 'ow-t-' ) . '%'
                ) );

                // If both languages are known and differ, do NOT merge — flag instead.
                if ( $orig && $has_icl && $dup_lang !== '' && ! empty( $orig->lang ) && $dup_lang !== $orig->lang ) {
                    WP_CLI::warning( sprintf( '    SKIP merge #%d (%s) → #%d (%s): language mismatch, review manually.',
                        $r->term_id, $dup_lang, $orig->term_id, $orig->lang ) );
                    $skipped++;
                    continue;
                }

                if ( $orig ) {
                    // DUPLICATE → merge into original and delete.
                    // Count ACTUAL relationship rows on the duplicate (the cached
                    // tt.count can be stale/0 under WPML even when links exist), so
                    // we always move whatever is really attached.
                    $real_links = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
                        "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id=%d", $r->tt_id ) );

                    WP_CLI::line( sprintf( '    merge #%d (count=%d, real links=%d) → original #%d "%s" [%s]',
                        $r->term_id, $r->count, $real_links, $orig->term_id, $orig->slug, $r->name ) );

                    $reassigned += $real_links;

                    if ( $apply ) {
                        if ( $real_links > 0 ) {
                            $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
                                "UPDATE IGNORE {$wpdb->term_relationships} SET term_taxonomy_id=%d WHERE term_taxonomy_id=%d",
                                $orig->tt_id, $r->tt_id ) );
                            // Remove any leftover rows that collided (object already on original).
                            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id=%d", $r->tt_id ) ); // phpcs:ignore WordPress.DB
                        }
                        if ( $has_icl ) {
                            $wpdb->query( $wpdb->prepare( "DELETE FROM `{$icl}` WHERE element_type=%s AND element_id=%d", 'tax_' . $tax, $r->tt_id ) ); // phpcs:ignore WordPress.DB
                        }
                        wp_delete_term( (int) $r->term_id, $tax );
                        wp_update_term_count_now( [ (int) $orig->tt_id ], $tax );
                    }
                    $merged++;
                } else {
                    // UNIQUE → repair slug in place using Arabic-preserving format.
                    $clean = $this->cleanSlug( $r->name );
                    if ( $clean === '' || $clean === $r->slug ) { $skipped++; continue; }
                    $owner = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
                        "SELECT term_id FROM {$wpdb->terms} WHERE slug=%s AND term_id<>%d LIMIT 1", $clean, $r->term_id ) );
                    if ( $owner > 0 ) { $skipped++; continue; }
                    WP_CLI::line( sprintf( '    repair #%d  %s → %s   (%s)', $r->term_id, $r->slug, $clean, $r->name ) );
                    if ( $apply ) {
                        $wpdb->update( $wpdb->terms, [ 'slug' => $clean ], [ 'term_id' => (int) $r->term_id ] ); // phpcs:ignore WordPress.DB
                        clean_term_cache( (int) $r->term_id, $tax );
                    }
                    $repaired++;
                }
            }
        }

        WP_CLI::line( '' );
        if ( $apply ) {
            WP_CLI::success( sprintf( 'Done. Merged %d duplicates (reassigned %d product links), repaired %d unique slugs, skipped %d.',
                $merged, $reassigned, $repaired, $skipped ) );
        } else {
            WP_CLI::warning( sprintf( 'DRY-RUN: would merge %d duplicates (reassign %d product links), repair %d unique slugs, skip %d. Re-run with --apply.',
                $merged, $reassigned, $repaired, $skipped ) );
        }
    }

    /**
     * Arabic/Unicode-preserving slug — same approach as AbstractMigrator::toSlug,
     * so we never produce percent-encoded slugs like sanitize_title() does.
     */
    private function cleanSlug( string $text ): string {
        $slug = mb_strtolower( trim( $text ), 'UTF-8' );
        $slug = preg_replace( '/[\s\x{200B}\x{200C}\x{200D}\x{FEFF}]+/u', '-', $slug );
        $slug = preg_replace( '/[^\p{L}\p{N}\-\.]/u', '', $slug );
        $slug = preg_replace( '/-{2,}/', '-', $slug );
        return trim( (string) $slug, '-' );
    }

    /**
     * Relabel terms that were mislabeled as the secondary language back to primary.
     *
     * The multilingual pass mislabeled some PRIMARY-language (English) terms as
     * SECONDARY (Arabic) in WPML — e.g. a category named "Graphics Cards" sitting in
     * the Arabic language bucket with no English parent. This finds secondary-flagged
     * terms whose NAME contains NO secondary-language (Arabic) characters AND that
     * have no same-named primary-language twin, and flips their icl_translations
     * language_code back to the primary language. Each is moved to its OWN new trid
     * (it becomes a standalone primary-language term), so nothing is merged or deleted.
     *
     * NOTHING is deleted; no products move. DRY-RUN by default.
     *
     * ## OPTIONS
     *
     * [--taxonomy=<tax>]
     * : Taxonomy to fix. Default: product_cat.
     *
     * [--apply]
     * : Write the language changes. Without this, only reports.
     *
     * ## EXAMPLES
     *
     *     wp octowoo fix_term_language --taxonomy=product_cat
     *     wp octowoo fix_term_language --taxonomy=product_cat --apply
     *
     * @when after_wp_load
     */
    public function fix_term_language( array $args, array $assoc_args ): void {
        global $wpdb;
        @set_time_limit( 0 );

        $apply = isset( $assoc_args['apply'] );
        $tax   = ! empty( $assoc_args['taxonomy'] ) ? sanitize_key( $assoc_args['taxonomy'] ) : 'product_cat';
        $icl   = $wpdb->prefix . 'icl_translations';
        $et    = 'tax_' . $tax;

        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$icl}'" ) ) { // phpcs:ignore WordPress.DB
            WP_CLI::error( 'WPML icl_translations table not found.' );
        }

        $settings  = get_option( 'octowoo_settings', [] );
        $primary   = $settings['multilingual']['primary_locale']   ?? 'en';
        $secondary = $settings['multilingual']['secondary_locale'] ?? 'ar';

        WP_CLI::line( '' );
        WP_CLI::line( '╔══════════════════════════════════════════════════╗' );
        WP_CLI::line( '║   OctoWoo — Relabel Mislabeled Terms             ║' );
        WP_CLI::line( '╚══════════════════════════════════════════════════╝' );
        WP_CLI::line( "Taxonomy: {$tax} | Primary: {$primary} | Secondary: {$secondary}" );
        WP_CLI::line( $apply ? 'Mode: APPLY' : 'Mode: DRY-RUN (no changes)' );
        WP_CLI::line( '' );

        // Secondary-flagged terms whose name has NO Arabic characters AND no primary twin of the same name.
        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
            "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id AS tt_id, icl.translation_id AS icl_id, icl.trid
             FROM `{$icl}` icl
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = icl.element_id
             JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE icl.element_type = %s
               AND icl.language_code = %s
               AND t.name NOT REGEXP '[ء-ي]'
               AND NOT EXISTS (
                   SELECT 1 FROM `{$icl}` e
                   JOIN {$wpdb->term_taxonomy} ett ON ett.term_taxonomy_id = e.element_id
                   JOIN {$wpdb->terms} et ON et.term_id = ett.term_id
                   WHERE e.element_type = %s AND e.language_code = %s AND et.name = t.name
               )",
            $et, $secondary, $et, $primary
        ) );

        $n = count( $rows );
        WP_CLI::line( "Mislabeled {$secondary}→{$primary} terms (English-named, no {$primary} twin): {$n}" );
        WP_CLI::line( '' );
        foreach ( array_slice( $rows, 0, 20 ) as $r ) {
            WP_CLI::line( sprintf( '   #%d  %s  (%s)', $r->term_id, $r->slug, $r->name ) );
        }
        if ( $n > 20 ) { WP_CLI::line( '   … (' . ( $n - 20 ) . ' more)' ); }
        WP_CLI::line( '' );

        if ( ! $apply ) {
            WP_CLI::warning( "DRY-RUN: {$n} terms would be relabeled to {$primary} (own new trid). Nothing deleted. Re-run with --apply." );
            return;
        }

        $done = 0;
        $bar = \WP_CLI\Utils\make_progress_bar( "Relabeling to {$primary}", max( 1, $n ) );
        foreach ( $rows as $r ) {
            // Give each its own fresh trid as a standalone primary-language term.
            $new_trid = 1 + (int) $wpdb->get_var( "SELECT COALESCE(MAX(trid),0) FROM `{$icl}`" ); // phpcs:ignore WordPress.DB
            $wpdb->update( // phpcs:ignore WordPress.DB
                $icl,
                [ 'language_code' => $primary, 'source_language_code' => null, 'trid' => $new_trid ],
                [ 'translation_id' => (int) $r->icl_id ]
            );
            $done++;
            $bar->tick();
        }
        $bar->finish();

        WP_CLI::success( "Relabeled {$done} terms to {$primary}. They are now standalone {$primary} categories (no data lost)." );
        WP_CLI::line( "Re-check: {$primary} count should rise by ~{$done}, {$secondary} drop by the same." );
    }

    /**
     * Reset ONLY product categories (clean slate) — deletes every product_cat term,
     * its relationships, meta, and WPML rows, and clears the category id_map. Nothing
     * else is touched (products, brands, orders, customers all remain).
     *
     * Requires --confirm to run. Without it, only reports how many would be deleted.
     *
     * ## OPTIONS
     *
     * [--confirm]
     * : Required to actually delete.
     *
     * ## EXAMPLES
     *
     *     wp octowoo reset_categories
     *     wp octowoo reset_categories --confirm
     *
     * @when after_wp_load
     */
    public function reset_categories( array $args, array $assoc_args ): void {
        global $wpdb;
        @set_time_limit( 0 );

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy='product_cat'" ); // phpcs:ignore WordPress.DB

        WP_CLI::line( '' );
        WP_CLI::line( '╔══════════════════════════════════════════════════╗' );
        WP_CLI::line( '║   OctoWoo — Reset Product Categories (only)      ║' );
        WP_CLI::line( '╚══════════════════════════════════════════════════╝' );
        WP_CLI::line( "Current product_cat terms: {$count}" );
        WP_CLI::line( 'Deletes ONLY category terms (+ their WPML rows + id_map).' );
        WP_CLI::line( 'Products, brands, orders, customers are NOT touched.' );
        WP_CLI::line( '' );

        if ( ! isset( $assoc_args['confirm'] ) ) {
            WP_CLI::warning( "Dry: would delete {$count} category terms. Re-run with --confirm to proceed." );
            return;
        }

        $config = get_option( 'octowoo_settings', [] );
        $logger = new \OctoWoo\Core\Logger( 'cli-reset-cats-' . date( 'YmdHis' ) );
        $purger = new \OctoWoo\Core\DataPurger( $logger, $config );

        $ref = new \ReflectionClass( $purger );
        $m   = $ref->getMethod( 'purgeCategories' );
        $m->setAccessible( true );
        $deleted = (int) $m->invoke( $purger, true ); // force = true

        clean_taxonomy_cache( 'product_cat' );
        flush_rewrite_rules( false );

        $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy='product_cat'" ); // phpcs:ignore WordPress.DB
        WP_CLI::success( "Reset complete. Deleted {$deleted} category terms. Remaining: {$remaining}." );
        WP_CLI::line( 'Next: wp octowoo migrate --migrators=categories   (clean re-import)' );
    }

    /**
     * Re-link products to their categories from the OpenCart source — WITHOUT
     * re-importing products. Use this after reset_categories + a category re-import,
     * which recreates category terms but leaves products unassigned.
     *
     * For each row in OpenCart's product_to_category, it finds the WC product (by
     * _octowoo_oc_id) and the WC category term (by its _octowoo_oc_id) and assigns
     * the product to that category. Pure DB lookups; no remote downloads.
     *
     * DRY-RUN by default. Pass --apply to write the assignments.
     *
     * ## OPTIONS
     *
     * [--apply]
     * : Actually assign categories. Without this, only reports counts.
     *
     * ## EXAMPLES
     *
     *     wp octowoo relink_categories
     *     wp octowoo relink_categories --apply
     *
     * @when after_wp_load
     */
    public function relink_categories( array $args, array $assoc_args ): void {
        global $wpdb;
        @set_time_limit( 0 );

        $apply  = isset( $assoc_args['apply'] );
        $config = \OctoWoo\Admin\AdminPage::getConfig();

        WP_CLI::line( '' );
        WP_CLI::line( '╔══════════════════════════════════════════════════╗' );
        WP_CLI::line( '║   OctoWoo — Re-link Products → Categories         ║' );
        WP_CLI::line( '╚══════════════════════════════════════════════════╝' );
        WP_CLI::line( $apply ? 'Mode: APPLY' : 'Mode: DRY-RUN (no changes)' );
        WP_CLI::line( '' );

        // OpenCart source: product_id → [category_id,...]
        $db_config           = $config['db'] ?? [];
        $db_config['source'] = $config['source'] ?? 'remote';
        $oc = new \OctoWoo\Core\DatabaseConnector( $db_config );
        $oc_pfx = $config['db']['prefix'] ?? 'oc_';
        $rows = $oc->fetchAll( "SELECT product_id, category_id FROM `{$oc_pfx}product_to_category`" );
        if ( empty( $rows ) ) {
            WP_CLI::error( 'No product_to_category rows found in the OpenCart database.' );
        }
        $oc_map = [];
        foreach ( $rows as $r ) { $oc_map[ (int) $r['product_id'] ][] = (int) $r['category_id']; }

        // Build OC category_id → WC term_id (from term meta), once.
        $cat_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
            "SELECT tm.meta_value AS oc_id, tm.term_id
             FROM {$wpdb->termmeta} tm
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id AND tt.taxonomy='product_cat'
             WHERE tm.meta_key='_octowoo_oc_id'"
        );
        $cat_map = [];
        foreach ( $cat_rows as $cr ) { $cat_map[ (int) $cr->oc_id ] = (int) $cr->term_id; }

        // Build OC product_id → WC post_id (from post meta), once.
        $prod_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
            "SELECT pm.meta_value AS oc_id, pm.post_id
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type='product'
             WHERE pm.meta_key='_octowoo_oc_id'"
        );
        $prod_map = [];
        foreach ( $prod_rows as $pr ) { $prod_map[ (int) $pr->oc_id ][] = (int) $pr->post_id; }

        // For WPML language-correct linking: map each English category term_id to its
        // Arabic translation term_id (same trid). English products link to English
        // terms; Arabic twin products link to the Arabic term of the same category.
        $icl = $wpdb->prefix . 'icl_translations';
        $has_icl = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$icl}'" ); // phpcs:ignore WordPress.DB
        $secondary = $config['multilingual']['secondary_locale'] ?? 'ar';
        $en_to_ar_term = [];
        if ( $has_icl ) {
            $pairs = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
                "SELECT en_tt.term_id AS en_term, ar_tt.term_id AS ar_term
                 FROM `{$icl}` en
                 JOIN `{$icl}` ar ON ar.trid = en.trid AND ar.element_type = en.element_type AND ar.language_code = %s
                 JOIN {$wpdb->term_taxonomy} en_tt ON en_tt.term_taxonomy_id = en.element_id
                 JOIN {$wpdb->term_taxonomy} ar_tt ON ar_tt.term_taxonomy_id = ar.element_id
                 WHERE en.element_type = 'tax_product_cat' AND en.language_code = 'en'",
                $secondary
            ) );
            foreach ( $pairs as $p ) { $en_to_ar_term[ (int) $p->en_term ] = (int) $p->ar_term; }
        }
        // Which post IDs are Arabic twins (carry _octowoo_translation_of)?
        $ar_posts = array_map( 'intval', (array) $wpdb->get_col( // phpcs:ignore WordPress.DB
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_octowoo_translation_of'"
        ) );
        $ar_posts = array_flip( $ar_posts );

        WP_CLI::line( sprintf( 'OC products with categories: %d | WC categories mapped: %d | WC products mapped: %d | EN→AR cat pairs: %d',
            count( $oc_map ), count( $cat_map ), count( $prod_map ), count( $en_to_ar_term ) ) );
        WP_CLI::line( '' );

        $linked = 0; $missing_cat = 0; $missing_prod = 0;
        $bar = \WP_CLI\Utils\make_progress_bar( 'Linking', max( 1, count( $oc_map ) ) );

        foreach ( $oc_map as $oc_pid => $oc_cat_ids ) {
            $wc_post_ids = $prod_map[ $oc_pid ] ?? [];
            if ( empty( $wc_post_ids ) ) { $missing_prod++; $bar->tick(); continue; }

            $en_terms = [];
            foreach ( array_unique( $oc_cat_ids ) as $cid ) {
                if ( isset( $cat_map[ $cid ] ) ) { $en_terms[] = $cat_map[ $cid ]; }
                else { $missing_cat++; }
            }
            if ( empty( $en_terms ) ) { $bar->tick(); continue; }

            foreach ( $wc_post_ids as $pid ) {
                if ( isset( $ar_posts[ $pid ] ) ) {
                    // Arabic twin → use Arabic category terms where available, else English.
                    $terms = [];
                    foreach ( $en_terms as $t ) { $terms[] = $en_to_ar_term[ $t ] ?? $t; }
                } else {
                    $terms = $en_terms;
                }
                if ( $apply ) {
                    wp_set_object_terms( $pid, array_values( array_unique( $terms ) ), 'product_cat', false );
                }
                $linked++;
            }
            $bar->tick();
        }
        $bar->finish();

        WP_CLI::line( '' );
        if ( $apply ) {
            WP_CLI::success( sprintf( 'Linked %d products to categories. (%d OC categories had no WC term, %d OC products had no WC post.)',
                $linked, $missing_cat, $missing_prod ) );
        } else {
            WP_CLI::warning( sprintf( 'DRY-RUN: would link %d products. (%d OC categories unmapped, %d OC products unmapped.) Re-run with --apply.',
                $linked, $missing_cat, $missing_prod ) );
        }
    }

}
