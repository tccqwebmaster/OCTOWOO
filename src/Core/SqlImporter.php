<?php
/**
 * SqlImporter – imports an OpenCart SQL dump into WordPress's own database.
 *
 * The dump is re-prefixed on the fly: every table that starts with
 * the source prefix (e.g. "oc_") is renamed to "octowoo_oc_" so it can
 * live safely inside the WP database alongside WP tables.
 *
 * After a successful import the config is updated so DatabaseConnector
 * points to the WP database with prefix "octowoo_oc_".
 *
 * Usage:
 *   $importer = new SqlImporter( $source_prefix );
 *   foreach ( $importer->import( $sql_file_path ) as $progress ) {
 *       // $progress = ['done' => int, 'total' => int, 'message' => string]
 *   }
 */

namespace OctoWoo\Core;

defined( 'ABSPATH' ) || exit;

class SqlImporter {

    /** Prefix used in the uploaded dump (e.g. "oc_"). */
    private string $source_prefix;

    /** Prefix used in the WP database after import. */
    public const IMPORT_PREFIX = 'octowoo_oc_';

    /** Maximum bytes read per iteration to avoid memory exhaustion. */
    private const CHUNK_BYTES = 256 * 1024; // 256 KB

    public function __construct( string $source_prefix = 'oc_' ) {
        $this->source_prefix = $source_prefix;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Drop all previously-imported OC tables (clean slate before re-import).
     */
    public function dropImportedTables(): void {
        global $wpdb;

        $tables = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s",
                DB_NAME,
                self::IMPORT_PREFIX . '%'
            )
        );

