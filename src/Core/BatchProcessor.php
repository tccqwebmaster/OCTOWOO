<?php
/**
 * Generic batch processor.
 *
 * Iterates over a data set in configurable chunks, invoking a callback
 * for each batch.  Tracks memory usage and optionally emits progress
 * information (used by the WP-CLI progress bar and admin AJAX polling).
 *
 * Usage:
 *   $processor = new BatchProcessor($logger, $config);
 *   $processor->run(
 *       totalCallback: fn() => $db->count('product'),
 *       batchCallback: fn(int $offset, int $limit) => $db->fetchBatch($sql, [], $limit, $offset),
 *       itemCallback:  fn(array $row) => $migrator->processOne($row),
 *       migrator:      'product',
 *       checkpoint:    $checkpointManager,
 *       lastId:        $checkpointManager->getLastId('product'),
 *       idField:       'product_id',
 *   );
 */

namespace OctoWoo\Core;

defined( 'ABSPATH' ) || exit;

class BatchProcessor {

    /** @var Logger */
    private Logger $logger;

    /** @var int Items per batch. */
    private int $batch_size;

    /** @var bool True = do not write anything to the DB. */
    private bool $dry_run;

    /**
     * @var int When > 0, each migrator stops after this many successfully-processed items.
     *          Used for demo / sanity-check runs before committing to a full migration.
     *          0 = unlimited (normal operation).
     */
    private int $demo_limit;

    /** @var callable|null Called after every batch with (processed, total). */
    private $progress_callback = null;

    /** @var bool When true, run() processes only one batch then stops. */
    private bool $chunk_mode = false;

    // ── Construction ─────────────────────────────────────────────────────────

    public function __construct( Logger $logger, array $config = [] ) {
        $this->logger     = $logger;
        $this->batch_size = (int) ( $config['batch_size'] ?? 20 );
        $this->dry_run    = (bool) ( $config['dry_run']   ?? false );
        $this->demo_limit = max( 0, (int) ( $config['demo_limit'] ?? 0 ) );
    }

