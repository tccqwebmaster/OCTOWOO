<?php
/**
 * Cron / dropshipping auto-import scheduler.
 *
 * Registers a WP-Cron event that periodically runs a delta migration to
 * pick up new products and orders added to OpenCart since the last run.
 *
 * Hooks registered in octowoo.php:
 *   add_action( 'octowoo_cron_event', [ CronManager::class, 'runCronMigration' ] );
 *
 * @package OctoWoo\Core
 */

namespace OctoWoo\Core;

defined( 'ABSPATH' ) || exit;

class CronManager {

    /** WP-Cron hook name. */
    const CRON_HOOK = 'octowoo_cron_event';

    /** Option that stores the timestamp of the last successful cron run. */
    const LAST_RUN_OPTION = 'octowoo_cron_last_run';

    // ──────────────────────────────────────────────────────────────────────
    // Registration / Scheduling
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Register the cron event and any custom schedules.
     * Call this at plugin activation and on every page load so the event
     * re-schedules itself if it was cleared.
     */
    public static function register(): void {
        $config = self::getConfig();

        // Register custom schedules (e.g. "every 30 minutes").
        add_filter( 'cron_schedules', [ static::class, 'addSchedules' ] );

        if ( empty( $config['enabled'] ) ) {
            // Ensure event is not scheduled if disabled.
            self::unschedule();
            return;
        }

        $interval = $config['interval'] ?? 'daily';
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), $interval, self::CRON_HOOK );
        }

        // Wire up the callback.
        add_action( self::CRON_HOOK, [ static::class, 'runCronMigration' ] );
    }

    /**
     * Add custom WP-Cron schedule intervals.
     *
     * @param  array $schedules  Existing schedules.
     * @return array
     */
    public static function addSchedules( array $schedules ): array {
        if ( ! isset( $schedules['every30min'] ) ) {
            $schedules['every30min'] = [
                'interval' => 30 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 30 Minutes', 'octowoo' ),
            ];
        }
        if ( ! isset( $schedules['every6hours'] ) ) {
            $schedules['every6hours'] = [
                'interval' => 6 * HOUR_IN_SECONDS,
                'display'  => __( 'Every 6 Hours', 'octowoo' ),
            ];
        }
        return $schedules;
    }

    /**
     * Remove the scheduled cron event (used on deactivation or when disabled).
     */
    public static function unschedule(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Execution
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Called by WP-Cron. Runs a delta migration using on_duplicate=update
     * so only new/changed data from OpenCart is imported.
     */
    public static function runCronMigration(): void {
        $config  = self::getConfig();
        $started = time();
        $last    = (int) get_option( self::LAST_RUN_OPTION, 0 );

        // Overrides: only run the migrators listed in cron config,
        // and force update-on-duplicate so new items are imported.
        $migrator_names = array_filter(
            array_map( 'trim', explode( ',', $config['migrators'] ?? 'products,images,orders' ) )
        );

        $overrides = [
            'migration' => [
                'dry_run'      => false,
                'on_duplicate' => 'update',
            ],
        ];

        // Disable all migrators, then re-enable requested ones.
        $all_keys = [
            'run_categories', 'run_products', 'run_images', 'run_customers',
            'run_orders', 'run_coupons', 'run_seo', 'run_information',
            'run_tags', 'run_filters', 'run_downloads', 'run_manufacturers', 'run_reviews',
        ];
        foreach ( $all_keys as $k ) {
            $overrides['migration'][ $k ] = false;
        }
        foreach ( $migrator_names as $name ) {
            $overrides['migration'][ 'run_' . strtolower( $name ) ] = true;
        }

        // Pass a delta-since timestamp via the overrides so migrators can
        // filter their SQL to rows newer than $last.
        if ( $last > 0 ) {
            $overrides['cron']['delta_since'] = date( 'Y-m-d H:i:s', $last ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        }

        try {
            $manager = new MigrationManager( $overrides );
            $report  = $manager->run( false );

            update_option( self::LAST_RUN_OPTION, $started );

            $processed = array_sum( array_column( $report['results'] ?? [], 'processed' ) );
            $errors    = $report['error_count'] ?? 0;

            // Store last result summary for display in admin UI.
            update_option( 'octowoo_cron_last_result', [
                'run_id'    => $report['run_id'],
                'processed' => $processed,
                'errors'    => $errors,
                'at'        => current_time( 'mysql' ),
            ] );

        } catch ( \Throwable $e ) {
            // Log but don't crash WP-Cron.
            error_log( '[OctoWoo Cron] Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Status & Admin Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Return human-readable info about the cron schedule & last run.
     *
     * @return array{enabled: bool, interval: string, next: string, last_run: string, last_result: array}
     */
    public static function getStatus(): array {
        $config    = self::getConfig();
        $next      = wp_next_scheduled( self::CRON_HOOK );
        $last_run  = get_option( self::LAST_RUN_OPTION, 0 );
        $result    = get_option( 'octowoo_cron_last_result', [] );

        return [
            'enabled'     => ! empty( $config['enabled'] ),
            'interval'    => $config['interval'] ?? 'daily',
            'migrators'   => $config['migrators'] ?? '',
            'next'        => $next ? date( 'Y-m-d H:i:s', $next ) : 'not scheduled', // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            'last_run'    => $last_run ? date( 'Y-m-d H:i:s', $last_run ) : 'never', // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            'last_result' => $result,
        ];
    }

    /**
     * Manually trigger the cron job now (useful for admin "Run Now" button).
     */
    public static function runNow(): void {
        do_action( self::CRON_HOOK );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Config helper
    // ──────────────────────────────────────────────────────────────────────

    private static function getConfig(): array {
        $saved = get_option( 'octowoo_config', [] );
        return $saved['cron'] ?? [];
    }
}
