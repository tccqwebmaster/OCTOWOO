<?php
/**
 * Manufacturer migrator.
 *
 * Reads OpenCart manufacturers (oc_manufacturer) and creates WordPress
 * taxonomy terms in whichever "brand" taxonomy is active on the site.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * Supported brand plugins / taxonomies (auto-detected in priority order):
 *
 *   1. WooCommerce Brands (official WooCommerce extension)
 *      Taxonomy: product_brand
 *
 *   2. Perfect WooCommerce Brands (by PowerfulWP)
 *      Taxonomy: pwb-brand
 *
 *   3. YITH WooCommerce Brands Add-on (free or Premium)
 *      Taxonomy: yith_product_brand
 *
 *   4. Brands for WooCommerce by BeRocket
 *      Taxonomy: berocket_brand
 *
 *   5. Ultimate WooCommerce Brands (by UltimateWoo)
 *      Taxonomy: product_brand   (same slug as #1 – detected earlier)
 *
 *   6. Theme-based / generic brand taxonomy
 *      Taxonomy: brand
 *
 *   Fallback (none of the above active):
 *      Creates a custom "product_manufacturer" taxonomy registered by OctoWoo.
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * OpenCart tables used:
 *   oc_manufacturer         – manufacturer_id, name, image, sort_order
 *   oc_manufacturer_to_store – (filters by store)
 *   oc_product               – (product_id → manufacturer_id link, for assignment)
 *
 * @package OctoWoo\Migrators
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class ManufacturerMigrator extends AbstractMigrator {

    private const KEY = 'manufacturers';
    private const MAP_KEY = 'manufacturer';

    /**
     * Candidate brand taxonomies (in priority order).
     * First one that is registered on the site will be used.
     */
    private const BRAND_TAXONOMY_CANDIDATES = [
        'product_brand',         // WooCommerce Brands (official) · Ultimate WooCommerce Brands
        'pwb-brand',             // Perfect WooCommerce Brands
        'yith_product_brand',    // YITH WooCommerce Brands
        'berocket_brand',        // Brands for WooCommerce by BeRocket
        'pa_brand',              // Attribute-based brand taxonomy used by some setups
        'brand',                 // Generic / theme-based
    ];

    /** Resolved brand taxonomy slug, set once in migrate(). */
    private string $taxonomy = '';

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        $resume_id = $this->checkpoint->getLastId( self::KEY );

        if ( $resume_id === PHP_INT_MAX ) {
            if ( $this->onDuplicate() !== 'update' ) {
                $this->logger->info( '[manufacturers] Already completed – skipping.' );
                return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
            }
            $resume_id = 0; // Update mode: re-process all manufacturer terms + re-assign brands.
        }

        // Resolve taxonomy (create fallback if none found).
        $this->taxonomy = $this->resolveTaxonomy();
        $this->logger->info( "[manufacturers] Using brand taxonomy: {$this->taxonomy}" );

        $pfx = $this->pfx();

        $total_callback = function () use ( $pfx ): int {
            return $this->oc->count( 'manufacturer' );
        };

        $batch_callback = function ( int $offset, int $limit ) use ( $pfx ): array {
            return $this->oc->fetchBatch(
                "SELECT manufacturer_id, name, image, sort_order
                 FROM `{$pfx}manufacturer`
                 ORDER BY manufacturer_id ASC, sort_order ASC",
                [],
                $limit,
                $offset
            );
        };

        $item_callback = function ( array $row ): bool {
            return $this->processManufacturer( $row );
        };

        // If WPML is active, switch to the primary language so that wp_insert_term()
        // calls during this batch are auto-assigned to the correct language by WPML.
        // Without this, brand terms are created without any WPML language metadata and
        // the English admin shows "No Brands Found" even though the terms exist in DB.
        $this->wpmlSwitchToPrimary();

        $result = $this->batch->run(
            total_callback:  $total_callback,
            batch_callback:  $batch_callback,
            item_callback:   $item_callback,
            migrator:        self::KEY,
            checkpoint:      $this->checkpoint,
            resume_after_id: $resume_id,
            id_field:        'manufacturer_id'
        );

        $this->wpmlRestoreLanguage();

        // Phase 2: assign brand terms to products.
        // Run on the final chunk only (is_done = true) so we don't scan all
        // products on every 20-item chunk and cause PHP timeouts.
        // The MIGRATOR_ORDER guarantees products always run before manufacturers,
        // so no additional isCompleted('products') guard is needed — that guard
        // was silently skipping assignment on every run and causing 0 brands.
        $is_done = $result['is_done'] ?? ! $this->batch->isDryRun();
        if ( $is_done ) {
            $this->assignManufacturersToProducts();
        }

        return $result;
    }

    // ── Per-manufacturer processing ───────────────────────────────────────────

    private function processManufacturer( array $row ): bool {
        $oc_id = (int) $row['manufacturer_id'];
        $name  = $this->sanitizeText( $row['name'] ?? '' );

        if ( $name === '' ) {
            $this->logger->warning( "[manufacturers] Empty name for OC manufacturer #{$oc_id} – skipping." );
            return false;
        }

        // Duplicate check.
        $existing_wc_id = $this->checkpoint->getWcId( self::MAP_KEY, $oc_id )
            ?? $this->checkpoint->getWcId( self::KEY, $oc_id );
        if ( $existing_wc_id ) {
            if ( $this->onDuplicate() === 'update' ) {
                return $this->updateBrandTerm( (int) $existing_wc_id, $row );
            }
            $this->logger->debug( "[manufacturers] OC #{$oc_id} already mapped → term #{$existing_wc_id} – skipping." );
            return false;
        }

        if ( $this->isDry() ) {
            $this->logger->debug( "[DRY-RUN] Would create brand term: {$name} (OC #{$oc_id})" );
            return true;
        }

        return $this->createBrandTerm( $oc_id, $row );
    }

    // ── Create / Update ───────────────────────────────────────────────────────

    private function createBrandTerm( int $oc_id, array $row ): bool {
        $name = $this->sanitizeText( $row['name'] );
        $slug = $this->fetchSeoSlug( $oc_id ) ?: $this->toSlug( $name );

        // Insert term.
        $result = wp_insert_term( $name, $this->taxonomy, [ 'slug' => $slug ] );

        // Handle slug conflict.
        if ( is_wp_error( $result ) && $result->get_error_code() === 'term_exists' ) {
            $term_id = (int) $result->get_error_data( 'term_exists' );
            $this->saveManufacturerMap( $oc_id, $term_id );
            update_term_meta( $term_id, '_octowoo_oc_manufacturer_id', $oc_id );
            update_term_meta( $term_id, '_octowoo_oc_id', $oc_id );
            $this->logger->debug( "[manufacturers] OC #{$oc_id} matched existing brand term #{$term_id}: '{$name}'" );
            return false; // Counted as skipped.
        }

        if ( is_wp_error( $result ) ) {
            $this->logger->error( "[manufacturers] Failed to create term '{$name}': " . $result->get_error_message() );
            return false;
        }

        $term_id      = (int) $result['term_id'];

        // Store OC ID for reference (specific and generic keys).
        add_term_meta( $term_id, '_octowoo_oc_manufacturer_id', $oc_id, true );
        update_term_meta( $term_id, '_octowoo_oc_id', $oc_id );

        // Import brand logo.
        if ( ! empty( $row['image'] ) ) {
            $this->importBrandImage( $term_id, (string) $row['image'] );
        }

        // Store secondary language name (for WPML/Polylang post-processing).
        $this->storeSecondaryLanguage( $term_id, $oc_id );

        // Save ID mapping.
        $this->saveManufacturerMap( $oc_id, $term_id );

        // Explicitly register this term with WPML in the primary language.
        // wpmlSwitchToPrimary() is called before the batch, but WPML's auto-
        // registration via wp_insert_term hooks is not always reliable for
        // custom taxonomies. This guarantees the icl_translations row exists
        // so the brand appears in the English WP admin "Brands" list.
        $this->registerBrandTermWithWpml( $term_id );

        $this->logger->info( "[manufacturers] Created brand term #{$term_id} ← OC #{$oc_id}: '{$name}' ({$this->taxonomy})" );

        /**
         * Fires after a brand term has been created from an OC manufacturer.
         *
         * @param int    $oc_id      OpenCart manufacturer_id.
         * @param int    $term_id    WordPress term_id.
         * @param string $taxonomy   The brand taxonomy used.
         * @param array  $row        Raw OC row.
         * @param array  $config     Full plugin config.
         */
        do_action( 'octowoo_after_migrate_manufacturer', $oc_id, $term_id, $this->taxonomy, $row, $this->config );

        return true;
    }

    private function updateBrandTerm( int $term_id, array $row ): bool {
        wp_update_term( $term_id, $this->taxonomy, [
            'name' => $this->sanitizeText( $row['name'] ?? '' ),
        ] );

        if ( ! empty( $row['image'] ) ) {
            $this->importBrandImage( $term_id, (string) $row['image'] );
        }

        // Re-register with WPML on every update. Existing brand terms created
        // before v2.4.52 may lack an icl_translations row (causing "No Brands
        // Found" in the English admin). This is idempotent – WPML updates the
        // row if it already exists, creates it if missing.
        $this->registerBrandTermWithWpml( $term_id );

        $this->logger->debug( "[manufacturers] Updated brand term #{$term_id}." );
        return true;
    }

    /**
     * Ensure a brand term is registered with WPML in the primary language.
     *
     * Without an icl_translations row (element_type = 'tax_{taxonomy}',
     * language_code = primary locale), WPML hides the term from the English
     * admin "Brands" list even though it exists in wp_terms.
     */
    private function registerBrandTermWithWpml( int $term_id ): void {
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            return;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $tt_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s",
                $term_id,
                $this->taxonomy
            )
        );

        if ( $tt_id > 0 ) {
            do_action( 'wpml_set_element_language_details', [
                'element_id'           => $tt_id,
                'element_type'         => 'tax_' . $this->taxonomy,
                'trid'                 => null,
                'language_code'        => $this->primaryLocale(),
                'source_language_code' => null,
            ] );
        }
    }

    // ── Product assignment ────────────────────────────────────────────────────

    /**
     * Walk every OC product's manufacturer_id and assign the brand term to the WC product.
     */
    private function assignManufacturersToProducts(): void {
        $pfx = $this->pfx();

        $rows = $this->oc->fetchAll(
            "SELECT product_id, manufacturer_id FROM `{$pfx}product`
             WHERE manufacturer_id > 0 ORDER BY product_id ASC",
            []
        );

        $assigned = 0;
        foreach ( $rows as $row ) {
            $oc_product_id      = (int) $row['product_id'];
            $oc_manufacturer_id = (int) $row['manufacturer_id'];

            $wc_product_id = $this->checkpoint->getWcId( 'product', $oc_product_id );
            $wc_term_id    = $this->checkpoint->getWcId( self::MAP_KEY, $oc_manufacturer_id )
                ?? $this->checkpoint->getWcId( self::KEY, $oc_manufacturer_id );

            if ( ! $wc_product_id || ! $wc_term_id ) {
                continue;
            }

            if ( $this->isDry() ) {
                continue;
            }

            wp_set_object_terms( (int) $wc_product_id, [ (int) $wc_term_id ], $this->taxonomy, false );
            $assigned++;
        }

        if ( $assigned > 0 ) {
            $this->logger->info( "[manufacturers] Assigned brand terms to {$assigned} product(s)." );
        }
    }

    private function saveManufacturerMap( int $oc_id, int $term_id ): void {
        $this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $term_id );

        // Backward compatibility with older runs that used the plural key.
        if ( self::MAP_KEY !== self::KEY ) {
            $this->checkpoint->saveIdMap( self::KEY, $oc_id, $term_id );
        }
    }

    // ── Brand image ───────────────────────────────────────────────────────────

    /**
     * Copy the manufacturer's logo image into the WP media library and
     * attach it to the brand term using the convention expected by each plugin.
     *
     * Uses direct file copy (same approach as ImageMigrator) instead of
     * download_url() to avoid HTTP loopback hangs / timeouts that block
     * the chunked AJAX migration.
     */
    private function importBrandImage( int $term_id, string $oc_image_rel_path ): void {
        if ( ! $this->shouldImportImages() ) {
            return;
        }

        $image_path = $this->config['opencart']['image_path'] ?? '';
        if ( ! $image_path ) {
            return;
        }

        $abs_path = trailingslashit( $image_path ) . ltrim( $oc_image_rel_path, '/' );
        if ( ! is_file( $abs_path ) ) {
            return;
        }

        // Sideload via WordPress media API.
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Copy to a temp file so WP can sideload it safely (no HTTP roundtrip).
        $tmp = wp_tempnam( basename( $oc_image_rel_path ) );
        if ( ! @copy( $abs_path, $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $this->logger->warning( "[manufacturers] Could not copy brand image: {$oc_image_rel_path}" );
            return;
        }

        $file_array = [
            'name'     => basename( $oc_image_rel_path ),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, 0 );

        // Clean up temp file if sideload didn't consume it.
        if ( file_exists( $tmp ) ) {
            @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        if ( is_wp_error( $attachment_id ) ) {
            $this->logger->warning( "[manufacturers] media_handle_sideload failed for brand image: " . $attachment_id->get_error_message() );
            return;
        }

        // thumbnail_id is recognised by WooCommerce Brands, YITH, and most others.
        update_term_meta( $term_id, 'thumbnail_id', $attachment_id );

        // Plugin-specific meta keys.
        update_term_meta( $term_id, 'pwb_brand_image',  $attachment_id ); // Perfect WooCommerce Brands
        update_term_meta( $term_id, 'brand_image',       $attachment_id ); // BeRocket
        update_term_meta( $term_id, 'image_id',          $attachment_id ); // some themes

        $this->logger->debug( "[manufacturers] Imported brand image as attachment #{$attachment_id} for term #{$term_id}" );
    }

    // ── Secondary language storage ────────────────────────────────────────────

    private function storeSecondaryLanguage( int $term_id, int $oc_id ): void {
        // OpenCart doesn't have per-language manufacturer names in the standard schema.
        // If the installation added them via a custom table or description table, override this method.
        // Default: no secondary name available.
    }

    // ── SEO slug lookup ───────────────────────────────────────────────────────

    private function fetchSeoSlug( int $oc_id ): string {
        $pfx = $this->pfx();
        try {
            $row = $this->oc->fetchRow(
                "SELECT keyword FROM `{$pfx}seo_url`
                 WHERE query = ? AND store_id = 0 LIMIT 1",
                [ "manufacturer_id={$oc_id}" ]
            );
            return $row ? $this->toSlug( $row['keyword'] ) : '';
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    // ── Taxonomy resolution ───────────────────────────────────────────────────

    /**
     * Detect which brand taxonomy is active on the site.
     * Returns the first registered candidate, or creates a fallback.
     */
    private function resolveTaxonomy(): string {
        foreach ( self::BRAND_TAXONOMY_CANDIDATES as $tax ) {
            if ( taxonomy_exists( $tax ) ) {
                return $tax;
            }
        }

        // None found – register our own fallback taxonomy so the migration can proceed.
        return $this->registerFallbackTaxonomy();
    }

    /**
     * Register a minimal "product_manufacturer" taxonomy as a fallback when no
     * brand plugin is active.  The taxonomy mirrors WooCommerce Brands' settings
     * so it works out-of-the-box with most themes.
     */
    private function registerFallbackTaxonomy(): string {
        $taxonomy = 'product_manufacturer';

        if ( taxonomy_exists( $taxonomy ) ) {
            return $taxonomy;
        }

        register_taxonomy( $taxonomy, 'product', [
            'hierarchical'      => false,
            'labels'            => [
                'name'                       => __( 'Brands', 'octowoo' ),
                'singular_name'              => __( 'Brand', 'octowoo' ),
                'menu_name'                  => __( 'Brands', 'octowoo' ),
                'all_items'                  => __( 'All Brands', 'octowoo' ),
                'edit_item'                  => __( 'Edit Brand', 'octowoo' ),
                'view_item'                  => __( 'View Brand', 'octowoo' ),
                'update_item'                => __( 'Update Brand', 'octowoo' ),
                'add_new_item'               => __( 'Add New Brand', 'octowoo' ),
                'new_item_name'              => __( 'New Brand Name', 'octowoo' ),
                'search_items'               => __( 'Search Brands', 'octowoo' ),
                'not_found'                  => __( 'No brands found.', 'octowoo' ),
            ],
            'show_ui'           => true,
            'show_in_nav_menus' => true,
            'show_tagcloud'     => false,
            'show_in_rest'      => true,
            'rewrite'           => [ 'slug' => 'brand', 'with_front' => false ],
            'show_admin_column' => true,
            'query_var'         => true,
        ] );

        $this->logger->info( '[manufacturers] No brand plugin detected – created fallback taxonomy "product_manufacturer".' );

        return $taxonomy;
    }
}
