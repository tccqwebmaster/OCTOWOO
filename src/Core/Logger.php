<?php
/**
 * Migration logger.
 *
 * Writes structured log entries to:
 *   (a) A rotating log file in /logs/ (one file per calendar day).
 *   (b) The wp_{prefix}octowoo_logs database table.
 *
 * Supports levels: DEBUG < INFO < WARNING < ERROR < SUCCESS.
 * Both destinations can be toggled independently via the plugin config.
 */

namespace OctoWoo\Core;

defined( 'ABSPATH' ) || exit;

class Logger {

    // ── Log level constants ───────────────────────────────────────────────────
    const DEBUG   = 'DEBUG';
    const INFO    = 'INFO';
    const WARNING = 'WARNING';
    const ERROR   = 'ERROR';
    const SUCCESS = 'SUCCESS';

    /** Priority order for min-level filtering. */
    private const LEVEL_PRIORITY = [
        self::DEBUG   => 0,
        self::INFO    => 1,
        self::WARNING => 2,
        self::ERROR   => 3,
        self::SUCCESS => 4,
    ];

    /** @var string Unique ID for this migration run. */
    private string $run_id;

    /** @var string|null Current migrator context label. */
    private string $current_migrator = '';

    /** @var bool Write to file? */
    private bool $file_enabled;

    /** @var bool Write to database? */
    private bool $db_enabled;

    /** @var string Minimum level to record (DEBUG|INFO|WARNING|ERROR). */
    private string $min_level;

    /** @var int Maximum file size before rotation (bytes). */
    private int $max_file_size;

    /** @var string|null Path to the currently open log file. */
    private ?string $log_file = null;

    /** @var array<int, array<string, mixed>> In-memory buffer for bulk DB inserts. */
    private array $buffer = [];

    /** @var int How many buffer entries to accumulate before flushing to DB. */
    private const BUFFER_FLUSH = 5;

    // ── Construction ─────────────────────────────────────────────────────────