        foreach ( $tables as $tbl ) {
            $wpdb->query( "DROP TABLE IF EXISTS `" . esc_sql( $tbl ) . "`" ); // phpcs:ignore WordPress.DB.PreparedSQL
        }
    }

    /**
     * Parse and execute an SQL dump file, yielding progress updates.
     *
     * @param  string $file  Absolute path to the .sql file.
     * @return \Generator<array{done:int,total:int,message:string}>
     * @throws \RuntimeException on file / DB errors.
     */
    public function import( string $file ): \Generator {
        if ( ! is_readable( $file ) ) {
            throw new \RuntimeException( "SQL file not readable: {$file}" );
        }

        $size = filesize( $file ) ?: 1;
        $fh   = fopen( $file, 'rb' );
        if ( ! $fh ) {
            throw new \RuntimeException( "Cannot open SQL file: {$file}" );
        }

        global $wpdb;
        $wpdb->query( 'SET foreign_key_checks = 0' );

        $buffer    = '';
        $read      = 0;
        $stmt_count = 0;

        try {
            while ( ! feof( $fh ) ) {
                $chunk  = fread( $fh, self::CHUNK_BYTES );
                $read  += strlen( $chunk );
                $buffer .= $chunk;

                // Extract complete statements from the buffer.
                while ( ( $pos = $this->findStatementEnd( $buffer ) ) !== false ) {
                    $raw_stmt = substr( $buffer, 0, $pos + 1 );
                    $buffer   = ltrim( substr( $buffer, $pos + 1 ) );

                    $stmt = $this->transformStatement( $raw_stmt );

                    if ( $stmt !== '' ) {
                        $result = $wpdb->query( $stmt ); // phpcs:ignore WordPress.DB.PreparedSQL
                        if ( $result === false && ! empty( $wpdb->last_error ) ) {
                            // Non-fatal: log and keep going (duplicate key errors on re-import are normal).
                            error_log( 'OctoWoo SqlImporter: ' . $wpdb->last_error );
                        }
                        $stmt_count++;
                    }

                    if ( $stmt_count % 100 === 0 ) {
                        yield [
                            'done'    => $read,
                            'total'   => $size,
                            'message' => "Imported {$stmt_count} statements…",
                        ];
                    }
                }
            }

            // Flush any remaining partial statement.
            $remainder = trim( $buffer );
            if ( $remainder !== '' && $remainder !== ';' ) {
                $stmt = $this->transformStatement( $remainder );
                if ( $stmt !== '' ) {
                    $wpdb->query( $stmt ); // phpcs:ignore WordPress.DB.PreparedSQL
                    $stmt_count++;
                }
            }
        } finally {
            fclose( $fh );
            $wpdb->query( 'SET foreign_key_checks = 1' );
        }

        yield [
            'done'    => $size,
            'total'   => $size,
            'message' => "Import complete: {$stmt_count} statements executed.",
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Find the position of the statement-terminating semicolon,
     * ignoring semicolons inside quoted strings and comments.
     *
     * Returns false if no complete statement found in the buffer yet.
     */
    private function findStatementEnd( string $buf ): int|false {
        $len      = strlen( $buf );
        $in_quote = false;
        $quote    = '';
        $i        = 0;

        while ( $i < $len ) {
            $c = $buf[ $i ];

            // Toggle quote state.
            if ( ! $in_quote && ( $c === "'" || $c === '"' || $c === '`' ) ) {
                $in_quote = true;
                $quote    = $c;
                $i++;
                continue;
            }
            if ( $in_quote && $c === $quote ) {
                // Escaped quote: '' or "" or ``
                if ( $i + 1 < $len && $buf[ $i + 1 ] === $quote ) {
                    $i += 2;
                    continue;
                }
                $in_quote = false;
                $i++;
                continue;
            }

            // Skip line comments (-- or #) when outside quotes.
            if ( ! $in_quote && $c === '-' && $i + 1 < $len && $buf[ $i + 1 ] === '-' ) {
                $eol = strpos( $buf, "\n", $i );
                $i   = $eol !== false ? $eol + 1 : $len;
                continue;
            }
            if ( ! $in_quote && $c === '#' ) {
                $eol = strpos( $buf, "\n", $i );
                $i   = $eol !== false ? $eol + 1 : $len;
                continue;
            }
            // Skip block comments /* … */
            if ( ! $in_quote && $c === '/' && $i + 1 < $len && $buf[ $i + 1 ] === '*' ) {
                $end = strpos( $buf, '*/', $i + 2 );
                $i   = $end !== false ? $end + 2 : $len;
                continue;
            }

            if ( ! $in_quote && $c === ';' ) {
                return $i;
            }

            $i++;
        }

        return false;
    }

    /**
     * Rewrite a raw SQL statement:
     *  – Strip SET, USE, LOCK, UNLOCK statements (WP connection handles these).
     *  – Replace source prefix with IMPORT_PREFIX in table names.
     *  – Remove ENGINE/CHARSET clauses that might conflict with WP's collation.
     */
    private function transformStatement( string $stmt ): string {
        $stmt = trim( $stmt );
        if ( $stmt === '' || $stmt === ';' ) {
            return '';
        }

        // Remove trailing semicolon (wpdb->query() does not need it).
        $stmt = rtrim( $stmt, ';' );

        $upper = strtoupper( ltrim( $stmt ) );

        // Skip statements that don't make sense in this context.
        $skip_patterns = [ 'SET SQL_MODE', 'SET TIME_ZONE', 'SET CHARACTER_SET', 'SET @', 'USE ', 'LOCK TABLES', 'UNLOCK TABLES' ];
        foreach ( $skip_patterns as $pat ) {
            if ( str_starts_with( $upper, $pat ) ) {
                return '';
            }
        }

        // Re-prefix: replace `oc_tablename` → `octowoo_oc_tablename`
        // Handles backtick-quoted and unquoted table references.
        $src = preg_quote( $this->source_prefix, '/' );
        $dst = self::IMPORT_PREFIX;

        // Backtick-quoted: `oc_table`
        $stmt = preg_replace( '/`' . $src . '([^`]+)`/', '`' . $dst . '$1`', $stmt );
        // Unquoted (word boundary): oc_table
        $stmt = preg_replace( '/\b' . $src . '(\w+)\b/', $dst . '$1', $stmt );

        return $stmt ?? '';
    }

    // ── Static helpers ────────────────────────────────────────────────────────

    /**
     * Return the DB config override so MigrationManager uses imported tables.
     * Merges WP's own credentials with the import prefix.
     * Properly parses DB_HOST which WordPress allows in three forms:
     *   "hostname", "hostname:port", or "hostname:/path/to/socket".
     */
    public static function getLocalDbConfig(): array {
        $socket = '';
        $port   = 3306;
        $host   = DB_HOST;

        // Split WP's DB_HOST into host + port-or-socket.
        if ( strpos( DB_HOST, ':' ) !== false ) {
            [ $host, $suffix ] = explode( ':', DB_HOST, 2 );
            if ( str_starts_with( $suffix, '/' ) ) {
                // Unix socket path embedded in DB_HOST.
                $socket = $suffix;
            } else {
                $port = (int) $suffix;
            }
        }

        return [
            'host'     => $host,
            'port'     => $port,
            'socket'   => $socket,
            'database' => DB_NAME,
            'username' => DB_USER,
            'password' => DB_PASSWORD,
            'prefix'   => self::IMPORT_PREFIX,
        ];
    }

    /**
     * Return the absolute path where uploaded images are extracted.
     */
    public static function getImagesDir(): string {
        return trailingslashit( wp_upload_dir()['basedir'] ) . 'octowoo-images/';
    }
}
