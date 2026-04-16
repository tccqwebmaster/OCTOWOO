<?php
/**
 * Migration manager – top-level orchestrator.
 *
 * Responsibilities:
 *  1. Load and merge configuration (defaults ← saved options ← runtime overrides).
 *  2. Instantiate shared services (DatabaseConnector, Logger, CheckpointManager, BatchProcessor).
 *  3. Instantiate and run each migrator in the correct order.
 *  4. Expose a progress snapshot for the admin UI / CLI progress bar.
 *  5. Support pause / abort via a DB flag.
 */

namespace OctoWoo\Core;

use OctoWoo\Migrators\CategoryMigrator;
use OctoWoo\Migrators\ImageMigrator;
use OctoWoo\Migrators\ProductMigrator;
use OctoWoo\Migrators\CustomerMigrator;
use OctoWoo\Migrators\OrderMigrator;
use OctoWoo\Migrators\CouponMigrator;
use OctoWoo\Migrators\SeoMigrator;
use OctoWoo\Migrators\InformationMigrator;
use OctoWoo\Migrators\TagMigrator;
use OctoWoo\Migrators\FilterMigrator;
use OctoWoo\Migrators\ReviewMigrator;
use OctoWoo\Migrators\DownloadMigrator;
use OctoWoo\Migrators\ManufacturerMigrator;
use OctoWoo\Migrators\TaxMigrator;
use OctoWoo\Migrators\RelatedProductsMigrator;
use OctoWoo\Migrators\OrderStatusMigrator;
use OctoWoo\Migrators\BundleMigrator;
use OctoWoo\Integration\WpmlIntegration;
use OctoWoo\Integration\AddonManager;

defined( 'ABSPATH' ) || exit;

class MigrationManager {

    /** @var array<string, mixed> Resolved configuration. */
    private array $config;

    /** @var DatabaseConnector */
    private DatabaseConnector $db;

    /** @var Logger */
    private Logger $logger;

    /** @var CheckpointManager */
    private CheckpointManager $checkpoint;

    /** @var BatchProcessor */
    private BatchProcessor $batch;

    /** @var string Unique identifier for this run. */
    private string $run_id;

    /** @var callable|null Progress hook – signature: fn(string $migrator, int $done, int $total) */
    private $progress_hook = null;

    /** @var array<string, array{processed:int,skipped:int,failed:int}> Per-migrator stats. */
    private array $stats = [];

    // ── Migrator execution order ──────────────────────────────────────────────
    private const MIGRATOR_ORDER = [
        'tax',             // Tax classes – before products so the map is ready
        'order_statuses',  // Custom order statuses – before orders
        'categories',
        'manufacturers',   // Brands – before products so ID map is ready
        'images',
        'products',
        'related',         // Related products – requires products to be migrated first
        'bundles',         // Product bundles – requires products to be migrated first
        'customers',
        'orders',
        'coupons',
        'seo',
        'information',
        'tags',            // Requires products to be migrated first
        'filters',         // Requires products to be migrated first
        'downloads',       // Requires products to be migrated first
        'reviews',         // Requires products + customers to be migrated first
        'multilingual',    // Always last – reads from ID map
    ];

