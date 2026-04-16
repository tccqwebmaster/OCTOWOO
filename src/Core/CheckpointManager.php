<?php
/**
 * Checkpoint / resume manager.
 *
 * Persists migration progress into the wp_{prefix}octowoo_checkpoints table
 * so that an interrupted run can be resumed from the last successfully
 * processed item rather than restarting from scratch.
 *
 * Each checkpoint is keyed by (run_id, migrator).
 * A separate "active run" option stores the current run_id so the UI
 * can distinguish a fresh run from a resume.
 */

namespace OctoWoo\Core;

defined( 'ABSPATH' ) || exit;

class CheckpointManager {

    // Checkpoint status values.
    const STATUS_PENDING    = 'pending';
    const STATUS_RUNNING    = 'running';
    const STATUS_COMPLETED  = 'completed';
    const STATUS_FAILED     = 'failed';

    /** @var string Current migration run ID. */
    private string $run_id;

    /** @var string Database table name (fully qualified). */
    private string $table;

    // ── Construction ─────────────────────────────────────────────────────────

    public function __construct( string $run_id ) {
        global $wpdb;
        $this->run_id = $run_id;
        $this->table  = $wpdb->prefix . 'octowoo_checkpoints';
    }

    // ── Run lifecycle ─────────────────────────────────────────────────────────

    /**
     * Persist the current run ID as the "active" run so the admin UI knows
     * a migration is in progress.
     */
    public function markRunActive(): void {
        global $wpdb;

        // Try to acquire a DB-level lock to prevent concurrent runners.
        // Wait up to 10 seconds for the lock; if unavailable we still mark the run
        // active but the existence of this lock helps prevent races between cron/ajax.
        $got = $wpdb->get_var( "SELECT GET_LOCK('octowoo_migration', 10)" ); // phpcs:ignore WordPress.DB.PreparedSQL

        if ( $got ) {
            update_option( 'octowoo_db_lock', '1', false );
        }

        // Short-lived transient visible to JS/UI to detect an active run quickly.
        set_transient( 'octowoo_lock_' . $this->run_id, '1', HOUR_IN_SECONDS );

        update_option( 'octowoo_active_run_id', $this->run_id, false );
        update_option( 'octowoo_run_started_at', current_time( 'mysql' ), false );
    }

    /**
     * Clear the active-run flag when migration completes or is aborted.
     */
    public function markRunFinished(): void {
        global $wpdb;

        // Release DB lock if held.
        $wpdb->get_var( "SELECT RELEASE_LOCK('octowoo_migration')" ); // phpcs:ignore WordPress.DB.PreparedSQL
        delete_option( 'octowoo_db_lock' );

        // Clear transient + active-run flag.
        delete_transient( 'octowoo_lock_' . $this->run_id );
        delete_option( 'octowoo_active_run_id' );
        update_option( 'octowoo_last_run_id', $this->run_id, false );
        update_option( 'octowoo_last_run_at', current_time( 'mysql' ), false );
    }

    /**
     * Return the run ID that is currently in progress, or null.
     */
    public static function getActiveRunId(): ?string {
        $v = get_option( 'octowoo_active_run_id', '' );
        return $v ?: null;
    }

    // ── Checkpoint CRUD ───────────────────────────────────────────────────────

