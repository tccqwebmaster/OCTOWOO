<?php
/**
 * Filter migrator.
 *
 * Reads OpenCart filter groups and filters and creates WooCommerce product
 * attributes (informational – NOT used for variations).  Each filter group
 * becomes a "pa_" global attribute; each filter becomes a term in that
 * taxonomy; product-to-filter assignments are then applied.
 *
 * OpenCart tables used:
 *   oc_filter_group             – filter_group_id, sort_order
 *   oc_filter_group_description – filter_group_id, language_id, name
 *   oc_filter                   – filter_id, filter_group_id, sort_order
 *   oc_filter_description       – filter_id, language_id, name
 *   oc_product_filter           – product_id, filter_id
 *
 * Must run AFTER ProductMigrator.
 *
 * @package OctoWoo\Migrators
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class FilterMigrator extends AbstractMigrator {

    private const KEY = 'filters';

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        $resume_id = $this->checkpoint->getLastId( self::KEY );

        if ( $resume_id === PHP_INT_MAX ) {
            $this->logger->info( '[filters] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        // Phase 1: Build filter-group → WC attribute map.
        $group_map = $this->migrateFilterGroups();   // oc filter_group_id => WC attribute slug

        // Phase 2: Build filter → WC term map.
        $term_map  = $this->migrateFilters( $group_map );  // oc filter_id => WC term_id

        // Phase 3: Assign filter terms to products.
        return $this->migrateProductFilters( $term_map );
    }

    // ── Phase 1: Filter groups → WC attributes ────────────────────────────────

    /**
     * @return array<int, string>  oc_filter_group_id → WC attribute slug (without "pa_" prefix)
     */
    private function migrateFilterGroups(): array {
        $pfx     = $this->pfx();
        $lang_id = $this->langId();
        $map     = [];

        $groups = $this->oc->fetchAll(
            "SELECT fg.filter_group_id, fg.sort_order,
                    COALESCE( fgd_lang.name, fgd_any.name ) AS name
             FROM `{$pfx}filter_group` fg
             LEFT JOIN `{$pfx}filter_group_description` fgd_lang
                    ON fgd_lang.filter_group_id = fg.filter_group_id
                   AND fgd_lang.language_id = {$lang_id}
             LEFT JOIN (
                 SELECT filter_group_id, name
                 FROM `{$pfx}filter_group_description`
                 GROUP BY filter_group_id
             ) fgd_any ON fgd_any.filter_group_id = fg.filter_group_id
             ORDER BY fg.sort_order ASC, fg.filter_group_id ASC",
            []
        );

        foreach ( $groups as $group ) {
            $gid  = (int) $group['filter_group_id'];
            $name = $this->sanitizeText( $group['name'] ?? "Filter Group {$gid}" );
            $slug = 'filter-' . $this->toSlug( $name );

            if ( $this->isDry() ) {
                $map[ $gid ] = $slug;
                $this->logger->debug( "[DRY-RUN] Would create WC attribute: {$name} (slug: pa_{$slug})" );
                continue;
            }

            $attr_id = $this->ensureProductAttribute( $name, $slug );
            if ( $attr_id ) {
                $map[ $gid ] = $slug;
                $this->logger->debug( "[filters] Ensured WC attribute [{$name}] (pa_{$slug})" );
            }
        }

        return $map;
    }

    // ── Phase 2: Filters → WC attribute terms ────────────────────────────────

    /**
     * @param  array<int, string> $group_map
     * @return array<int, int>    oc_filter_id → WC term_id
     */
    private function migrateFilters( array $group_map ): array {
        $pfx     = $this->pfx();
        $lang_id = $this->langId();
        $map     = [];

        if ( empty( $group_map ) ) {
            return $map;
        }

        $filters = $this->oc->fetchAll(
            "SELECT f.filter_id, f.filter_group_id, f.sort_order,
                    COALESCE( fd_lang.name, fd_any.name ) AS name
             FROM `{$pfx}filter` f
             LEFT JOIN `{$pfx}filter_description` fd_lang
                    ON fd_lang.filter_id = f.filter_id
                   AND fd_lang.language_id = {$lang_id}
             LEFT JOIN (
                 SELECT filter_id, name FROM `{$pfx}filter_description` GROUP BY filter_id
             ) fd_any ON fd_any.filter_id = f.filter_id
             ORDER BY f.filter_group_id ASC, f.sort_order ASC, f.filter_id ASC",
            []
        );

        foreach ( $filters as $filter ) {
            $fid   = (int) $filter['filter_id'];
            $gid   = (int) $filter['filter_group_id'];
            $name  = $this->sanitizeText( $filter['name'] ?? "Filter {$fid}" );

            if ( ! isset( $group_map[ $gid ] ) ) {
                continue; // Parent group wasn't mapped.
            }

            $taxonomy = 'pa_' . $group_map[ $gid ];

            if ( $this->isDry() ) {
                $this->logger->debug( "[DRY-RUN] Would create term [{$name}] in {$taxonomy}" );
                continue;
            }

            // Check for existing term.
            $existing_term = get_term_by( 'name', $name, $taxonomy );
            if ( $existing_term && ! is_wp_error( $existing_term ) ) {
                $map[ $fid ] = (int) $existing_term->term_id;
                continue;
            }

            $term = wp_insert_term( $name, $taxonomy );
            if ( is_wp_error( $term ) ) {
                $this->logger->warning( "[filters] Could not create term [{$name}] in {$taxonomy}: " . $term->get_error_message() );
                continue;
            }

            $map[ $fid ] = (int) $term['term_id'];
        }

        return $map;
    }

    // ── Phase 3: Product-filter assignments ───────────────────────────────────

    /**
     * @param  array<int, int> $term_map  oc_filter_id → WC term_id
     * @return array{processed: int, skipped: int, failed: int}
     */
    private function migrateProductFilters( array $term_map ): array {
        $pfx     = $this->pfx();
        $resume_id = $this->checkpoint->getLastId( self::KEY );

        $total_callback = function () use ( $pfx ): int {
            return (int) $this->oc->fetchColumn(
                "SELECT COUNT(DISTINCT product_id) FROM `{$pfx}product_filter`"
            );
        };

        // Group filters by product so we make one wp_set_object_terms call per taxonomy per product.
        $batch_callback = function ( int $offset, int $limit ) use ( $pfx ): array {
            return $this->oc->fetchBatch(
                "SELECT DISTINCT product_id FROM `{$pfx}product_filter` ORDER BY product_id ASC",
                [],
                $limit,
                $offset
            );
        };

        $item_callback = function ( array $row ) use ( $pfx, $term_map ): bool {
            return $this->assignProductFilters( (int) $row['product_id'], $pfx, $term_map );
        };

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

    private function assignProductFilters( int $oc_product_id, string $pfx, array $term_map ): bool {
        $wc_product_id = $this->checkpoint->getWcId( 'product', $oc_product_id );
        if ( ! $wc_product_id ) {
            return false;
        }

        // Fetch all filter IDs for this product.
        $rows = $this->oc->fetchAll(
            "SELECT pf.filter_id, f.filter_group_id
             FROM `{$pfx}product_filter` pf
             JOIN `{$pfx}filter` f ON f.filter_id = pf.filter_id
             WHERE pf.product_id = ?",
            [ $oc_product_id ]
        );

        if ( empty( $rows ) ) {
            return false;
        }

        if ( $this->isDry() ) {
            $this->logger->debug( "[DRY-RUN] Would assign " . count( $rows ) . " filter(s) to WC product #{$wc_product_id}" );
            return true;
        }

        // Group by attribute taxonomy so we call wp_set_object_terms once per taxonomy.
        $by_taxonomy = [];
        foreach ( $rows as $row ) {
            $filter_id = (int) $row['filter_id'];
            if ( ! isset( $term_map[ $filter_id ] ) ) {
                continue;
            }
            // Infer taxonomy from product_attributes stored on product meta.
            // We re-fetch to find the pa_slug via the filter_group_id mapping.
            $by_taxonomy[ $filter_id ] = $term_map[ $filter_id ];
        }

        if ( empty( $by_taxonomy ) ) {
            return false;
        }

        // Assign all filter term IDs (append, preserve existing).
        wp_set_object_terms( (int) $wc_product_id, array_values( $by_taxonomy ), 'product_attr_filter', true );

        // Build / update the product's _product_attributes meta so the attribute tab shows in frontend.
        $this->updateProductAttributesMeta( (int) $wc_product_id, $by_taxonomy );

        $this->logger->debug( "[filters] Assigned " . count( $by_taxonomy ) . " filter term(s) to WC product #{$wc_product_id}" );

        return true;
    }

    /**
     * Merge the filter terms into the product's serialised _product_attributes meta.
     *
     * @param  int         $product_id  WC post ID.
     * @param  array<int,int> $term_ids  WC term IDs to merge in.
     */
    private function updateProductAttributesMeta( int $product_id, array $term_ids ): void {
        // Organise into grouped by term taxonomy.
        $terms_by_tax = [];
        foreach ( $term_ids as $term_id ) {
            $term = get_term( $term_id );
            if ( $term && ! is_wp_error( $term ) ) {
                $terms_by_tax[ $term->taxonomy ][] = $term->term_id;
            }
        }

        if ( empty( $terms_by_tax ) ) {
            return;
        }

        $attributes = get_post_meta( $product_id, '_product_attributes', true ) ?: [];

        foreach ( $terms_by_tax as $taxonomy => $ids ) {
            $slug = str_replace( 'pa_', '', $taxonomy );
            $attr_name = wc_attribute_label( $taxonomy ) ?: ucwords( str_replace( '-', ' ', $slug ) );

            if ( ! isset( $attributes[ $taxonomy ] ) ) {
                $attributes[ $taxonomy ] = [
                    'name'         => $attr_name,
                    'value'        => '',
                    'position'     => 0,
                    'is_visible'   => 1,
                    'is_variation' => 0,
                    'is_taxonomy'  => 1,
                ];
            } else {
                $attributes[ $taxonomy ]['is_visible'] = 1;
            }
        }

        update_post_meta( $product_id, '_product_attributes', $attributes );
    }
}
