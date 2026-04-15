<?php
/**
 * OpenCart version detector.
 *
 * Connects to the OC database and inspects table/column structure to determine
 * whether we are talking to OpenCart 1.x, 2.x, 3.x, or 4.x.
 * Exposes helper methods that each migrator can use to emit version-adaptive SQL.
 *
 * @package OctoWoo\Core
 */

namespace OctoWoo\Core;

defined( 'ABSPATH' ) || exit;

class VersionDetector {

    /** @var DatabaseConnector */
    private DatabaseConnector $db;

    /** @var string Detected version string, e.g. "3.0.3.8" */
    private string $version_string = '';

    /** @var int Detected major version: 1, 2, 3, or 4 */
    private int $major = 0;

    /** @var array Column existence cache: "table.column" => bool */
    private array $column_cache = [];

    /** @var array Table existence cache: "table" => bool */
    private array $table_cache = [];

    public function __construct( DatabaseConnector $db ) {
        $this->db = $db;
        $this->detect();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Detection
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Run detection against the connected database.
     */
    private function detect(): void {
        // Strategy 1: oc_setting stores 'config_version'.
        try {
            $row = $this->db->fetchRow(
                "SELECT `value` FROM {$this->db->table('setting')} WHERE `key` = 'config_version' LIMIT 1"
            );
            if ( $row && ! empty( $row['value'] ) ) {
                $this->version_string = (string) $row['value'];
                $this->major = (int) $this->version_string[0];
                return;
            }
        } catch ( \Throwable $e ) {
            // fallthrough
        }

        // Strategy 2: Inspect structural markers.
        // OC4: oc_product has 'subtract' column removed; oc_attribute_group_description exists
        // OC3: oc_product has 'subtract' and oc_seo_url exists without 'language_id' column
        // OC2: oc_seo_url exists with 'language_id' column
        // OC1: no oc_seo_url table

        if ( $this->hasTable( 'seo_url' ) ) {
            // OC 2+ – distinguish 2 vs 3 vs 4.
            if ( $this->hasColumn( 'product', 'date_available' ) && ! $this->hasColumn( 'product', 'options' ) ) {
                // OC4 dropped 'subtract' and changed schema in certain builds
                if ( ! $this->hasColumn( 'seo_url', 'language_id' ) ) {
                    $this->major = 4;
                } else {
                    $this->major = 3;
                }
            } else {
                $this->major = 2;
            }
        } else {
            $this->major = 1;
        }

        $this->version_string = "{$this->major}.x";
    }

    // ──────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Return the detected version string (e.g. "3.0.3.8", "4.x").
     */
    public function getVersion(): string {
        return $this->version_string;
    }

    /**
     * Return the detected major version.
     */
    public function getMajor(): int {
        return $this->major;
    }

    /**
     * Returns true if the OC version is >= $min.
     *
     * @param int $min  Major version number to test against (1–4).
     */
    public function isAtLeast( int $min ): bool {
        return $this->major >= $min;
    }

    /**
     * Check whether a table exists (without prefix – we add it).
     *
     * @param string $table  Short name, e.g. "seo_url".
     */
    public function hasTable( string $table ): bool {
        if ( isset( $this->table_cache[ $table ] ) ) {
            return $this->table_cache[ $table ];
        }

        try {
            $fqn    = $this->db->table( $table );
            $result = $this->db->fetchColumn( "SHOW TABLES LIKE '{$fqn}'" );
            $exists = ! empty( $result );
        } catch ( \Throwable $e ) {
            $exists = false;
        }

        return $this->table_cache[ $table ] = $exists;
    }

    /**
     * Check whether a column exists in a table.
     *
     * @param string $table   Short table name (without prefix).
     * @param string $column  Column name to check.
     */
    public function hasColumn( string $table, string $column ): bool {
        $key = "{$table}.{$column}";
        if ( isset( $this->column_cache[ $key ] ) ) {
            return $this->column_cache[ $key ];
        }

        try {
            $fqn    = $this->db->table( $table );
            $result = $this->db->fetchRow( "SHOW COLUMNS FROM `{$fqn}` LIKE '{$column}'" );
            $exists = ! empty( $result );
        } catch ( \Throwable $e ) {
            $exists = false;
        }

        return $this->column_cache[ $key ] = $exists;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Version-adaptive SQL helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Build the SEO URL lookup fragment appropriate for this OC version.
     *
     * OC1 has no oc_seo_url – returns empty string.
     * OC2 uses keyword column.
     * OC3/4 same structure.
     *
     * @param  string $alias  Table alias to use.
     * @return string  SQL fragment for LEFT JOIN.
     */
    public function seoUrlJoin( string $alias = 'su', string $entity_field = 'product_id', string $entity_value_placeholder = '%d' ): string {
        if ( ! $this->hasTable( 'seo_url' ) ) {
            return '';
        }

        $tbl = $this->db->table( 'seo_url' );

        if ( $this->major === 2 ) {
            return "LEFT JOIN `{$tbl}` AS `{$alias}` ON `{$alias}`.`query` = CONCAT('product_id=', p.product_id)";
        }

        // OC3 / OC4
        return "LEFT JOIN `{$tbl}` AS `{$alias}` ON `{$alias}`.`query` = CONCAT('product_id=', p.product_id) AND `{$alias}`.`store_id` = 0";
    }

    /**
     * Return the column name that holds the SEO keyword/slug.
     */
    public function seoKeywordColumn(): string {
        if ( ! $this->hasTable( 'seo_url' ) ) {
            return '';
        }
        // All versions use 'keyword'
        return 'keyword';
    }

    /**
     * Return the column holding the product quantity.
     * OC4 renamed ``quantity`` to ``stock`` in some builds – we detect.
     */
    public function quantityColumn(): string {
        if ( $this->hasColumn( 'product', 'quantity' ) ) {
            return 'quantity';
        }
        if ( $this->hasColumn( 'product', 'stock' ) ) {
            return 'stock';
        }
        return 'quantity'; // fallback
    }
}