    // ── Construction ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $runtime_overrides  Key-value pairs that override saved options.
     * @param string|null          $run_id             Supply an existing run ID to resume, or null for a fresh run.
     */
    public function __construct( array $runtime_overrides = [], ?string $run_id = null ) {
        $this->config = $this->buildConfig( $runtime_overrides );
        $this->run_id = $run_id ?? $this->generateRunId();
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Register a callback invoked after each batch across all migrators.
     *
     * @param callable $cb  Signature: fn(string $migrator, int $processed, int $total): void
     */
    public function onProgress( callable $cb ): void {
        $this->progress_hook = $cb;
    }

    /**
     * Execute the full migration (or resume an interrupted one).
     *
     * @return array<string, mixed>  Summary report.
     */
    public function run( bool $resume = true ): array {
        $this->bootstrap();

        $this->checkpoint->markRunActive();
        AddonManager::loadAddons( $this->config );
        WpmlIntegration::registerHooks( $this->config );

        $this->logger->info( 'Migration started', [
            'run_id'  => $this->run_id,
            'dry_run' => $this->config['migration']['dry_run'],
        ] );

        $migration_config = $this->config['migration'];

        // Pre-populate checkpoint rows for all enabled migrators so
        // getAll() always returns data for the progress UI.
        foreach ( self::MIGRATOR_ORDER as $key ) {
            $run_key = 'run_' . $key;
            if ( isset( $migration_config[ $run_key ] ) && ! $migration_config[ $run_key ] ) {
                continue;
            }
            $this->checkpoint->ensureExists( $key );
        }

        foreach ( self::MIGRATOR_ORDER as $key ) {
            if ( $this->isAborted() ) {
                $this->logger->warning( 'Migration aborted by user flag.' );
                break;
            }

            $run_key = 'run_' . $key;
            if ( isset( $migration_config[ $run_key ] ) && ! $migration_config[ $run_key ] ) {
                $this->logger->info( "Skipping [{$key}] – disabled in config." );
                continue;
            }

            try {
                $this->runMigrator( $key );
            } catch ( \Throwable $e ) {
                $this->logger->error(
                    "Migrator [{$key}] threw an unhandled exception: " . $e->getMessage(),
                    [ 'trace' => $e->getTraceAsString() ]
                );
                // Continue with the next migrator instead of aborting the entire run.
            }
        }

        $this->logger->flush();
        $this->checkpoint->markRunFinished();

        $report = $this->buildReport();
        $this->logger->info( 'Migration finished.', $report );

        /**
         * Fires when the migration run is fully complete.
         *
         * @param string $run_id  The run identifier.
         * @param array  $report  Summary report.
         * @param array  $config  Resolved config.
         */
        do_action( 'octowoo_migration_finished', $this->run_id, $report, $this->config );

        update_option( 'octowoo_last_run_id', $this->run_id );
        update_option( 'octowoo_last_run_at', current_time( 'mysql' ) );

        return $report;
    }

    /**
     * Signal an abort by setting a transient; the next batch loop checks it.
     */
    public static function requestAbort( string $run_id ): void {
        set_transient( 'octowoo_abort_' . $run_id, '1', HOUR_IN_SECONDS );
    }

    /**
     * Process exactly ONE batch of the next pending migrator (chunked mode).
     *
     * Each AJAX request calls this once.  JS keeps calling until done_all=true.
     * This way no single request runs for more than batch_size × item_time,
     * so it never hits PHP/Nginx execution-time limits.
     *
     * @return array{
     *   done_all: bool,
     *   migrator: string,
     *   chunk: array,
     *   checkpoints: array,
     *   report: array|null
     * }
     */
    public function runNextChunk(): array {
        $this->bootstrap();

        // ── Concurrency guard ─────────────────────────────────────────────────
        // Prevents two simultaneous AJAX chunk requests from running the same
        // batch (race condition in fast-clicking browsers or server-side retries).
        $lock_key = 'octowoo_chunk_lock_' . $this->run_id;
        if ( get_transient( $lock_key ) ) {
            return [
                'done_all'    => false,
                'busy'        => true,
                'migrator'    => '',
                'chunk'       => [],
                'checkpoints' => [],
                'report'      => null,
            ];
        }
        set_transient( $lock_key, '1', 90 ); // 90 s — well beyond any single chunk.

        try {
            $res = $this->doRunNextChunk();
            return $res;
        } finally {
            // Always flush logs and release the lock.
            try { $this->logger->flush(); } catch ( \Throwable $e ) { /* ignore */ }
            delete_transient( $lock_key );
        }
    }

    /**
     * Internal implementation — called inside the lock acquired by runNextChunk().
     */
    private function doRunNextChunk(): array {
        $this->checkpoint->markRunActive();

        AddonManager::loadAddons( $this->config );
        WpmlIntegration::registerHooks( $this->config );

        if ( $this->isAborted() ) {
            $this->logger->warning( 'Chunk run aborted by user flag.' );
            $this->logger->flush();
            $this->checkpoint->markRunFinished();
            return [
                'done_all'    => true,
                'aborted'     => true,
                'migrator'    => '',
                'chunk'       => [],
                'checkpoints' => $this->checkpoint->getAll(),
                'report'      => null,
            ];
        }

        $migration_config = $this->config['migration'];

        // Pre-populate checkpoint rows for ALL enabled migrators so the UI
        // always has data to render in the progress table — even before the
        // first migrator actually starts processing.
        foreach ( self::MIGRATOR_ORDER as $key ) {
            $run_key = 'run_' . $key;
            if ( isset( $migration_config[ $run_key ] ) && ! $migration_config[ $run_key ] ) {
                continue;
            }
            $this->checkpoint->ensureExists( $key );
        }

        foreach ( self::MIGRATOR_ORDER as $key ) {
            // Skip disabled migrators.
            $run_key = 'run_' . $key;
            if ( isset( $migration_config[ $run_key ] ) && ! $migration_config[ $run_key ] ) {
                continue;
            }

            // Skip already-completed or permanently-failed migrators.
            if ( $this->checkpoint->isCompleted( $key ) || $this->checkpoint->isFailed( $key ) ) {
                continue;
            }

            // Run ONE batch of this migrator.
            try {
                $chunk = $this->runMigratorChunk( $key );
                $this->stats[ $key ] = $chunk;
            } catch ( \Throwable $e ) {
                $this->logger->error(
                    "Chunk [{$key}] threw exception: " . $e->getMessage(),
                    [ 'trace' => $e->getTraceAsString() ]
                );
                // Mark the migrator as FAILED so it is skipped on the next chunk
                // request instead of being retried in an infinite loop.
                $this->checkpoint->fail( $key );
                $chunk = [ 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'is_done' => true ];
            }

            $this->logger->flush();

            return [
                'done_all'    => false,
                'migrator'    => $key,
                'chunk'       => $chunk,
                'checkpoints' => $this->checkpoint->getAll(),
                'report'      => null,
            ];
        }

        // All migrators done.
        $this->logger->flush();
        $this->checkpoint->markRunFinished();

        $report = $this->buildReport();
        $this->logger->info( 'Migration finished (chunked).', $report );

        do_action( 'octowoo_migration_finished', $this->run_id, $report, $this->config );

        update_option( 'octowoo_last_run_id', $this->run_id );
        update_option( 'octowoo_last_run_at', current_time( 'mysql' ) );

        return [
            'done_all'    => true,
            'aborted'     => false,
            'migrator'    => '',
            'chunk'       => [],
            'checkpoints' => $this->checkpoint->getAll(),
            'report'      => $report,
        ];
    }

    /**
     * Return an overall progress snapshot (used by admin AJAX polling).
     *
     * @return array<string, mixed>
     */
    public function getProgress(): array {
        $checkpoints = $this->checkpoint->getAll();
        $snapshot    = [];

        foreach ( $checkpoints as $row ) {
            $snapshot[ $row['migrator'] ] = [
                'status'    => $row['status'],
                'processed' => (int) $row['processed_count'],
                'total'     => (int) $row['total_count'],
                'pct'       => $row['total_count'] > 0
                    ? round( $row['processed_count'] / $row['total_count'] * 100, 1 )
                    : 0,
            ];
        }

        return $snapshot;
    }

    // ── Bootstrap ─────────────────────────────────────────────────────────────

    private function bootstrap(): void {
        // Ensure the plugin's DB tables exist (covers plugin upgrades where
        // activation hook was not re-fired after files were replaced).
        \OctoWoo_Activator::maybeCreateTables();

        // Logger is created BEFORE the OC database connection so that bootstrap
        // errors (e.g. wrong DB credentials) are captured in logs/DB.
        $this->logger = new Logger(
            $this->run_id,
            $this->config['logging'] ?? []
        );

        $this->checkpoint = new CheckpointManager( $this->run_id );

        // Inject the top-level 'source' flag into the db config so DatabaseConnector
        // can switch to the locally-imported WP tables when source = 'local'.
        // Without this, local-mode migration always tries the remote OC DB and fails.
        $db_config           = $this->config['db'];
        $db_config['source'] = $this->config['source'] ?? 'remote';

        try {
            $this->db = new DatabaseConnector( $db_config );
            $this->db->connect(); // Validate credentials early; throws on failure.
        } catch ( \Throwable $e ) {
            $this->logger->error(
                'Cannot connect to OpenCart database. Check your Settings → Database Connection.',
                [ 'error' => $e->getMessage() ]
            );
            $this->logger->flush();
            throw $e;
        }

        $this->batch = new BatchProcessor(
            $this->logger,
            $this->config['migration'] ?? []
        );

        if ( $this->progress_hook ) {
            $cb = $this->progress_hook;
            $this->batch->onProgress( function ( int $done, int $total, string $migrator ) use ( $cb ): void {
                $cb( $migrator, $done, $total );
            } );
        }
    }

    // ── Individual migrator dispatch ──────────────────────────────────────────

    private function runMigrator( string $key ): void {
        $migrator = $this->buildMigrator( $key );

        if ( ! $migrator ) {
            $this->logger->warning( "No migrator found for key [{$key}]." );
            return;
        }

        $this->logger->setMigrator( $key );
        $this->logger->info( "Running migrator: [{$key}]" );

        $result = $migrator->migrate();

        $this->stats[ $key ] = $result;
    }

    /**
     * Run one batch of $key (chunk mode) – used by runNextChunk().
     *
     * @return array{processed:int,skipped:int,failed:int,is_done:bool}
     */
    private function runMigratorChunk( string $key ): array {
        $migrator = $this->buildMigrator( $key );

        if ( ! $migrator ) {
            $this->logger->warning( "No migrator found for key [{$key}]." );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'is_done' => true ];
        }

        $this->logger->setMigrator( $key );
        $this->batch->setChunkMode( true );

        $result = $migrator->migrate(); // migrate() calls batch->run() which delegates to runChunk()

        $this->batch->setChunkMode( false );

        // Ensure is_done is always present.
        $result['is_done'] = $result['is_done'] ?? $this->checkpoint->isCompleted( $key );

        return $result;
    }

