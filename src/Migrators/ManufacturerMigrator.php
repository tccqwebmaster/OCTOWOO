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

    /**
     * Candidate brand taxonomies (in priority order).
     * First one that is registered on the site will be used.
     */
    private const BRAND_TAXONOMY_CANDIDATES = [
        'product_brand',         // WooCommerce Brands (official) · Ultimate WooCommerce Brands
        'pwb-brand',             // Perfect WooCommerce Brands
        'yith_product_brand',    // YITH WooCommerce Brands
        'berocket_brand',        // Brands for WooCommerce by BeRocket
        'brand',                 // Generic / theme-based
    ];

    /** Resolved brand taxonomy slug, set once in migrate(). */
    private string $taxonomy = '';

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        $resume_id = $this->checkpoint->getLastId( self::KEY );

        if ( $resume_id === PHP_INT_MAX ) {
            $this->logger->info( '[manufacturers] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
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
                 ORDER BY sort_order ASC, manufacturer_id ASC",
                [],
                $limit,
                $offset
            );
        };

        $item_callback = function ( array $row ): bool {
            return $this->processManufacturer( $row );
        };

        $result = $this->batch->run(
            total_callback:  $total_callback,
            batch_callback:  $batch_callback,
            item_callback:   $item_callback,
            migrator:        self::KEY,
            checkpoint:      $this->checkpoint,
            resume_after_id: $resume_id,
            id_field:        'manufacturer_id'
        );

        // Phase 2: assign brand terms to products.
        // In chunk mode this is called after every batch — defer until all terms
        // have been created (is_done = true) so we don't scan 4,000+ products on
        // every single 20-item chunk and cause PHP timeouts.
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
        $existing_wc_id = $this->checkpoint->getWcId( self::KEY, $oc_id );
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
            $this->checkpoint->saveIdMap( self::KEY, $oc_id, $term_id );
            $this->logger->debug( "[manufacturers] OC #{$oc_id} matched existing brand term #{$term_id}: '{$name}'" );
            return false; // Counted as skipped.
        }

        if ( is_wp_error( $result ) ) {
            $this->logger->error( "[manufacturers] Failed to create term '{$name}': " . $result->get_error_message() );
            return false;
        }

        $term_id      = (int) $result['term_id'];
        $term_tax_id  = (int) $result['term_taxonomy_id'];

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
        $this->checkpoint->saveIdMap( self::KEY, $oc_id, $term_id );

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

        $this->logger->debug( "[manufacturers] Updated brand term #{$term_id}." );
        return true;
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
            $wc_term_id    = $this->checkpoint->getWcId( self::KEY, $oc_manufacturer_id );

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

    // ── Brand image ───────────────────────────────────────────────────────────

    /**
     * Sideload (or re-use from ID map) the manufacturer's logo image and
     * attach it to the brand term using the convention expected by each plugin.
     */
    private function importBrandImage( int $term_id, string $oc_image_rel_path ): void {
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

        $tmp = download_url( $this->localPathToUrl( $abs_path ) );
        if ( is_wp_error( $tmp ) ) {
            // Fallback: copy file directly.
            $tmp = wp_tempnam();
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @copy( $abs_path, $tmp );
        }

        if ( ! is_file( $tmp ) ) {
            return;
        }

        $file_array = [
            'name'     => basename( $oc_image_rel_path ),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, 0 );
        if ( is_wp_error( $attachment_id ) ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @unlink( $tmp );
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

    /**
     * Convert an absolute server path to a local URL (used only when download_url isn't available).
     */
    private function localPathToUrl( string $abs_path ): string {
        $upload_dir = wp_upload_dir();
        // Try to map via uploads base.
        if ( strpos( $abs_path, $upload_dir['basedir'] ) === 0 ) {
            return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $abs_path );
        }
        // Use first candidate URL from OC shop URL.
        $shop_url = $this->config['opencart']['shop_url'] ?? '';
        if ( $shop_url ) {
            $oc_image_path = $this->config['opencart']['image_path'] ?? '';
            if ( $oc_image_path ) {
                $rel = str_replace( $oc_image_path, '', $abs_path );
                return rtrim( $shop_url, '/' ) . '/image/' . ltrim( $rel, '/' );
            }
        }
        return '';
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