    /**
     * Initialise a checkpoint row for a migrator (idempotent).
     *
     * @param string $migrator     Migrator key e.g. "category", "product".
     * @param int    $total_count  How many OC records will be processed.
     */
    public function init( string $migrator, int $total_count = 0 ): void {
        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$this->table}` WHERE run_id = %s AND migrator = %s", // phpcs:ignore WordPress.DB.PreparedSQL
                $this->run_id,
                $migrator
            )
        );

        if ( ! $exists ) {
            $wpdb->insert(
                $this->table,
                [
                    'run_id'          => $this->run_id,
                    'migrator'        => $migrator,
                    'last_oc_id'      => 0,
                    'processed_count' => 0,
                    'total_count'     => $total_count,
                    'status'          => self::STATUS_PENDING,
                    'started_at'      => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%d', '%d', '%d', '%s', '%s' ]
            );
        } else {
            // Re-initialising an existing checkpoint (e.g. restart): reset counters.
            $wpdb->update(
                $this->table,
                [ 'total_count' => $total_count, 'status' => self::STATUS_PENDING ],
                [ 'run_id' => $this->run_id, 'migrator' => $migrator ],
                [ '%d', '%s' ],
                [ '%s', '%s' ]
            );
        }
    }

    /**
     * Mark a migrator as running.
     */
    public function start( string $migrator ): void {
        $this->updateStatus( $migrator, self::STATUS_RUNNING );
    }

    /**
     * Update progress after each batch.
     *
     * @param string $migrator      Migrator key.
     * @param int    $last_oc_id    The highest OC ID processed in the last batch.
     * @param int    $batch_count   Number of items processed in this batch.
     */
    public function update( string $migrator, int $last_oc_id, int $batch_count ): void {
        global $wpdb;

        $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->prepare(
                "UPDATE `{$this->table}`
                    SET last_oc_id      = %d,
                        processed_count = processed_count + %d,
                        updated_at      = %s
                 WHERE run_id = %s AND migrator = %s",
                $last_oc_id,
                $batch_count,
                current_time( 'mysql' ),
                $this->run_id,
                $migrator
            )
        );
    }

    /**
     * Mark a migrator as completed or failed.
     */
    public function complete( string $migrator ): void {
        $this->updateStatus( $migrator, self::STATUS_COMPLETED );
    }

    public function fail( string $migrator ): void {
        $this->updateStatus( $migrator, self::STATUS_FAILED );
    }

    /**
     * Return the last-processed OC ID for a migrator (used to resume).
     */
    public function getLastId( string $migrator ): int {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT last_oc_id, status FROM `{$this->table}` WHERE run_id = %s AND migrator = %s", // phpcs:ignore WordPress.DB.PreparedSQL
                $this->run_id,
                $migrator
            ),
            ARRAY_A
        );

        // If already completed in a prior run, return 0 to skip.
        if ( $row && $row['status'] === self::STATUS_COMPLETED ) {
            return PHP_INT_MAX; // Signals: already done.
        }

        return (int) ( $row['last_oc_id'] ?? 0 );
    }

    /**
     * Return all checkpoint rows for the current run.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$this->table}` WHERE run_id = %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
                $this->run_id
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Return the number of items already processed for a migrator.
     * Used to compute the correct SQL OFFSET when resuming chunked runs.
     */
    public function getProcessedCount( string $migrator ): int {
        global $wpdb;

        $val = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT processed_count FROM `{$this->table}` WHERE run_id = %s AND migrator = %s", // phpcs:ignore WordPress.DB.PreparedSQL
                $this->run_id,
                $migrator
            )
        );

        return (int) ( $val ?? 0 );
    }

    /**
     * Check if a specific migrator has already completed successfully.
     */
    public function isCompleted( string $migrator ): bool {
        global $wpdb;

        $status = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM `{$this->table}` WHERE run_id = %s AND migrator = %s", // phpcs:ignore WordPress.DB.PreparedSQL
                $this->run_id,
                $migrator
            )
        );

        return $status === self::STATUS_COMPLETED;
    }

    // ── ID map helpers ────────────────────────────────────────────────────────

    /**
     * Persist an OpenCart → WooCommerce ID mapping.
     *
     * @param string $entity One of: category, product, customer, order, coupon, image.
     * @param int    $oc_id  OpenCart source ID.
     * @param int    $wc_id  WordPress/WooCommerce destination ID.
     */
    public function saveIdMap( string $entity, int $oc_id, int $wc_id ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'octowoo_id_map';

        // Use INSERT … ON DUPLICATE KEY UPDATE to handle re-runs gracefully.
        $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->prepare(
                "INSERT INTO `{$table}` (entity_type, oc_id, wc_id, run_id)
                 VALUES (%s, %d, %d, %s)
                 ON DUPLICATE KEY UPDATE wc_id = VALUES(wc_id), run_id = VALUES(run_id)",
                $entity,
                $oc_id,
                $wc_id,
                $this->run_id
            )
        );
    }

    /**
     * Look up the WC ID for a previously migrated OC entity.
     *
     * Two-pass lookup:
     *  1. Fast path  – octowoo_id_map table (populated during migration).
     *  2. Fallback   – scan WordPress post/term meta directly.
     *                  Covers cases where id_map was reset but content still
     *                  exists (e.g. Reset Progress was clicked, or plugin was
     *                  reinstalled). When found via fallback the row is
     *                  backfilled into id_map for instant future lookups.
     *
     * @return int|null WC ID, or null if not yet migrated.
     */
    public function getWcId( string $entity, int $oc_id ): ?int {
        global $wpdb;

        $table = $wpdb->prefix . 'octowoo_id_map';

        // ── Pass 1: ID map ────────────────────────────────────────────────────
        $wc_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT wc_id FROM `{$table}` WHERE entity_type = %s AND oc_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
                $entity,
                $oc_id
            )
        );

        if ( $wc_id !== null ) {
            return (int) $wc_id;
        }

        // ── Pass 2: meta table fallback ───────────────────────────────────────
        $found = null;

        switch ( $entity ) {
            case 'product':
            case 'variation':
                $found = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT pm.post_id
                         FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = '_octowoo_oc_id'
                           AND pm.meta_value = %s
                           AND p.post_type IN ('product','product_variation')
                         LIMIT 1",
                        (string) $oc_id
                    )
                );
                break;

            case 'category':
                $found = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT tm.term_id
                         FROM {$wpdb->termmeta} tm
                         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                         WHERE tm.meta_key = '_octowoo_oc_id'
                           AND tm.meta_value = %s
                           AND tt.taxonomy = 'product_cat'
                         LIMIT 1",
                        (string) $oc_id
                    )
                );
                break;

            case 'manufacturer':
                $found = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT term_id
                         FROM {$wpdb->termmeta}
                         WHERE meta_key = '_octowoo_oc_manufacturer_id'
                           AND meta_value = %s
                         LIMIT 1",
                        (string) $oc_id
                    )
                );
                break;

            case 'customer':
                $found = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT user_id
                         FROM {$wpdb->usermeta}
                         WHERE meta_key = '_octowoo_oc_id'
                           AND meta_value = %s
                         LIMIT 1",
                        (string) $oc_id
                    )
                );
                break;
            case 'order':
                $found = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT pm.post_id
                         FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = '_octowoo_oc_order_id'
                           AND pm.meta_value = %s
                           AND p.post_type = 'shop_order'
                         LIMIT 1",
                        (string) $oc_id
                    )
                );
                break;

            case 'coupon':
                $found = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT pm.post_id
                         FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = '_octowoo_oc_id'
                           AND pm.meta_value = %s
                           AND p.post_type = 'shop_coupon'
                         LIMIT 1",
                        (string) $oc_id
                    )
                );
                break;

            case 'order':
                // Legacy post-table orders.
                $found = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT pm.post_id
                         FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = '_octowoo_oc_order_id'
                           AND pm.meta_value = %s
                           AND p.post_type = 'shop_order'
                         LIMIT 1",
                        (string) $oc_id
                    )
                );
                // HPOS table fallback (WooCommerce 7.1+).
                if ( ! $found ) {
                    $ot = $wpdb->prefix . 'wc_orders_meta';
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $found = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT order_id FROM `{$ot}`
                             WHERE meta_key = '_octowoo_oc_order_id'
                               AND meta_value = %s
                             LIMIT 1",
                            (string) $oc_id
                        )
                    );
                }
                break;
        }

        if ( ! $found ) {
            return null;
        }

        $found = (int) $found;

        // Backfill id_map so subsequent lookups skip this fallback.
        $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->prepare(
                "INSERT INTO `{$table}` (entity_type, oc_id, wc_id, run_id)
                 VALUES (%s, %d, %d, %s)
                 ON DUPLICATE KEY UPDATE wc_id = VALUES(wc_id), run_id = VALUES(run_id)",
                $entity,
                $oc_id,
                $found,
                $this->run_id
            )
        );

        return $found;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function updateStatus( string $migrator, string $status ): void {
        global $wpdb;

        $wpdb->update(
            $this->table,
            [ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ],
            [ 'run_id' => $this->run_id, 'migrator' => $migrator ],
            [ '%s', '%s' ],
            [ '%s', '%s' ]
        );
    }

    public function getRunId(): string {
        return $this->run_id;
    }
}
