<?php
/**
 * Tag migrator.
 *
 * In OpenCart 2/3/4 product tags are stored as a comma-separated string
 * in oc_product_description.tag (per language).
 * This migrator reads those strings and assigns product_tag taxonomy terms
 * to already-migrated WooCommerce products.
 *
 * Must run AFTER ProductMigrator so the ID map is populated.
 *
 * @package OctoWoo\Migrators
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class TagMigrator extends AbstractMigrator {

    private const KEY = 'tags';

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        $resume_id = $this->checkpoint->getLastId( self::KEY );

        if ( $resume_id === PHP_INT_MAX ) {
            if ( $this->onDuplicate() !== 'update' ) {
                $this->logger->info( '[tags] Already completed – skipping.' );
                return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
            }
            $resume_id = 0; // Update mode: re-process all tags from the start.
        }

        $pfx     = $this->pfx();
        $lang_id = $this->langId();

        // oc_product_description.tag is a comma-separated string.
        // We iterate products that have a non-empty tag column.
        $total_callback = function () use ( $pfx, $lang_id ): int {
            return (int) $this->oc->fetchColumn(
                "SELECT COUNT(*) FROM `{$pfx}product_description`
                 WHERE language_id = {$lang_id} AND `tag` != '' AND `tag` IS NOT NULL"
            );
        };

        $batch_callback = function ( int $offset, int $limit ) use ( $pfx, $lang_id ): array {
            return $this->oc->fetchBatch(
                "SELECT product_id, `tag`
                 FROM `{$pfx}product_description`
                 WHERE language_id = {$lang_id} AND `tag` != '' AND `tag` IS NOT NULL
                 ORDER BY product_id ASC",
                [],
                $limit,
                $offset
            );
        };

        $item_callback = function ( array $row ): bool {
            return $this->processTagRow( $row );
        };

        // Diagnose upfront so the log is clear when OpenCart has no tag data.
        $tag_total = $total_callback();
        if ( $tag_total === 0 ) {
            $this->logger->info(
                '[tags] oc_product_description.tag is empty for all products in this language – ' .
                'no WooCommerce product tags will be created. ' .
                'If you expected tags, verify that the tag column is populated in your OpenCart database.'
            );
        }

        return $this->batch->run(
            total_callback:  $total_callback,
            batch_callback:  $batch_callback,
            item_callback:   $item_callback,
            migrator:        self::KEY,
            checkpoint:      $this->checkpoint,
            resume_after_id: $resume_id,
            id_field:        'product_id'
        );
    }

    // ── Per-item processing ───────────────────────────────────────────────────

    private function processTagRow( array $row ): bool {
        $oc_product_id = (int) $row['product_id'];
        $tag_string    = trim( $row['tag'] ?? '' );

        if ( $tag_string === '' ) {
            return false;
        }

        // Resolve WC product from ID map.
        $wc_product_id = $this->checkpoint->getWcId( 'product', $oc_product_id );
        if ( ! $wc_product_id ) {
            $this->logger->debug( "[tags] OC product #{$oc_product_id} not in ID map – skipping." );
            return false;
        }

        // Parse the comma-delimited list.
        $tags = array_values(
            array_filter(
                array_map( 'sanitize_text_field', explode( ',', $tag_string ) ),
                fn( string $t ) => $t !== ''
            )
        );

        if ( empty( $tags ) ) {
            return false;
        }

        if ( $this->isDry() ) {
            $this->logger->debug(
                sprintf(
                    '[DRY-RUN] Would assign %d tag(s) [%s] to WC product #%d',
                    count( $tags ),
                    implode( ', ', $tags ),
                    $wc_product_id
                )
            );
            return true;
        }

        // Append tags (do not remove existing ones set by other sources).
        $result = wp_set_object_terms( (int) $wc_product_id, $tags, 'product_tag', true );

        if ( is_wp_error( $result ) ) {
            $this->logger->error(
                "[tags] Failed for WC product #{$wc_product_id}: " . $result->get_error_message()
            );
            return false;
        }

        $this->logger->debug(
            sprintf( '[tags] Assigned %d tag(s) to WC product #%d.', count( $tags ), $wc_product_id )
        );

        return true;
    }
}