    /**
     * Factory: instantiate the correct migrator class.
     *
     * @return \OctoWoo\Migrators\AbstractMigrator|null
     */
    private function buildMigrator( string $key ): ?\OctoWoo\Migrators\AbstractMigrator {
        $shared = [ $this->db, $this->logger, $this->checkpoint, $this->batch, $this->config ];

        return match ( $key ) {
            'tax'            => new TaxMigrator( ...$shared ),
            'order_statuses' => new OrderStatusMigrator( ...$shared ),
            'categories'     => new CategoryMigrator( ...$shared ),
            'images'         => new ImageMigrator( ...$shared ),
            'products'       => new ProductMigrator( ...$shared ),
            'related'        => new RelatedProductsMigrator( ...$shared ),
            'bundles'        => new BundleMigrator( ...$shared ),
            'customers'      => new CustomerMigrator( ...$shared ),
            'orders'         => new OrderMigrator( ...$shared ),
            'coupons'        => new CouponMigrator( ...$shared ),
            'seo'            => new SeoMigrator( ...$shared ),
            'information'    => new InformationMigrator( ...$shared ),
            'manufacturers'  => new ManufacturerMigrator( ...$shared ),
            'tags'           => new TagMigrator( ...$shared ),
            'filters'        => new FilterMigrator( ...$shared ),
            'downloads'      => new DownloadMigrator( ...$shared ),
            'reviews'        => new ReviewMigrator( ...$shared ),
            'multilingual'   => new WpmlIntegration( ...$shared ),
            default          => null,
        };
    }

