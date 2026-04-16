<?php
/**
 * Pre-migration scanner.
 *
 * Reads the OpenCart database BEFORE any migration starts and returns a
 * structured summary of source record counts plus any potential issues:
 *
 *  - Source entity counts (products, categories, orders, …).
 *  - Images missing from the configured local image directory.
 *    (Non-zero missing count triggers the HTTP fallback during migration.)
 *  - Products whose option combinations exceed WooCommerce's practical
 *    variation limit of 50 — these are automatically converted to simple
 *    products. The scan shows which ones will be affected.
 *  - Products with no description in the primary language — these are
 *    skipped during migration to avoid blank listings.
 *
 * This class is read-only; it never writes to either database.
 */

namespace OctoWoo\Core;

defined( 'ABSPATH' ) || exit;

class PreMigrationScanner {

    /** WooCommerce practical upper limit for variation combinations per product. */
    private const VARIATION_LIMIT = 50;

    /** @var DatabaseConnector */
    private DatabaseConnector $db;

    /** @var array<string, mixed> Resolved plugin config. */
    private array $config;

    // ── Construction ──────────────────────────────────────────────────────────

    public function __construct( DatabaseConnector $db, array $config ) {
        $this->db     = $db;
        $this->config = $config;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Run all checks and return a structured scan result.
     *
     * @return array{
     *   counts: array<string,int>,
     *   missing_images: array{total:int, missing:int, sample:string[]},
     *   oversize_variations: array{total:int, product_ids:int[]},
     *   no_description: int,
     *   warnings: string[],
     *   issues: string[]
     * }
     */
    public function scan(): array {
        $issues   = [];
        $warnings = [];

        // 1. Source record counts.
        $counts = $this->scanCounts();

        // 2. Missing images.
        $image_info = $this->scanImages();
        if ( $image_info['missing'] > 0 ) {
            $warnings[] = sprintf(
                '%d of %d distinct image paths are not found on the local filesystem. '
                . 'The HTTP fallback will be attempted during migration.',
                $image_info['missing'],
                $image_info['total']
            );
        }

        // 3. Variation explosion.
        $oversize = $this->scanOversizeVariations();
        if ( $oversize['total'] > 0 ) {
            $warnings[] = sprintf(
                '%d product(s) have more than %d variation combinations. '
                . 'They will be imported as simple products with informational attributes.',
                $oversize['total'],
                self::VARIATION_LIMIT
            );
        }

        // 4. Products with no primary-language description.
        $no_desc = $this->scanMissingDescriptions();
        if ( $no_desc > 0 ) {
            $warnings[] = sprintf(
                '%d product(s) have no description in the primary language (ID %d) and will be skipped.',
                $no_desc,
                (int) ( $this->config['opencart']['language_id'] ?? 1 )
            );
        }

        return [
            'counts'              => $counts,
            'missing_images'      => $image_info,
            'oversize_variations' => $oversize,
            'no_description'      => $no_desc,
            'warnings'            => $warnings,
            'issues'              => $issues,
        ];
    }

    // ── Individual checks ─────────────────────────────────────────────────────

    /**
     * Fetch entity counts from the most-scanned OC tables.
     *
     * Returns -1 for any table that does not exist or throws an error.
     *
     * @return array<string, int>
     */
    private function scanCounts(): array {
        $tables = [ 'product', 'category', 'customer', 'order', 'review', 'coupon', 'manufacturer' ];
        $counts = [];

        foreach ( $tables as $table ) {
            try {
                $counts[ $table ] = $this->db->count( $table );
            } catch ( \Throwable $e ) {
                $counts[ $table ] = -1;
            }
        }

        return $counts;
    }

    /**
     * Check distinct image paths against the configured local image directory.
     *
     * @return array{total:int, missing:int, sample:string[]}
     */
    private function scanImages(): array {
        $pfx = $this->db->getPrefix();

        try {
            $rows = $this->db->fetchAll(
                "SELECT DISTINCT image AS path
                 FROM `{$pfx}product`
                 WHERE image != '' AND image IS NOT NULL
                 UNION
                 SELECT DISTINCT image AS path
                 FROM `{$pfx}product_image`
                 WHERE image != '' AND image IS NOT NULL"
            );
        } catch ( \Throwable $e ) {
            return [ 'total' => 0, 'missing' => 0, 'sample' => [] ];
        }

        $total      = count( $rows );
        $missing    = 0;
        $sample     = [];
        $image_base = rtrim( $this->config['opencart']['image_path'] ?? '', '/\\' );

        // No local path → treat all as missing (will rely on HTTP fallback).
        if ( $image_base === '' ) {
            return [ 'total' => $total, 'missing' => $total, 'sample' => [] ];
        }

        foreach ( $rows as $row ) {
            $safe = ltrim( str_replace( '\\', '/', $row['path'] ), '/' );
            if ( strpos( $safe, '..' ) !== false ) {
                continue; // Skip suspicious paths.
            }
            $abs = $image_base . DIRECTORY_SEPARATOR . $safe;
            if ( ! file_exists( $abs ) ) {
                $missing++;
                if ( count( $sample ) < 5 ) {
                    $sample[] = $row['path'];
                }
            }
        }

        return [ 'total' => $total, 'missing' => $missing, 'sample' => $sample ];
    }

    /**
     * Find products whose variation option-value combinations exceed VARIATION_LIMIT.
     *
     * @return array{total:int, product_ids:int[]}
     */
    private function scanOversizeVariations(): array {
        $pfx = $this->db->getPrefix();

        try {
            $rows = $this->db->fetchAll(
                "SELECT po.product_id,
                        GROUP_CONCAT(pov_count.cnt ORDER BY po.product_option_id SEPARATOR ',') AS counts
                 FROM `{$pfx}product_option` po
                 JOIN (
                     SELECT product_option_id, COUNT(*) AS cnt
                     FROM `{$pfx}product_option_value`
                     GROUP BY product_option_id
                 ) pov_count ON pov_count.product_option_id = po.product_option_id
                 JOIN `{$pfx}option` o
                   ON o.option_id = po.option_id
                  AND o.type IN ('select', 'radio')
                 GROUP BY po.product_id"
            );
        } catch ( \Throwable $e ) {
            return [ 'total' => 0, 'product_ids' => [] ];
        }

        $ids = [];
        foreach ( $rows as $row ) {
            if ( empty( $row['counts'] ) ) {
                continue;
            }
            $counts = array_map( 'intval', explode( ',', (string) $row['counts'] ) );
            if ( (int) array_product( $counts ) > self::VARIATION_LIMIT ) {
                $ids[] = (int) $row['product_id'];
            }
        }

        return [ 'total' => count( $ids ), 'product_ids' => array_slice( $ids, 0, 100 ) ];
    }

    /**
     * Count active products that have no description in the primary language.
     */
    private function scanMissingDescriptions(): int {
        $pfx     = $this->db->getPrefix();
        $lang_id = (int) ( $this->config['opencart']['language_id'] ?? 1 );

        try {
            return (int) $this->db->fetchColumn(
                "SELECT COUNT(*)
                 FROM `{$pfx}product` p
                 WHERE p.status = 1
                   AND NOT EXISTS (
                       SELECT 1
                       FROM `{$pfx}product_description` pd
                       WHERE pd.product_id = p.product_id
                         AND pd.language_id = ?
                   )",
                [ $lang_id ]
            );
        } catch ( \Throwable $e ) {
            return 0;
        }
    }
}
