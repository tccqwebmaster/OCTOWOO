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
        // Pre-load all existing product_tag terms into memory.
        // Avoids get_term_by() DB query per tag per product (would be 20,000+ queries).
        global $wpdb;
        $existing_tags = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id
             FROM {$wpdb->terms} t
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = 'product_tag'",
            ARRAY_A
        );
        $tag_name_map  = []; // lowercase name → term_taxonomy_id
        $tag_termid_map = []; // lowercase name → term_id
        foreach ( $existing_tags as $et ) {
            $key = strtolower( $et['name'] );
            $tag_name_map[ $key ]   = (int) $et['term_taxonomy_id'];
            $tag_termid_map[ $key ] = (int) $et['term_id'];
        }

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

        $item_callback = function ( array $row ) use ( &$tag_name_map, &$tag_termid_map ): bool {
            return $this->processTagRow( $row, $tag_name_map, $tag_termid_map );
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

    private function processTagRow( array $row, array &$tag_name_map, array &$tag_termid_map ): bool {
        $oc_product_id = (int) $row['product_id'];
        $tag_string    = trim( $row['tag'] ?? '' );

        if ( $tag_string === '' ) { return false; }

        $wc_product_id = $this->checkpoint->getWcId( 'product', $oc_product_id );
        if ( ! $wc_product_id ) { return false; }

        $tags = array_values( array_filter(
            array_map( 'sanitize_text_field', explode( ',', $tag_string ) ),
            fn( string $t ) => $t !== ''
        ) );

        if ( empty( $tags ) ) { return false; }
        if ( $this->isDry() ) { return true; }

        global $wpdb;
        $ttids = [];

        foreach ( $tags as $tag_name ) {
            $key = strtolower( $tag_name );

            // Use cached term if it exists — zero DB queries for known tags.
            if ( isset( $tag_name_map[ $key ] ) ) {
                $ttids[] = $tag_name_map[ $key ];
                continue;
            }

            // Create new tag directly via DB — faster than wp_insert_term().
            $slug = sanitize_title( $tag_name );
            $wpdb->insert( $wpdb->terms, [ 'name' => $tag_name, 'slug' => $slug, 'term_group' => 0 ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $term_id = (int) $wpdb->insert_id;
            if ( ! $term_id ) {
                // Slug conflict — fetch existing.
                $term_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "SELECT term_id FROM {$wpdb->terms} WHERE slug = %s LIMIT 1", $slug
                ) );
                if ( ! $term_id ) { continue; }
            }
            $wpdb->insert( $wpdb->term_taxonomy, [ 'term_id' => $term_id, 'taxonomy' => 'product_tag', 'description' => '', 'parent' => 0, 'count' => 0 ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $tt_id = (int) $wpdb->insert_id;
            if ( ! $tt_id ) {
                $tt_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'product_tag' LIMIT 1", $term_id
                ) );
            }
            // Cache for subsequent products.
            $tag_name_map[ $key ]    = $tt_id;
            $tag_termid_map[ $key ]  = $term_id;
            $ttids[] = $tt_id;
        }

        if ( empty( $ttids ) ) { return false; }

        // Insert term relationships directly — faster than wp_set_object_terms().
        foreach ( $ttids as $tt_id ) {
            $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
                "INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id) VALUES (%d, %d)",
                $wc_product_id, $tt_id
            ) );
        }
        // We skip per-product is_wp_error check since we're using direct DB.

        $this->logger->debug(
            sprintf( '[tags] Assigned %d tag(s) to WC product #%d.', count( $tags ), $wc_product_id )
        );

        return true;
    }
}
