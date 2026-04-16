<?php
/**
 * Pre-migration system validator.
 *
 * Runs a suite of server and configuration checks before a migration starts
 * and returns a structured report so the admin UI can show a clear "ready / not ready"
 * status with actionable fix instructions.
 *
 * Usage:
 *   $validator = new Validator( AdminPage::getConfig() );
 *   $results   = $validator->run();
 *   if ( ! $validator->allPassed( $results ) ) { ... }
 */

namespace OctoWoo\Core;

defined( 'ABSPATH' ) || exit;

class Validator {

    const STATUS_PASS    = 'pass';
    const STATUS_WARNING = 'warning';
    const STATUS_FAIL    = 'fail';

    /** @var array<string,mixed> Resolved plugin configuration. */
    private array $config;

    public function __construct( array $config ) {
        $this->config = $config;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Run all validation checks.
     *
     * @return array<string, array{status:string,message:string,value?:string,fix?:string}>
     */
    public function run(): array {
        return [
            'woocommerce'    => $this->checkWooCommerce(),
            'php_version'    => $this->checkPhpVersion(),
            'php_extensions' => $this->checkPhpExtensions(),
            'memory_limit'   => $this->checkMemoryLimit(),
            'upload_limit'   => $this->checkUploadLimit(),
            'max_execution'  => $this->checkMaxExecution(),
            'db_connection'  => $this->checkDbConnection(),
            'image_path'     => $this->checkImagePath(),
            'log_directory'  => $this->checkLogDirectory(),
            'disk_space'     => $this->checkDiskSpace(),
            'hpos_compat'    => $this->checkHposCompat(),
        ];
    }

    /**
     * Returns true only when no check has status=fail.
     *
     * @param  array<string, array{status:string}> $results  Output of run().
     */
    public function allPassed( array $results ): bool {
        foreach ( $results as $check ) {
            if ( ( $check['status'] ?? '' ) === self::STATUS_FAIL ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns true when all checks pass or warn (no hard failures).
     *
     * @param  array<string, array{status:string}> $results  Output of run().
     */
    public function hasWarningsOnly( array $results ): bool {
        $has_warning = false;
        foreach ( $results as $check ) {
            $status = $check['status'] ?? self::STATUS_PASS;
            if ( $status === self::STATUS_FAIL ) {
                return false;
            }
            if ( $status === self::STATUS_WARNING ) {
                $has_warning = true;
            }
        }
        return $has_warning;
    }

    // ── Individual checks ─────────────────────────────────────────────────────

    private function checkWooCommerce(): array {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return $this->result(
                self::STATUS_FAIL,
                __( 'WooCommerce is not active.', 'octowoo' ),
                null,
                __( 'Activate WooCommerce before running a migration.', 'octowoo' )
            );
        }

        $version = defined( 'WC_VERSION' ) ? WC_VERSION : '0';

        if ( version_compare( $version, '6.0', '<' ) ) {
            return $this->result(
                self::STATUS_WARNING,
                /* translators: %s WooCommerce version number */
                sprintf( __( 'WooCommerce %s detected. Version 6.0+ is recommended.', 'octowoo' ), $version ),
                $version
            );
        }

        return $this->result(
            self::STATUS_PASS,
            /* translators: %s WooCommerce version number */
            sprintf( __( 'WooCommerce %s is active.', 'octowoo' ), $version ),
            $version
        );
    }

    private function checkPhpVersion(): array {
        $version = PHP_VERSION;

        if ( version_compare( $version, '8.0', '>=' ) ) {
            return $this->result( self::STATUS_PASS, sprintf( __( 'PHP %s', 'octowoo' ), $version ), $version );
        }

        if ( version_compare( $version, '7.4', '>=' ) ) {
            return $this->result(
                self::STATUS_WARNING,
                /* translators: %s PHP version */
                sprintf( __( 'PHP %s is supported but upgrading to PHP 8.0+ is recommended.', 'octowoo' ), $version ),
                $version
            );
        }

        return $this->result(
            self::STATUS_FAIL,
            /* translators: %s PHP version */
            sprintf( __( 'PHP %s is too old. PHP 7.4+ is required.', 'octowoo' ), $version ),
            $version,
            __( 'Contact your host to upgrade PHP.', 'octowoo' )
        );
    }

    private function checkPhpExtensions(): array {
        $required = [ 'pdo', 'pdo_mysql', 'mbstring', 'json' ];
        $optional = [ 'zip' ];

        $missing_required = array_values( array_filter( $required, fn( $e ) => ! extension_loaded( $e ) ) );
        $missing_optional = array_values( array_filter( $optional, fn( $e ) => ! extension_loaded( $e ) ) );

        if ( ! empty( $missing_required ) ) {
            return $this->result(
                self::STATUS_FAIL,
                /* translators: %s comma-separated extension names */
                sprintf( __( 'Required PHP extensions missing: %s', 'octowoo' ), implode( ', ', $missing_required ) ),
                null,
                __( 'Ask your hosting provider to enable these PHP extensions.', 'octowoo' )
            );
        }

        if ( ! empty( $missing_optional ) ) {
            return $this->result(
                self::STATUS_WARNING,
                /* translators: %s comma-separated extension names */
                sprintf( __( 'Optional extension missing (ZIP import disabled): %s', 'octowoo' ), implode( ', ', $missing_optional ) )
            );
        }

        return $this->result( self::STATUS_PASS, __( 'All required PHP extensions are loaded.', 'octowoo' ) );
    }

    private function checkMemoryLimit(): array {
        $raw      = ini_get( 'memory_limit' );
        $bytes    = $this->parseBytes( $raw );
        $limit_mb = $bytes < 0 ? PHP_INT_MAX : (int) ( $bytes / (1024 * 1024) );

        if ( $bytes < 0 ) {
            return $this->result( self::STATUS_PASS, __( 'Memory limit: unlimited', 'octowoo' ), 'unlimited' );
        }

        if ( $limit_mb >= 256 ) {
            return $this->result( self::STATUS_PASS, sprintf( __( 'Memory limit: %dMB', 'octowoo' ), $limit_mb ), $raw );
        }

        if ( $limit_mb >= 128 ) {
            return $this->result(
                self::STATUS_WARNING,
                /* translators: %d memory value in MB */
                sprintf( __( 'Memory limit: %dMB. 256MB+ recommended for large catalogs.', 'octowoo' ), $limit_mb ),
                $raw,
                __( 'Add "php_value memory_limit 256M" to your .htaccess or contact your host.', 'octowoo' )
            );
        }

        return $this->result(
            self::STATUS_FAIL,
            /* translators: %d memory value in MB */
            sprintf( __( 'Memory limit: %dMB is insufficient. Minimum 128MB required.', 'octowoo' ), $limit_mb ),
            $raw,
            __( 'Increase memory_limit to at least 128M in php.ini or .htaccess.', 'octowoo' )
        );
    }

    private function checkUploadLimit(): array {
        $upload_bytes = $this->parseBytes( (string) ini_get( 'upload_max_filesize' ) );
        $post_bytes   = $this->parseBytes( (string) ini_get( 'post_max_size' ) );
        $effective    = ( $upload_bytes > 0 && $post_bytes > 0 ) ? min( $upload_bytes, $post_bytes ) : max( $upload_bytes, $post_bytes );
        $effective_mb = (int) ( $effective / (1024 * 1024) );

        if ( $effective_mb >= 64 ) {
            return $this->result( self::STATUS_PASS, sprintf( __( 'Upload limit: %dMB', 'octowoo' ), $effective_mb ), "{$effective_mb}MB" );
        }

        return $this->result(
            self::STATUS_WARNING,
            /* translators: %d upload limit in MB */
            sprintf( __( 'Upload limit: %dMB. Increase to 64MB+ for large SQL dumps.', 'octowoo' ), $effective_mb ),
            "{$effective_mb}MB",
            __( 'Add "php_value upload_max_filesize 128M" and "php_value post_max_size 128M" to your .htaccess.', 'octowoo' )
        );
    }

    private function checkMaxExecution(): array {
        $max = (int) ini_get( 'max_execution_time' );

        if ( $max === 0 ) {
            return $this->result( self::STATUS_PASS, __( 'max_execution_time: unlimited', 'octowoo' ), '0' );
        }

        if ( $max >= 300 ) {
            return $this->result( self::STATUS_PASS, sprintf( __( 'max_execution_time: %ds', 'octowoo' ), $max ), "{$max}s" );
        }

        return $this->result(
            self::STATUS_WARNING,
            /* translators: %d execution time in seconds */
            sprintf( __( 'max_execution_time: %ds. Use Background mode or WP-CLI for large catalogs.', 'octowoo' ), $max ),
            "{$max}s",
            __( 'Use "Start in Background" mode which does not rely on PHP execution time.', 'octowoo' )
        );
    }

    private function checkDbConnection(): array {
        $source = $this->config['source'] ?? 'remote';

        if ( $source === 'local' ) {
            $info = SqlImporter::getImportedInfo();
            $tables = count( (array) ( $info['tables'] ?? [] ) );

            if ( $tables === 0 ) {
                return $this->result(
                    self::STATUS_WARNING,
                    __( 'Local mode: no SQL dump imported yet.', 'octowoo' ),
                    null,
                    __( 'Upload a SQL dump using the "Import SQL" section first.', 'octowoo' )
                );
            }

            return $this->result(
                self::STATUS_PASS,
                /* translators: %d number of tables */
                sprintf( __( 'Local mode: %d OpenCart tables ready.', 'octowoo' ), $tables ),
                "{$tables} tables"
            );
        }

        // Remote mode — attempt live connection.
        $db = $this->config['db'] ?? [];
        if ( empty( $db['host'] ) || empty( $db['database'] ) || empty( $db['username'] ) ) {
            return $this->result(
                self::STATUS_FAIL,
                __( 'OpenCart database credentials are not configured.', 'octowoo' ),
                null,
                __( 'Go to Settings and enter the Host, Database, Username, and Password for your OpenCart database.', 'octowoo' )
            );
        }

        $db_config           = $db;
        $db_config['source'] = $this->config['source'] ?? 'remote';
        $connector = new DatabaseConnector( $db_config );
        $error     = $connector->testConnection();
        $connector->close();

        if ( $error === null ) {
            return $this->result( self::STATUS_PASS, __( 'OpenCart database connection successful.', 'octowoo' ) );
        }

        return $this->result(
            self::STATUS_FAIL,
            $error,
            null,
            __( 'Check the DB Host, Port, Database, Username, and Password in Settings.', 'octowoo' )
        );
    }

    private function checkImagePath(): array {
        $image_path = trim( $this->config['opencart']['image_path'] ?? '' );

        if ( $image_path === '' ) {
            return $this->result(
                self::STATUS_WARNING,
                __( 'Image path not configured. Product images will not be migrated.', 'octowoo' ),
                null,
                __( 'Set the "OpenCart Image Path" in Settings to enable image migration.', 'octowoo' )
            );
        }

        // Looks like a URL — remote/mounted path.
        if ( filter_var( $image_path, FILTER_VALIDATE_URL ) ) {
            return $this->result(
                self::STATUS_WARNING,
                __( 'Image path is a URL. Ensure the remote server allows direct file access.', 'octowoo' ),
                $image_path
            );
        }

        if ( is_dir( $image_path ) && is_readable( $image_path ) ) {
            return $this->result( self::STATUS_PASS, __( 'Image directory is accessible.', 'octowoo' ), $image_path );
        }

        return $this->result(
            self::STATUS_FAIL,
            /* translators: %s directory path */
            sprintf( __( 'Image path "%s" does not exist or is not readable.', 'octowoo' ), $image_path ),
            $image_path,
            __( 'Verify the OpenCart image directory path or mount it on this server.', 'octowoo' )
        );
    }

    private function checkLogDirectory(): array {
        $log_dir = OCTOWOO_LOG_DIR;

        if ( is_dir( $log_dir ) && is_writable( $log_dir ) ) {
            return $this->result( self::STATUS_PASS, __( 'Log directory is writable.', 'octowoo' ) );
        }

        return $this->result(
            self::STATUS_WARNING,
            __( 'Log directory is not writable. File logging will be disabled.', 'octowoo' ),
            $log_dir,
            __( 'Run the plugin activator once or ensure the web server can write to the /logs/ directory.', 'octowoo' )
        );
    }

    private function checkDiskSpace(): array {
        $upload_dir = wp_upload_dir();
        $free       = disk_free_space( $upload_dir['basedir'] );

        if ( $free === false ) {
            return $this->result( self::STATUS_WARNING, __( 'Could not determine available disk space.', 'octowoo' ) );
        }

        $free_gb = round( $free / (1024 * 1024 * 1024), 1 );

        if ( $free_gb >= 1.0 ) {
            return $this->result(
                self::STATUS_PASS,
                /* translators: %.1f disk space in GB */
                sprintf( __( 'Available disk space: %.1f GB', 'octowoo' ), $free_gb ),
                "{$free_gb}GB"
            );
        }

        if ( $free_gb >= 0.2 ) {
            return $this->result(
                self::STATUS_WARNING,
                /* translators: %.1f disk space in GB */
                sprintf( __( 'Low disk space: %.1f GB. Large image sets may not fully import.', 'octowoo' ), $free_gb ),
                "{$free_gb}GB"
            );
        }

        return $this->result(
            self::STATUS_FAIL,
            /* translators: %.1f disk space in GB */
            sprintf( __( 'Very low disk space: %.1f GB. Image migration will likely fail.', 'octowoo' ), $free_gb ),
            "{$free_gb}GB",
            __( 'Free up disk space before migrating images.', 'octowoo' )
        );
    }

    private function checkHposCompat(): array {
        // Check if WC HPOS is enabled.
        if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
            return $this->result( self::STATUS_PASS, __( 'WooCommerce HPOS: using legacy post-based orders (compatible).', 'octowoo' ) );
        }

        $hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ( $hpos_enabled ) {
            return $this->result(
                self::STATUS_PASS,
                __( 'WooCommerce HPOS is enabled. OctoWoo uses the WC Orders API (compatible).', 'octowoo' ),
                'HPOS'
            );
        }

        return $this->result( self::STATUS_PASS, __( 'WooCommerce running in legacy order mode (compatible).', 'octowoo' ) );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param  string      $status   One of STATUS_* constants.
     * @param  string      $message  Human-readable check result.
     * @param  string|null $value    Optional raw value being tested.
     * @param  string|null $fix      Optional actionable fix hint shown on failure/warning.
     * @return array{status:string,message:string,value?:string,fix?:string}
     */
    private function result( string $status, string $message, ?string $value = null, ?string $fix = null ): array {
        $r = [ 'status' => $status, 'message' => $message ];
        if ( $value !== null ) {
            $r['value'] = $value;
        }
        if ( $fix !== null ) {
            $r['fix'] = $fix;
        }
        return $r;
    }

    /**
     * Convert PHP shorthand memory notation to bytes.
     * Returns -1 for unlimited (-1), and PHP_INT_MAX for unlimited (0).
     */
    private function parseBytes( string $value ): int {
        $value = trim( $value );
        if ( $value === '-1' ) {
            return -1;
        }
        $unit  = strtolower( substr( $value, -1 ) );
        $bytes = (int) $value;
        switch ( $unit ) {
            case 'g':
                $bytes *= 1073741824;
                break;
            case 'm':
                $bytes *= 1048576;
                break;
            case 'k':
                $bytes *= 1024;
                break;
        }
        return $bytes;
    }
}
