<?php
/**
 * OpenCart database connector.
 *
 * Opens a dedicated PDO connection to the OpenCart MySQL database,
 * completely separate from the WordPress $wpdb connection.
 *
 * All queries use prepared statements and UTF-8mb4 charset to ensure
 * Arabic and other multi-byte content is transported safely.
 */

namespace OctoWoo\Core;

defined( 'ABSPATH' ) || exit;

class DatabaseConnector {

    /** @var \PDO|null Active PDO connection instance. */
    private ?\PDO $pdo = null;

    /** @var array<string, mixed> Resolved configuration. */
    private array $config;

    /** @var string OpenCart table prefix (default "oc_"). */
    private string $prefix;

    // ── Construction ─────────────────────────────────────────────────────────

    public function __construct( array $config ) {
        // If offline/local mode is active, override DB credentials with WP's own
        // database (which holds the imported OC tables under octowoo_oc_ prefix).
        if ( ( $config['source'] ?? 'remote' ) === 'local' ) {
            $config = array_merge( $config, SqlImporter::getLocalDbConfig() );
        }

        $this->config = $config;
        $this->prefix = $config['prefix'] ?? 'oc_';
    }

    // ── Connection management ─────────────────────────────────────────────────

    /**
     * Open the PDO connection (lazy, called only when first query runs).
     *
     * @throws \RuntimeException on connection failure.
     */
    public function connect(): void {
        if ( $this->pdo !== null ) {
            return;
        }

        // Support Unix socket connections (needed when MySQL root uses auth_socket).
        $socket = trim( $this->config['socket'] ?? '' );

        if ( $socket !== '' ) {
            $dsn = sprintf(
                'mysql:unix_socket=%s;dbname=%s;charset=utf8mb4',
                $socket,
                $this->config['database'] ?? 'opencart'
            );
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->config['host']     ?? '127.0.0.1',
                $this->config['port']     ?? 3306,
                $this->config['database'] ?? 'opencart'
            );
        }

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            // Force UTF-8mb4 so Arabic content is preserved.
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            $this->pdo = new \PDO(
                $dsn,
                $this->config['username'] ?? 'root',
                $this->config['password'] ?? '',
                $options
            );
        } catch ( \PDOException $e ) {
            $msg = $e->getMessage();
            // Error 1698: root user is configured with auth_socket / unix_socket plugin.
            // Password-based login is rejected; a Unix socket path or a dedicated DB user is required.
            if ( (int) $e->getCode() === 1698 ) {
                $msg .= ' — MySQL error 1698: this user is configured for UNIX socket (auth_socket) '
                      . 'authentication only. Solutions: (a) enter the Unix Socket Path in Settings '
                      . '(e.g. /var/run/mysqld/mysqld.sock), (b) create a dedicated DB user with a '
                      . 'password, or (c) run: ALTER USER \'root\'@\'localhost\' IDENTIFIED WITH '
                      . 'mysql_native_password BY \'your_password\'; FLUSH PRIVILEGES;';
            }
            throw new \RuntimeException(
                'OctoWoo: Cannot connect to OpenCart database – ' . $msg,
                (int) $e->getCode()
            );
        }
    }

    /**
     * Return (and lazily open) the raw PDO handle.
     */
    public function getPdo(): \PDO {
        $this->connect();
        return $this->pdo; // @phpstan-ignore-line (non-null after connect())
    }

    /**
     * Close the connection (releases the socket).
     */
    public function close(): void {
        $this->pdo = null;
    }

    /**
     * Test whether credentials are valid without throwing.
     *
     * @return string|null  Null on success, error message string on failure.
     */
    public function testConnection(): ?string {
        try {
            $this->connect();
            $this->getPdo()->query( 'SELECT 1' );
            return null; // success
        } catch ( \Throwable $e ) {
            return $e->getMessage();
        }
    }

    // ── Query helpers ─────────────────────────────────────────────────────────

    /**
     * Execute a prepared statement and return the PDOStatement.
     *
     * @param string              $sql    SQL with ? or :named placeholders.
     * @param array<int|string, mixed> $params Bound parameters.
     */
    public function query( string $sql, array $params = [] ): \PDOStatement {
        $stmt = $this->getPdo()->prepare( $sql );
        $stmt->execute( $params );
        return $stmt;
    }

    /**
     * Fetch all rows as associative arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll( string $sql, array $params = [] ): array {
        return $this->query( $sql, $params )->fetchAll();
    }

    /**
     * Fetch a single row.
     *
     * @return array<string, mixed>|null
     */
    public function fetchRow( string $sql, array $params = [] ): ?array {
        $row = $this->query( $sql, $params )->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Fetch a single scalar value.
     */
    public function fetchColumn( string $sql, array $params = [], int $col = 0 ): mixed {
        $val = $this->query( $sql, $params )->fetchColumn( $col );
        return $val !== false ? $val : null;
    }

    /**
     * Count rows in an OC table.
     *
     * @param string $table   Table name WITHOUT prefix.
     * @param string $where   WHERE clause (defaults to "1=1").
     * @param array  $params  Bound values for the WHERE clause.
     */
    public function count( string $table, string $where = '1=1', array $params = [] ): int {
        $qualified = $this->prefix . $table;
        // Table names are set by us (not user input), safe to interpolate.
        $sql = "SELECT COUNT(*) FROM `{$qualified}` WHERE {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL
        return (int) $this->fetchColumn( $sql, $params );
    }

    /**
     * Paginated SELECT for batch processing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchBatch(
        string $sql,
        array $params,
        int $limit,
        int $offset
    ): array {
        // Append LIMIT / OFFSET (values are integers – safe to interpolate).
        $sql .= " LIMIT {$limit} OFFSET {$offset}"; // phpcs:ignore WordPress.DB.PreparedSQL
        return $this->fetchAll( $sql, $params );
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    /**
     * Return the resolved table prefix (e.g. "oc_").
     */
    public function getPrefix(): string {
        return $this->prefix;
    }

    /**
     * Convenience: return a fully-qualified table name.
     */
    public function table( string $name ): string {
        return $this->prefix . $name;
    }
}