    /** Enable single-batch (chunked) mode. Returns $this for chaining. */
    public function setChunkMode( bool $enabled ): self {
        $this->chunk_mode = $enabled;
        return $this;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Register a progress callback invoked after each completed batch.
     *
     * @param callable $cb  Signature: function(int $processed, int $total, string $migrator): void
     */
    public function onProgress( callable $cb ): void {
        $this->progress_callback = $cb;
    }

    /**
     * Run a full paginated migration pass.
     *
     * @param callable $total_callback  Returns the total number of items (int).
     * @param callable $batch_callback  Accepts (offset, limit) and returns array of rows.
     * @param callable $item_callback   Accepts one row array. Should return bool (true = success).
     * @param string   $migrator        Label used in logs and checkpoints.
     * @param CheckpointManager $checkpoint
     * @param int      $resume_after_id Items with id <= this value are skipped (resume support).
     * @param string   $id_field        Name of the primary key column in source rows.
     *
     * @return array{processed: int, skipped: int, failed: int}
     */
    public function run(
        callable           $total_callback,
        callable           $batch_callback,
        callable           $item_callback,
        string             $migrator,
        CheckpointManager  $checkpoint,
        int                $resume_after_id = 0,
        string             $id_field        = 'id'
    ): array {
        // Chunked mode: delegate to single-batch path.
        if ( $this->chunk_mode ) {
            return $this->runChunk( $total_callback, $batch_callback, $item_callback, $migrator, $checkpoint, $id_field );
        }

        $stats = [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];

        $total  = (int) $total_callback();
        $offset = 0;

        $checkpoint->init( $migrator, $total );
        $checkpoint->start( $migrator );

        // In demo mode cap the total so the UI shows the correct ceiling.
        if ( $this->demo_limit > 0 ) {
            $total = min( $total, $this->demo_limit );
        }

        $this->logger->info(
            "Starting batch processing for [{$migrator}]: total={$total}, batch_size={$this->batch_size}, dry_run=" . ( $this->dry_run ? 'yes' : 'no' ) . ( $this->demo_limit > 0 ? ", demo_limit={$this->demo_limit}" : '' ),
            [ 'resume_after_id' => $resume_after_id ]
        );

        while ( $offset < $total ) {
            // Fetch next batch.
            $rows = (array) $batch_callback( $offset, $this->batch_size );

            if ( empty( $rows ) ) {
                break; // No more data.
            }

            $last_id    = 0;
            $batch_done = 0;

            foreach ( $rows as $row ) {
                $item_id = isset( $row[ $id_field ] ) ? (int) $row[ $id_field ] : 0;

                // Resume: skip items already processed in a previous run.
                if ( $item_id > 0 && $item_id <= $resume_after_id ) {
                    $stats['skipped']++;
                    $last_id = max( $last_id, $item_id );
                    continue;
                }

                try {
                    if ( $this->dry_run ) {
                        // Dry-run: just log what would happen.
                        $this->logger->debug( "[DRY-RUN] Would process {$migrator} #{$item_id}" );
                        $stats['processed']++;
                    } else {
                        $success = (bool) $item_callback( $row );
                        if ( $success ) {
                            $stats['processed']++;
                        } else {
                            $stats['skipped']++;
                        }
                    }
                } catch ( \Throwable $e ) {
                    $stats['failed']++;
                    $this->logger->error(
                        "Failed to process {$migrator} #{$item_id}: " . $e->getMessage(),
                        [ 'oc_id' => $item_id, 'exception' => get_class( $e ) ]
                    );
                }

                $last_id = max( $last_id, $item_id );
                $batch_done++;
            }

            // Persist checkpoint progress.
            $checkpoint->update( $migrator, $last_id, $batch_done );

            $processed_total = $stats['processed'] + $stats['skipped'];

            // Fire progress hook for live UI updates.
            if ( $this->progress_callback ) {
                ( $this->progress_callback )( $processed_total, $total, $migrator );
            }

            $this->logger->debug(
                "Batch complete [{$migrator}]: processed_so_far={$processed_total}/{$total}, last_id={$last_id}"
            );

            // Free memory between batches.
            $this->maybeReleaseMemory();

            $offset += count( $rows );

            // Demo mode: stop once we have imported the requested number of items.
            if ( $this->demo_limit > 0 && $stats['processed'] >= $this->demo_limit ) {
                $this->logger->info( "Demo limit of {$this->demo_limit} reached for [{$migrator}] – stopping." );
                break;
            }

            // Allow WP heartbeat / other tasks to run between large batches.
            if ( function_exists( 'wp_ob_end_flush_all' ) ) {
                @ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }

        $checkpoint->complete( $migrator );

        $this->logger->success(
            "Finished [{$migrator}]: processed={$stats['processed']}, skipped={$stats['skipped']}, failed={$stats['failed']}"
        );

        return $stats;
    }

    // ── Memory management ─────────────────────────────────────────────────────

    /**
     * Clear WP object / term caches between batches to keep memory flat.
     */
    private function maybeReleaseMemory(): void {
        if ( function_exists( 'wp_cache_flush_runtime' ) ) {
            wp_cache_flush_runtime(); // WP 6.0+
        } elseif ( function_exists( 'wp_cache_flush' ) && ! wp_using_ext_object_cache() ) {
            // Only flush non-persistent cache to avoid evicting shared cache entries.
            wp_cache_flush();
        }

        // Clear WP's internal post cache arrays.
        if ( isset( $GLOBALS['wp_object_cache'] ) && is_object( $GLOBALS['wp_object_cache'] ) ) {
            $cache = $GLOBALS['wp_object_cache'];
            foreach ( [ 'posts', 'post_meta', 'terms', 'term_meta' ] as $group ) {
                if ( isset( $cache->cache[ $group ] ) ) {
                    $cache->cache[ $group ] = [];
                }
            }
        }
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function isDryRun(): bool {
        return $this->dry_run;
    }

    public function getBatchSize(): int {
        return $this->batch_size;
    }

    // ── Chunked (one-batch-per-request) mode ──────────────────────────────────

    /**
     * Process exactly ONE batch of the given migrator and return.
     *
     * Unlike run(), this method:
     *  – Uses the checkpoint's processed_count as the SQL OFFSET so each
     *    separate HTTP request picks up exactly where the last one left off.
     *  – Does NOT call checkpoint->complete().
     *  – Returns an extra 'is_done' flag so the caller knows whether to fire
     *    another chunk or move on to the next migrator.
     *
     * @param callable          $total_callback  Returns the total source item count.
     * @param callable          $batch_callback  Accepts (offset, limit) → array of rows.
     * @param callable          $item_callback   Accepts one row → bool.
     * @param string            $migrator        Migrator key.
     * @param CheckpointManager $checkpoint
     * @param string            $id_field        Primary-key column name.
     *
     * @return array{processed:int, skipped:int, failed:int, is_done:bool}
     */
    public function runChunk(
        callable           $total_callback,
        callable           $batch_callback,
        callable           $item_callback,
        string             $migrator,
        CheckpointManager  $checkpoint,
        string             $id_field = 'id'
    ): array {
        $stats = [ 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'is_done' => false ];

        $total  = (int) $total_callback();

        // Demo mode: cap the effective total so the chunk loop terminates early.
        if ( $this->demo_limit > 0 ) {
            $total = min( $total, $this->demo_limit );
        }

        $offset = $checkpoint->getProcessedCount( $migrator );

        // First chunk: initialise / reset checkpoint row.
        if ( $offset === 0 ) {
            $checkpoint->init( $migrator, $total );
            $checkpoint->start( $migrator );
            $this->logger->info(
                "Chunk-start [{$migrator}]: total={$total}, batch_size={$this->batch_size}" . ( $this->demo_limit > 0 ? ", demo_limit={$this->demo_limit}" : '' )
            );
        }

        if ( $offset >= $total ) {
            $checkpoint->complete( $migrator );
            $this->logger->success( "Chunk-complete [{$migrator}] (already at end)." );
            $stats['is_done'] = true;
            return $stats;
        }

        $rows = (array) $batch_callback( $offset, $this->batch_size );

        if ( empty( $rows ) ) {
            $checkpoint->complete( $migrator );
            $this->logger->success( "Chunk-complete [{$migrator}] (no more rows)." );
            $stats['is_done'] = true;
            return $stats;
        }

        $last_id    = 0;
        $batch_done = 0;

        foreach ( $rows as $row ) {
            $item_id = isset( $row[ $id_field ] ) ? (int) $row[ $id_field ] : 0;

            try {
                if ( $this->dry_run ) {
                    $this->logger->debug( "[DRY-RUN] Would process {$migrator} #{$item_id}" );
                    $stats['processed']++;
                } else {
                    $success = (bool) $item_callback( $row );
                    if ( $success ) {
                        $stats['processed']++;
                    } else {
                        $stats['skipped']++;
                    }
                }
            } catch ( \Throwable $e ) {
                $stats['failed']++;
                $this->logger->error(
                    "Failed to process {$migrator} #{$item_id}: " . $e->getMessage(),
                    [ 'oc_id' => $item_id, 'exception' => get_class( $e ) ]
                );
            }

            $last_id = max( $last_id, $item_id );
            $batch_done++;
        }

        $checkpoint->update( $migrator, $last_id, $batch_done );

        $new_offset = $offset + count( $rows );
        $this->logger->debug(
            "Chunk [{$migrator}]: offset={$offset}→{$new_offset}/{$total}, last_id={$last_id}"
        );

        $this->maybeReleaseMemory();

        if ( $new_offset >= $total ) {
            $checkpoint->complete( $migrator );
            $this->logger->success(
                "Chunk-complete [{$migrator}]: processed={$stats['processed']}, failed={$stats['failed']}"
            );
            $stats['is_done'] = true;
        }

        return $stats;
    }
}