    public function __construct( string $run_id, array $config = [] ) {
        $this->run_id        = $run_id;
        $this->file_enabled  = (bool) ( $config['file_enabled']  ?? true );
        $this->db_enabled    = (bool) ( $config['db_enabled']    ?? true );
        $this->min_level     = $config['min_level']     ?? self::INFO;
        $this->max_file_size = (int)  ( $config['max_file_size'] ?? 10 * 1024 * 1024 );

        // Ensure buffered log entries are written on shutdown (covers fatal
        // errors, early exit, or callers that forget to call flush()).
        register_shutdown_function( [ $this, 'flush' ] );
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /** Set the current migrator label included in every subsequent log entry. */
    public function setMigrator( string $migrator ): void {
        $this->current_migrator = $migrator;
    }

    public function debug( string $message, array $context = [] ): void {
        $this->log( self::DEBUG, $message, $context );
    }

    public function info( string $message, array $context = [] ): void {
        $this->log( self::INFO, $message, $context );
    }

    public function warning( string $message, array $context = [] ): void {
        $this->log( self::WARNING, $message, $context );
    }

    public function error( string $message, array $context = [] ): void {
        $this->log( self::ERROR, $message, $context );
    }

    public function success( string $message, array $context = [] ): void {
        $this->log( self::SUCCESS, $message, $context );
    }

    /**
     * Core log method.
     *
     * @param string               $level   One of the level constants.
     * @param string               $message Human-readable log message.
     * @param array<string, mixed> $context Optional structured data (will be JSON-encoded).
     */
    public function log( string $level, string $message, array $context = [] ): void {
        if ( ! $this->shouldRecord( $level ) ) {
            return;
        }

        $entry = [
            'run_id'    => $this->run_id,
            'level'     => $level,
            'migrator'  => $this->current_migrator,
            'message'   => $message,
            'context'   => $context,
            'timestamp' => current_time( 'mysql' ),
        ];

        if ( $this->file_enabled ) {
            $this->writeToFile( $entry );
        }

        if ( $this->db_enabled ) {
            $this->bufferToDB( $entry );
        }
    }

    /**
     * Force-flush any buffered DB entries.
     * Call this at the end of each migrator run and on shutdown.
     */
    public function flush(): void {
        if ( ! empty( $this->buffer ) ) {
            $this->flushBuffer();
        }
    }

    // ── File writer ───────────────────────────────────────────────────────────

    private function writeToFile( array $entry ): void {
        $file = $this->getLogFilePath();

        $context_str = ! empty( $entry['context'] )
            ? ' | ' . wp_json_encode( $entry['context'], JSON_UNESCAPED_UNICODE )
            : '';

        $line = sprintf(
            "[%s] [%s] [%s] %s%s\n",
            $entry['timestamp'],
            str_pad( $entry['level'], 7 ),
            $entry['migrator'] ?: 'general',
            $entry['message'],
            $context_str
        );

        file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
    }

    /**
     * Return the current (date-based) log file path, rotating if over-size.
     */
    private function getLogFilePath(): string {
        $date    = gmdate( 'Y-m-d' );
        $base    = OCTOWOO_LOG_DIR . "migration-{$this->run_id}-{$date}.log";

        // Rotate if too large.
        if (
            $this->log_file !== null
            && $this->log_file !== $base
        ) {
            // Date rolled over to a new day.
            $this->log_file = $base;
        }

        if (
            $this->log_file === null
            || ( file_exists( $base ) && filesize( $base ) > $this->max_file_size )
        ) {
            // Start a new rotated file using a counter suffix.
            $i    = 1;
            $file = $base;
            while ( file_exists( $file ) && filesize( $file ) > $this->max_file_size ) {
                $file = OCTOWOO_LOG_DIR . "migration-{$this->run_id}-{$date}-{$i}.log";
                $i++;
            }
            $this->log_file = $file;
        }

        return $this->log_file;
    }

    // ── DB writer ─────────────────────────────────────────────────────────────

    private function bufferToDB( array $entry ): void {
        $this->buffer[] = $entry;

        if ( count( $this->buffer ) >= self::BUFFER_FLUSH ) {
            $this->flushBuffer();
        }
    }

    private function flushBuffer(): void {
        if ( empty( $this->buffer ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'octowoo_logs';

        foreach ( $this->buffer as $entry ) {
            $wpdb->insert(
                $table,
                [
                    'run_id'     => $entry['run_id'],
                    'level'      => $entry['level'],
                    'migrator'   => $entry['migrator'],
                    'message'    => $entry['message'],
                    'context'    => ! empty( $entry['context'] )
                        ? wp_json_encode( $entry['context'], JSON_UNESCAPED_UNICODE )
                        : null,
                    'created_at' => $entry['timestamp'],
                ],
                [ '%s', '%s', '%s', '%s', '%s', '%s' ]
            );
        }

        $this->buffer = [];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function shouldRecord( string $level ): bool {
        $min  = self::LEVEL_PRIORITY[ $this->min_level ] ?? 0;
        $curr = self::LEVEL_PRIORITY[ $level ]           ?? 0;
        return $curr >= $min;
    }

    /**
     * Retrieve recent log entries for the current run from the DB.
     *
     * @param int    $limit   Max rows to return.
     * @param string $level   Filter by level (empty = all).
     * @return array<int, array<string, mixed>>
     */
    public function getRecentEntries( int $limit = 100, string $level = '', string $migrator = '' ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'octowoo_logs';

        if ( $migrator && $level ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE run_id = %s AND level = %s AND migrator = %s ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
                    $this->run_id, $level, $migrator, $limit
                ),
                ARRAY_A
            );
        } elseif ( $migrator ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE run_id = %s AND migrator = %s ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
                    $this->run_id, $migrator, $limit
                ),
                ARRAY_A
            );
        } elseif ( $level ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE run_id = %s AND level = %s ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
                    $this->run_id,
                    $level,
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE run_id = %s ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
                    $this->run_id,
                    $limit
                ),
                ARRAY_A
            );
        }

        return $rows ?: [];
    }

    public function getRunId(): string {
        return $this->run_id;
    }
}