    // ── Config builder ────────────────────────────────────────────────────────

    /**
     * Merge: defaults ← saved DB options ← runtime overrides.
     * Deep-merges nested arrays so partial overrides work correctly.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function buildConfig( array $overrides ): array {
        $defaults = require OCTOWOO_PLUGIN_DIR . 'config/default-config.php';
        $saved    = get_option( 'octowoo_config', [] );

        if ( ! is_array( $saved ) ) {
            $saved = [];
        }

        return $this->deepMerge( $this->deepMerge( $defaults, $saved ), $overrides );
    }

    /**
     * Recursively merge two associative arrays (right wins).
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function deepMerge( array $base, array $override ): array {
        foreach ( $override as $key => $value ) {
            if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
                $base[ $key ] = $this->deepMerge( $base[ $key ], $value );
            } else {
                $base[ $key ] = $value;
            }
        }
        return $base;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function generateRunId(): string {
        return gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false );
    }

    private function isAborted(): bool {
        return self::checkAborted( $this->run_id );
    }

    /**
     * Static check used by BackgroundProcessor (no instance available there).
     */
    public static function checkAborted( string $run_id ): bool {
        return (bool) get_transient( 'octowoo_abort_' . $run_id );
    }

    private function buildReport(): array {
        $report = new MigrationReport(
            $this->run_id,
            $this->stats,
            $this->checkpoint->getAll(),
            (bool) ( $this->config['migration']['dry_run'] ?? false ),
            current_time( 'mysql' )
        );

        // Persist report to wp_options so admin UI can show it after page reload.
        $report->save();

        $this->logger->info( "Migration report:\n" . $report->toText() );

        return $report->toArray();
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getRunId(): string {
        return $this->run_id;
    }

    public function getConfig(): array {
        return $this->config;
    }
}
