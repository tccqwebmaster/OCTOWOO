<?php
/**
 * Product migrator.
 *
 * Handles both simple and variable products, including:
 *  - Basic fields: name, description (Arabic + English), SKU, price, special price.
 *  - Stock: quantity and status.
 *  - Category assignment.
 *  - Featured image + gallery.
 *  - Variable products: OpenCart options → WooCommerce attributes + variations.
 *
 * OpenCart tables read:
 *   oc_product, oc_product_description, oc_product_to_category,
 *   oc_product_image, oc_product_option, oc_product_option_value,
 *   oc_option, oc_option_description, oc_option_value, oc_option_value_description,
 *   oc_product_special
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class ProductMigrator extends AbstractMigrator {

    /** Checkpoint key (matches MigrationManager order/config key). */
    private const KEY = 'products';

    /** ID-map entity key used by other migrators (orders, coupons, etc.). */
    private const MAP_KEY = 'product';

    /** @var ImageMigrator Shared image importer instance. */
    private ImageMigrator $imageMigrator;

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        $this->imageMigrator = new ImageMigrator(
            $this->oc, $this->logger, $this->checkpoint, $this->batch, $this->config
        );

        $pfx = $this->pfx();

        $resume_id = $this->checkpoint->getLastId( self::KEY );
        if ( $resume_id === PHP_INT_MAX ) {
            $this->logger->info( '[products] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        // Pre-fetch supporting data to avoid N+1 queries.
        $descriptions  = $this->fetchProductDescriptions();
        $categories    = $this->fetchProductCategories();
        $extra_images  = $this->fetchProductImages();
        $options       = $this->fetchProductOptions();
        $specials       = $this->fetchProductSpecials();

        $total_callback = fn() => $this->oc->count( 'product', 'status = 1' );

        $batch_callback = fn( int $offset, int $limit ) => $this->oc->fetchBatch(
            "SELECT product_id, model, sku, quantity, stock_status_id, image,
                    price, subtract, sort_order, status, date_added, date_modified,
                    tax_class_id, weight, weight_class_id, length, width, height, minimum
             FROM `{$pfx}product`
             WHERE status = 1
             ORDER BY product_id ASC",
            [],
            $limit,
            $offset
        );

        $item_callback = function ( array $row ) use (
            $descriptions, $categories, $extra_images, $options, $specials
        ): bool {
            return $this->processProduct( $row, $descriptions, $categories, $extra_images, $options, $specials );
        };

        // Performance: defer term counting and object-cache invalidation so WP
        // doesn't hammer the DB flushing counts and caches after every single insert.
        wp_defer_term_counting( true );
        wp_suspend_cache_invalidation( true );

        // If WPML is active, switch to the configured primary language so that
        // every wp_insert_post() call during this batch is auto-assigned to the
        // correct primary language by WPML (not the admin's browsing language).
        $this->wpmlSwitchToPrimary();

        $result = $this->batch->run(
            total_callback:  $total_callback,
            batch_callback:  $batch_callback,
            item_callback:   $item_callback,
            migrator:        self::KEY,
            checkpoint:      $this->checkpoint,
            resume_after_id: $resume_id,
            id_field:        'product_id'
        );

        $this->wpmlRestoreLanguage();

        // Re-enable term counting (this triggers a single deferred recount) and
        // flush the object cache once for the entire batch.
        wp_defer_term_counting( false );
        wp_suspend_cache_invalidation( false );
        wp_cache_flush();

        return $result;
    }

    // ── Per-product processing ────────────────────────────────────────────────

    private function processProduct(
        array $row,
        array $descriptions,
        array $categories,
        array $extra_images,
        array $options,
        array $specials
    ): bool {
        $oc_id       = (int) $row['product_id'];
        $lang_id     = $this->langId();
        $lang_id_sec = $this->langIdSecondary();

        // Primary language description.
        $desc = $descriptions[ $oc_id ][ $lang_id ]
             ?? $descriptions[ $oc_id ][ array_key_first( $descriptions[ $oc_id ] ?? [] ) ]
             ?? null;

        if ( ! $desc ) {
            $this->logger->warning( "[products] No description for OC #{$oc_id} – skipping." );
            return false;
        }

        $name        = $this->sanitizeName( $desc['name'] ?? '' );
        $description = $this->cleanDescription( $desc['description'] ?? '' );
        $short_desc  = $this->sanitizeText( $desc['tag'] ?? '' );

        // Secondary language (Arabic) fields via Polylang / WPML meta or just stored as meta.
        $name_ar      = '';
        $desc_ar      = '';
        $short_ar     = '';
        $metatitle_ar = '';
        $metadesc_ar  = '';
        $metakw_ar    = '';
        $sec = null;
        if ( $lang_id_sec > 0 && isset( $descriptions[ $oc_id ][ $lang_id_sec ] ) ) {
            $sec = $descriptions[ $oc_id ][ $lang_id_sec ];
        } elseif ( ! empty( $descriptions[ $oc_id ] ) ) {
            // Fallback: when configured secondary language ID is wrong/missing,
            // use the first non-primary language row so WPML can still create translations.
            foreach ( $descriptions[ $oc_id ] as $candidate_lang_id => $candidate_desc ) {
                if ( (int) $candidate_lang_id !== $lang_id ) {
                    $sec = $candidate_desc;
                    $this->logger->warning( "[products] Secondary language ID {$lang_id_sec} not found for OC #{$oc_id}; using language_id={$candidate_lang_id} as fallback." );
                    break;
                }
            }
        }

        if ( is_array( $sec ) ) {
            $name_ar      = $this->sanitizeName( $sec['name']             ?? '' );
            $desc_ar      = $this->cleanDescription( $sec['description']  ?? '' );
            $short_ar     = $this->sanitizeText( $sec['tag']              ?? '' );
            $metatitle_ar = $this->sanitizeText( $sec['meta_title']       ?? '' );
            $metadesc_ar  = $this->sanitizeText( $sec['meta_description'] ?? '' );
            $metakw_ar    = $this->sanitizeText( $sec['meta_keyword']     ?? '' );
        }

        if ( $name === '' ) {
            $this->logger->warning( "[products] Empty product name for OC #{$oc_id}." );
            return false;
        }

        // Duplicate check: first by OC ID mapping (fast), then by SKU
        // (catches products that exist in WC but weren't tagged by OctoWoo).
        $existing_wc_id = $this->checkpoint->getWcId( self::MAP_KEY, $oc_id );

        if ( ! $existing_wc_id ) {
            $sku = sanitize_text_field( $row['sku'] ?: $row['model'] );
            if ( $sku !== '' && function_exists( 'wc_get_product_id_by_sku' ) ) {
                $by_sku = (int) wc_get_product_id_by_sku( $sku );
                if ( $by_sku > 0 ) {
                    $existing_wc_id = $by_sku;
                    // Cache so future runs use the fast OC-ID path.
                    $this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $by_sku );
                    $this->logger->debug( "[products] SKU match '{$sku}' OC #{$oc_id} → WC #{$by_sku}." );
                }
            }
        }

        if ( $existing_wc_id ) {
            if ( $this->onDuplicate() === 'update' ) {
                return $this->updateProduct( $existing_wc_id, $row, $desc, $categories[ $oc_id ] ?? [], $extra_images[ $oc_id ] ?? [], $options[ $oc_id ] ?? [], $specials[ $oc_id ] ?? [], $name_ar, $desc_ar, $metatitle_ar, $metadesc_ar, $short_ar, $metakw_ar );
            }
            $this->logger->debug( "[products] Duplicate OC #{$oc_id} → WC #{$existing_wc_id} – skipping." );
            return false;
        }

        if ( $this->isDry() ) {
            $this->logger->debug( "[DRY-RUN] Would create product: {$name} (OC #{$oc_id})" );
            return true;
        }

        return $this->createProduct( $row, $desc, $name, $description, $short_desc, $categories[ $oc_id ] ?? [], $extra_images[ $oc_id ] ?? [], $options[ $oc_id ] ?? [], $specials[ $oc_id ] ?? [], $name_ar, $desc_ar, $metatitle_ar, $metadesc_ar, $short_ar, $metakw_ar );
    }

    // ── Create product ────────────────────────────────────────────────────────

    private function createProduct(
        array  $row,
        array  $desc,
        string $name,
        string $description,
        string $short_desc,
        array  $oc_categories,
        array  $oc_images,
        array  $oc_options,
        array  $oc_specials,
        string $name_ar      = '',
        string $desc_ar      = '',
        string $metatitle_ar = '',
        string $metadesc_ar  = '',
        string $short_ar     = '',
        string $metakw_ar    = ''
    ): bool {
        global $wpdb;

        $oc_id     = (int) $row['product_id'];
        $has_vars  = $this->hasVariableOptions( $oc_options );

        // Create the WP post first so we have an ID for meta, images, etc.
        $product_type = $has_vars ? 'variable' : 'simple';

        // ── Per-record transaction ────────────────────────────────────────────
        // If any insert / meta write fails, the whole product (post + meta +
        // terms + variations) is rolled back so we never have partial data.
        $wpdb->query( 'START TRANSACTION' );

        try {
            $result = $this->doCreateProduct(
                $wpdb, $oc_id, $product_type, $has_vars,
                $row, $desc, $name, $description, $short_desc,
                $oc_categories, $oc_images, $oc_options, $oc_specials,
                $name_ar, $desc_ar, $metatitle_ar, $metadesc_ar, $short_ar, $metakw_ar
            );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            $this->logger->error( "[products] Transaction rolled back for OC #{$oc_id}: " . $e->getMessage() );
            return false;
        }

        return $result;
    }

    /**
     * Internal helper — runs inside the transaction opened by createProduct().
     */
    private function doCreateProduct(
        \wpdb  $wpdb,
        int    $oc_id,
        string $product_type,
        bool   $has_vars,
        array  $row,
        array  $desc,
        string $name,
        string $description,
        string $short_desc,
        array  $oc_categories,
        array  $oc_images,
        array  $oc_options,
        array  $oc_specials,
        string $name_ar      = '',
        string $desc_ar      = '',
        string $metatitle_ar = '',
        string $metadesc_ar  = '',
        string $short_ar     = '',
        string $metakw_ar    = ''
    ): bool {

        $post_id = wp_insert_post( [
            'post_title'   => $name,
            'post_content' => wp_kses_post( $description ),
            'post_excerpt' => wp_kses_post( $short_desc ),
            'post_status'  => 'publish',
            'post_type'    => 'product',
            'post_date'    => $row['date_added'] ?? current_time( 'mysql' ),
        ], true );

        if ( is_wp_error( $post_id ) ) {
            $this->logger->error( "[products] wp_insert_post failed for OC #{$oc_id}: " . $post_id->get_error_message() );
            return false;
        }

        // Set product type term.
        wp_set_object_terms( $post_id, $product_type, 'product_type' );

        // Core meta.
        $price = (float) $row['price'];
        update_post_meta( $post_id, '_sku',           sanitize_text_field( $row['sku'] ?: $row['model'] ) );
        update_post_meta( $post_id, '_regular_price', $price );
        update_post_meta( $post_id, '_price',         $price );

        // Sale/special price.
        if ( ! empty( $oc_specials ) ) {
            $special = $this->getBestSpecialPrice( $oc_specials );
            if ( $special !== null ) {
                update_post_meta( $post_id, '_sale_price', $special );
                update_post_meta( $post_id, '_price',      $special );
            }
        }

        // Stock.
        $qty    = (int) $row['quantity'];
        $status = $this->mapStockStatus( (int) $row['stock_status_id'], $qty );

        update_post_meta( $post_id, '_manage_stock',   'yes' );
        update_post_meta( $post_id, '_stock',          $qty );
        update_post_meta( $post_id, '_stock_status',   $status );
        update_post_meta( $post_id, '_backorders',     'no' );

        // Additional WC meta.
        update_post_meta( $post_id, '_weight', $row['weight'] ?? '' );
        update_post_meta( $post_id, '_length', $row['length'] ?? '' );
        update_post_meta( $post_id, '_width',  $row['width'] ?? '' );
        update_post_meta( $post_id, '_height', $row['height'] ?? '' );

        // Tax class (requires TaxMigrator to have run first to build the map).
        $this->applyTaxClass( $post_id, (int) ( $row['tax_class_id'] ?? 0 ) );

        // Source reference.
        update_post_meta( $post_id, '_octowoo_oc_id', $oc_id );

        // Arabic / secondary-language meta (for WPML / Polylang translation pass).
        if ( $name_ar ) {
            update_post_meta( $post_id, '_octowoo_name_ar', $name_ar );
        }
        if ( $desc_ar ) {
            update_post_meta( $post_id, '_octowoo_description_ar', $desc_ar );
        }
        if ( $short_ar ) {
            update_post_meta( $post_id, '_octowoo_short_description_ar', $short_ar );
        }
        if ( $metatitle_ar ) {
            update_post_meta( $post_id, '_octowoo_metatitle_ar', $metatitle_ar );
        }
        if ( $metadesc_ar ) {
            update_post_meta( $post_id, '_octowoo_metadesc_ar', $metadesc_ar );
        }
        if ( $metakw_ar ) {
            update_post_meta( $post_id, '_octowoo_metakw_ar', $metakw_ar );
        }
        // SEO meta fields.
        if ( ! empty( $desc['meta_title'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_title', $this->sanitizeText( $desc['meta_title'] ) );
        }
        if ( ! empty( $desc['meta_description'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', $this->sanitizeText( $desc['meta_description'] ) );
        }
        if ( ! empty( $desc['meta_keyword'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_focuskw', $this->sanitizeText( $desc['meta_keyword'] ) );
        }
        $this->assignCategories( $post_id, $oc_categories );

        // Featured image + gallery.
        $this->assignImages( $post_id, $row['image'] ?? '', $oc_images );

        // Attributes + variations — guard against combination explosion.
        if ( $has_vars ) {
            $combination_count = $this->countVariationCombinations( $oc_options );
            if ( $combination_count > 50 ) {
                $this->logger->warning(
                    "[products] OC #{$oc_id} would create {$combination_count} variations (WC limit=50). " .
                    "Converting to simple product with informational attributes."
                );
                update_post_meta( $post_id, '_octowoo_skipped_variations', $combination_count );
                // Switch the post type to simple so WC doesn't expect variation children.
                wp_set_object_terms( $post_id, 'simple', 'product_type' );
                $this->createSimpleAttributes( $post_id, $oc_options );
            } else {
                $this->createVariations( $post_id, $oc_id, $oc_options, $price );
            }
        } else {
            $this->createSimpleAttributes( $post_id, $oc_options );
        }

        // Transient cleanup is deferred to end of batch (migrate() calls wp_cache_flush()).
        // wc_delete_product_transients( $post_id ) intentionally omitted here for performance.

        $this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $post_id );

        $wpdb->query( 'COMMIT' );

        $this->logger->info( "[products] Created WC product #{$post_id} ({$product_type}) from OC #{$oc_id}: \"{$name}\"" );

        return true;
    }

    // ── Update product ────────────────────────────────────────────────────────

    private function updateProduct(
        int    $wc_post_id,
        array  $row,
        array  $desc,
        array  $oc_categories,
        array  $oc_images,
        array  $oc_options,
        array  $oc_specials,
        string $name_ar      = '',
        string $desc_ar      = '',
        string $metatitle_ar = '',
        string $metadesc_ar  = '',
        string $short_ar     = '',
        string $metakw_ar    = ''
    ): bool {
        $oc_id = (int) $row['product_id'];

        wp_update_post( [
            'ID'           => $wc_post_id,
            'post_title'   => $this->sanitizeName( $desc['name'] ?? '' ),
            'post_content' => wp_kses_post( $this->cleanDescription( $desc['description'] ?? '' ) ),
            'post_excerpt' => wp_kses_post( $this->sanitizeText( $desc['tag'] ?? '' ) ),
        ] );

        $price = (float) $row['price'];
        update_post_meta( $wc_post_id, '_regular_price', $price );
        update_post_meta( $wc_post_id, '_price',         $price );

        $qty = (int) $row['quantity'];
        update_post_meta( $wc_post_id, '_stock',        $qty );
        update_post_meta( $wc_post_id, '_stock_status', $this->mapStockStatus( (int) $row['stock_status_id'], $qty ) );

        // Sync tax class.
        $this->applyTaxClass( $wc_post_id, (int) ( $row['tax_class_id'] ?? 0 ) );

        // Arabic / secondary-language meta (keep in sync on update).
        if ( $name_ar ) {
            update_post_meta( $wc_post_id, '_octowoo_name_ar', $name_ar );
        }
        if ( $desc_ar ) {
            update_post_meta( $wc_post_id, '_octowoo_description_ar', $desc_ar );
        }
        if ( $short_ar ) {
            update_post_meta( $wc_post_id, '_octowoo_short_description_ar', $short_ar );
        }
        if ( $metatitle_ar ) {
            update_post_meta( $wc_post_id, '_octowoo_metatitle_ar', $metatitle_ar );
        }
        if ( $metadesc_ar ) {
            update_post_meta( $wc_post_id, '_octowoo_metadesc_ar', $metadesc_ar );
        }
        if ( $metakw_ar ) {
            update_post_meta( $wc_post_id, '_octowoo_metakw_ar', $metakw_ar );
        }

        // English SEO meta (keep in sync on update).
        if ( ! empty( $desc['meta_title'] ) ) {
            update_post_meta( $wc_post_id, '_yoast_wpseo_title',   $this->sanitizeText( $desc['meta_title'] ) );
        }
        if ( ! empty( $desc['meta_description'] ) ) {
            update_post_meta( $wc_post_id, '_yoast_wpseo_metadesc', $this->sanitizeText( $desc['meta_description'] ) );
        }
        if ( ! empty( $desc['meta_keyword'] ) ) {
            update_post_meta( $wc_post_id, '_yoast_wpseo_focuskw',  $this->sanitizeText( $desc['meta_keyword'] ) );
        }

        wc_delete_product_transients( $wc_post_id );

        $this->logger->info( "[products] Updated WC product #{$wc_post_id} (OC #{$oc_id})." );
        return true;
    }

    // ── Attributes + Variations ───────────────────────────────────────────────

    /**
     * Returns true when the provided options array contains "selectable" options
     * that create product variations (type: select, radio).
     */
    private function hasVariableOptions( array $options ): bool {
        foreach ( $options as $opt ) {
            if ( in_array( $opt['type'], [ 'select', 'radio' ], true ) && ! empty( $opt['values'] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Count the total number of variation combinations for a product's options.
     * Returns the Cartesian product of all selectable option value counts.
     */
    private function countVariationCombinations( array $options ): int {
        $total = 1;
        foreach ( $options as $opt ) {
            if ( in_array( $opt['type'], [ 'select', 'radio' ], true ) && ! empty( $opt['values'] ) ) {
                $total *= count( $opt['values'] );
            }
        }
        return $total;
    }

    /**
     * Create WooCommerce attributes and variation posts from OC product options.
     */
    private function createVariations( int $post_id, int $oc_id, array $oc_options, float $base_price ): void {
        $attributes     = [];
        $variation_data = []; // Will hold array of [attr_slug => [term_slugs]] per variation.

        foreach ( $oc_options as $opt ) {
            if ( ! in_array( $opt['type'], [ 'select', 'radio' ], true ) ) {
                continue;
            }

            $attr_name = sanitize_text_field( $opt['name'] );
            $attr_slug = wc_sanitize_taxonomy_name( $opt['name'] );

            // Check/create the WC global attribute.
            $attr_id       = $this->ensureProductAttribute( $attr_name, $attr_slug );
            $taxonomy_name = wc_attribute_taxonomy_name( $attr_slug );

            $term_slugs = [];
            foreach ( $opt['values'] as $val ) {
                $val_name = sanitize_text_field( $val['name'] );
                $val_slug = sanitize_title( $val_name );

                if ( ! term_exists( $val_name, $taxonomy_name ) ) {
                    wp_insert_term( $val_name, $taxonomy_name );
                }

                // Assign the term to the product post.
                wp_set_object_terms( $post_id, $val_name, $taxonomy_name, true );
                $term_slugs[] = $val_slug;
            }

            $attributes[ $taxonomy_name ] = [
                'name'         => $taxonomy_name,
                'value'        => '',
                'position'     => (int) ( $opt['sort_order'] ?? 0 ),
                'is_visible'   => 1,
                'is_variation' => 1,
                'is_taxonomy'  => 1,
            ];

            $variation_data[] = [
                'taxonomy' => $taxonomy_name,
                'values'   => $opt['values'],
            ];
        }

        if ( ! empty( $attributes ) ) {
            update_post_meta( $post_id, '_product_attributes', $attributes );
        }

        // Generate variation combinations.
        $this->generateVariationPosts( $post_id, $variation_data, $base_price );
    }

    /**
     * For non-variable products, store options as informational (non-variable) attributes.
     */
    private function createSimpleAttributes( int $post_id, array $oc_options ): void {
        $attributes = [];

        foreach ( $oc_options as $opt ) {
            if ( empty( $opt['values'] ) ) {
                continue;
            }

            $value_names = array_map( fn( $v ) => $v['name'], $opt['values'] );

            $attributes[ sanitize_key( $opt['name'] ) ] = [
                'name'         => sanitize_text_field( $opt['name'] ),
                'value'        => implode( ' | ', $value_names ),
                'position'     => 0,
                'is_visible'   => 1,
                'is_variation' => 0,
                'is_taxonomy'  => 0,
            ];
        }

        if ( ! empty( $attributes ) ) {
            update_post_meta( $post_id, '_product_attributes', $attributes );
        }
    }

    /**
     * Generate WooCommerce variation posts.
     * Each OC option value that has its own price adjustment creates one variation.
     */
    private function generateVariationPosts(
        int   $post_id,
        array $variation_data,
        float $base_price
    ): void {
        // Build flat list of all combinations (Cartesian product).
        $combinations = [ [] ];
        foreach ( $variation_data as $attr ) {
            $new_combinations = [];
            foreach ( $combinations as $combo ) {
                foreach ( $attr['values'] as $val ) {
                    $new_combinations[] = array_merge( $combo, [
                        [
                            'taxonomy'    => $attr['taxonomy'],
                            'term_name'   => $val['name'],
                            'price_diff'  => $this->resolvePriceDiff( $val ),
                            'quantity'    => (int) ( $val['quantity'] ?? 0 ),
                            'sku_suffix'  => sanitize_key( $val['name'] ),
                        ],
                    ] );
                }
            }
            $combinations = $new_combinations;
        }

        foreach ( $combinations as $combo ) {
            $var_price = $base_price;
            $var_qty   = 0;
            $var_sku   = '';

            $meta_input = [];
            foreach ( $combo as $attr_val ) {
                $var_price += $attr_val['price_diff'];
                $var_qty   = max( $var_qty, $attr_val['quantity'] );
                $var_sku  .= ( $var_sku ? '-' : '' ) . $attr_val['sku_suffix'];
                $meta_input[ 'attribute_' . $attr_val['taxonomy'] ] = sanitize_title( $attr_val['term_name'] );
            }

            $var_price = max( 0, $var_price );

            $variation_id = wp_insert_post( [
                'post_parent' => $post_id,
                'post_type'   => 'product_variation',
                'post_status' => 'publish',
                'menu_order'  => 0,
            ], true );

            if ( is_wp_error( $variation_id ) ) {
                $this->logger->error( "[products] Failed to create variation for product #{$post_id}: " . $variation_id->get_error_message() );
                continue;
            }

            foreach ( $meta_input as $key => $value ) {
                update_post_meta( $variation_id, $key, $value );
            }

            update_post_meta( $variation_id, '_regular_price', $var_price );
            update_post_meta( $variation_id, '_price',         $var_price );
            update_post_meta( $variation_id, '_stock',         $var_qty );
            update_post_meta( $variation_id, '_stock_status',  $var_qty > 0 ? 'instock' : 'outofstock' );
            update_post_meta( $variation_id, '_manage_stock',  'yes' );
            update_post_meta( $variation_id, '_sku',           sanitize_text_field( $var_sku ) );
        }
    }

    /**
     * Calculate the absolute price adjustment for a variation value.
     */
    private function resolvePriceDiff( array $val ): float {
        $diff   = (float) ( $val['price'] ?? 0 );
        $prefix = $val['price_prefix'] ?? '+';
        if ( $prefix === '-' ) {
            $diff = -abs( $diff );
        }
        return $diff;
    }

    // ── Images ────────────────────────────────────────────────────────────────

    private function assignImages( int $post_id, string $featured_oc_path, array $oc_images ): void {
        // Featured image.
        if ( $featured_oc_path ) {
            $attachment_id = $this->imageMigrator->importByOcPath( $featured_oc_path );
            if ( $attachment_id && $attachment_id > 0 ) {
                set_post_thumbnail( $post_id, $attachment_id );
            }
        }

        // Gallery images.
        $gallery_ids = [];
        foreach ( $oc_images as $img ) {
            if ( empty( $img['image'] ) ) {
                continue;
            }
            $att_id = $this->imageMigrator->importByOcPath( $img['image'] );
            if ( $att_id && $att_id > 0 ) {
                $gallery_ids[] = $att_id;
            }
        }

        if ( ! empty( $gallery_ids ) ) {
            update_post_meta( $post_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
        }
    }

    // ── Category assignment ───────────────────────────────────────────────────

    private function assignCategories( int $post_id, array $oc_category_ids ): void {
        $wc_term_ids = [];

        foreach ( $oc_category_ids as $oc_cat_id ) {
            $wc_term_id = $this->checkpoint->getWcId( 'category', (int) $oc_cat_id );
            if ( $wc_term_id ) {
                $wc_term_ids[] = (int) $wc_term_id;
            }
        }

        if ( ! empty( $wc_term_ids ) ) {
            wp_set_object_terms( $post_id, $wc_term_ids, 'product_cat' );
        }
    }

    // ── Special price helper ──────────────────────────────────────────────────

    private function getBestSpecialPrice( array $specials ): ?float {
        $today = gmdate( 'Y-m-d' );
        $best  = null;

        foreach ( $specials as $s ) {
            $start = $s['date_start'] ?: '0000-00-00';
            $end   = $s['date_end']   ?: '9999-12-31';

            // Only consider currently active specials.
            if ( $start <= $today && $end >= $today ) {
                $p = (float) $s['price'];
                if ( $best === null || $p < $best ) {
                    $best = $p;
                }
            }
        }

        return $best;
    }

    // ── Data fetching helpers ─────────────────────────────────────────────────

    /** @return array<int, array<int, array<string,mixed>>> Keyed [product_id][lang_id]. */
    private function fetchProductDescriptions(): array {
        $pfx  = $this->pfx();
        $rows = $this->oc->fetchAll(
            "SELECT product_id, language_id, name, description, tag,
                    meta_title, meta_description, meta_keyword
             FROM `{$pfx}product_description`"
        );

        $indexed = [];
        foreach ( $rows as $row ) {
            $indexed[ (int) $row['product_id'] ][ (int) $row['language_id'] ] = $row;
        }
        return $indexed;
    }

    /** @return array<int, int[]> [product_id => [category_id, ...]] */
    private function fetchProductCategories(): array {
        $pfx  = $this->pfx();
        $rows = $this->oc->fetchAll( "SELECT product_id, category_id FROM `{$pfx}product_to_category`" );

        $indexed = [];
        foreach ( $rows as $row ) {
            $indexed[ (int) $row['product_id'] ][] = (int) $row['category_id'];
        }
        return $indexed;
    }

    /** @return array<int, array<int, array<string,mixed>>> [product_id => [images]] */
    private function fetchProductImages(): array {
        $pfx  = $this->pfx();
        $rows = $this->oc->fetchAll(
            "SELECT product_id, image, sort_order FROM `{$pfx}product_image` ORDER BY sort_order ASC"
        );

        $indexed = [];
        foreach ( $rows as $row ) {
            $indexed[ (int) $row['product_id'] ][] = $row;
        }
        return $indexed;
    }

    /**
     * Fetch all product options with their values.
     * Returns [product_id => [options with embedded values]].
     *
     * @return array<int, array<int, array<string,mixed>>>
     */
    private function fetchProductOptions(): array {
        $pfx     = $this->pfx();
        $lang_id = $this->langId();

        // Fetch option types and names.
        $option_names = [];
        $opt_rows     = $this->oc->fetchAll(
            "SELECT o.option_id, o.type, od.name
             FROM `{$pfx}option` o
             JOIN `{$pfx}option_description` od ON od.option_id = o.option_id AND od.language_id = ?",
            [ $lang_id ]
        );
        foreach ( $opt_rows as $r ) {
            $option_names[ (int) $r['option_id'] ] = $r;
        }

        // Fetch option value names.
        $value_names = [];
        $val_rows    = $this->oc->fetchAll(
            "SELECT ovd.option_value_id, ovd.name, ovd.option_id
             FROM `{$pfx}option_value_description` ovd
             WHERE ovd.language_id = ?",
            [ $lang_id ]
        );
        foreach ( $val_rows as $r ) {
            $value_names[ (int) $r['option_value_id'] ] = $r;
        }

        // Fetch product-option links.
        $po_rows = $this->oc->fetchAll(
            "SELECT po.product_option_id, po.product_id, po.option_id, po.required
             FROM `{$pfx}product_option` po"
        );

        // Fetch product-option-value links.
        $pov_rows = $this->oc->fetchAll(
            "SELECT pov.product_option_id, pov.option_value_id,
                    pov.product_id, pov.quantity, pov.price, pov.price_prefix,
                    pov.weight, pov.weight_prefix
             FROM `{$pfx}product_option_value` pov"
        );

        // Group values by product_option_id.
        $values_by_po = [];
        foreach ( $pov_rows as $pov ) {
            $po_id = (int) $pov['product_option_id'];
            $ov_id = (int) $pov['option_value_id'];
            $values_by_po[ $po_id ][] = array_merge( $pov, [
                'name' => $this->sanitizeText( $value_names[ $ov_id ]['name'] ?? 'Value #' . $ov_id ),
            ] );
        }

        // Assemble final structure.
        $indexed = [];
        foreach ( $po_rows as $po ) {
            $option_id = (int) $po['option_id'];
            $prod_id   = (int) $po['product_id'];
            $po_id     = (int) $po['product_option_id'];

            $option_meta = $option_names[ $option_id ] ?? null;
            if ( ! $option_meta ) {
                continue;
            }

            $indexed[ $prod_id ][] = [
                'product_option_id' => $po_id,
                'option_id'         => $option_id,
                'name'              => $this->sanitizeText( $option_meta['name'] ?? 'Option' ),
                'type'              => $option_meta['type'] ?? 'select',
                'required'          => (bool) $po['required'],
                'sort_order'        => 0,
                'values'            => $values_by_po[ $po_id ] ?? [],
            ];
        }

        return $indexed;
    }

    /** @return array<int, array<int, array<string,mixed>>> [product_id => [specials]] */
    private function fetchProductSpecials(): array {
        $pfx  = $this->pfx();
        $rows = $this->oc->fetchAll(
            "SELECT product_id, price, date_start, date_end, priority
             FROM `{$pfx}product_special`
             ORDER BY priority ASC"
        );

        $indexed = [];
        foreach ( $rows as $row ) {
            $indexed[ (int) $row['product_id'] ][] = $row;
        }
        return $indexed;
    }

    // ── Tax class helper ──────────────────────────────────────────────────────

    /**
     * Write _tax_class and _tax_status meta onto a WC product post.
     *
     * Reads the 'octowoo_tax_class_map' option built by TaxMigrator.
     * Falls back gracefully when TaxMigrator hasn't run yet.
     *
     * WooCommerce convention:
     *   _tax_class = ''          → Standard rate
     *   _tax_class = 'reduced-rate' etc. → named class
     *   _tax_status = 'taxable' | 'shipping' | 'none'
     */
    private function applyTaxClass( int $post_id, int $oc_tax_class_id ): void {
        if ( $oc_tax_class_id <= 0 ) {
            update_post_meta( $post_id, '_tax_status', 'none' );
            update_post_meta( $post_id, '_tax_class',  '' );
            return;
        }

        /** @var array<int,string> $map */
        $map       = (array) get_option( 'octowoo_tax_class_map', [] );
        $tax_class = $map[ $oc_tax_class_id ] ?? '';  // '' = WC standard rate.

        update_post_meta( $post_id, '_tax_status', 'taxable' );
        update_post_meta( $post_id, '_tax_class',  $tax_class );
    }
}
