<?php
/**
 * Product migrator.
 *
 * Handles both simple and variable products, including:
 *  - Basic fields: name, description (primary + secondary language), SKU, price, special price.
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

        $total_callback = fn() => $this->oc->count( 'product' );

        // v2.5.1: Added manufacturer_id, date_available, minimum, upc, ean, jan, isbn, mpn
        // to capture all meaningful OC fields that have WC equivalents.
        $oc_major = $this->ocMajor();
        // OC1 may not have upc/ean/jan/isbn/mpn columns — use COALESCE for safe compat.
        $extra_cols = ( $oc_major >= 2 )
            ? "upc, ean, jan, isbn, mpn, date_available"
            : "'' AS upc, '' AS ean, '' AS jan, '' AS isbn, '' AS mpn, date_added AS date_available";

        $batch_callback = fn( int $offset, int $limit ) => $this->oc->fetchBatch(
            "SELECT product_id, manufacturer_id, model, sku, quantity, stock_status_id, image,
                    price, subtract, sort_order, status, date_added, date_modified,
                    tax_class_id, weight, weight_class_id, length, width, height, minimum,
                    {$extra_cols}
             FROM `{$pfx}product`
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
            // Product exists in oc_product (status=1) but has NO description row
            // at all in oc_product_description.  This is a data-quality issue in
            // the source OpenCart database.  We skip the product and log clearly
            // so the operator can investigate and re-run after fixing the data.
            $this->logger->warning(
                "[products] Skipping OC #{$oc_id}: no description found in oc_product_description " .
                "(checked primary language_id={$lang_id} and all other languages). " .
                'Fix the source data and reset progress to migrate this product.'
            );
            return false;
        }

        $name        = $this->sanitizeName( $desc['name'] ?? '' );
        $description = $this->cleanDescription( $desc['description'] ?? '' );
        // OpenCart's `tag` field holds comma-separated SEO/search keywords, NOT a
        // short description. WooCommerce short description (post_excerpt) is left
        // empty because OpenCart has no equivalent field.
        $short_desc  = '';

        // Secondary language fields (language-agnostic — locale comes from config).
        $sec_name      = '';
        $sec_desc      = '';
        $sec_short     = '';
        $sec_metatitle = '';
        $sec_metadesc  = '';
        $sec_metakw    = '';
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

        $sec_tags = '';  // Secondary-language tag string (comma-separated).
        if ( is_array( $sec ) ) {
            $sec_name      = $this->sanitizeName( $sec['name']             ?? '' );
            $sec_desc      = $this->cleanDescription( $sec['description']  ?? '' );
            // OC tag field = comma-separated search/SEO keywords — store for secondary tag assignment.
            $sec_tags      = $this->sanitizeText( $sec['tag']              ?? '' );
            $sec_short     = '';
            $sec_metatitle = $this->sanitizeText( $sec['meta_title']       ?? '' );
            $sec_metadesc  = $this->sanitizeText( $sec['meta_description'] ?? '' );
            $sec_metakw    = $this->sanitizeText( $sec['meta_keyword']     ?? '' );
        }

        if ( $name === '' ) {
            $this->logger->warning(
                "[products] Skipping OC #{$oc_id}: product name is empty after sanitization " .
                "(raw name: '" . ( $desc['name'] ?? '' ) . "'). Fix the source data and reset progress."
            );
            return false;
        }

        // Duplicate check:
        //  1. id_map / _octowoo_oc_id meta  — fastest when the id_map is warm.
        //  2. Direct _sku postmeta query    — always reliable, even when WooCommerce's
        //     wc_product_meta_lookup table is stale (which happens when products were
        //     created via update_post_meta() without going through WC_Product::save()).
        //     wc_get_product_id_by_sku() queries that lookup table in WC 3.7+ and
        //     therefore returns 0 for products created by OctoWoo — causing duplicates.
        $existing_wc_id = $this->checkpoint->getWcId( self::MAP_KEY, $oc_id );

        if ( ! $existing_wc_id ) {
            $sku = sanitize_text_field( $row['sku'] ?: $row['model'] );
            if ( $sku !== '' ) {
                global $wpdb;
                // Query wp_postmeta directly so we never depend on wc_product_meta_lookup.
                $by_sku = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $wpdb->prepare(
                        "SELECT pm.post_id
                         FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = '_sku'
                           AND pm.meta_value = %s
                           AND p.post_type IN ('product','product_variation')
                           AND p.post_status != 'trash'
                         LIMIT 1",
                        $sku
                    )
                );
                if ( $by_sku > 0 ) {
                    $existing_wc_id = $by_sku;
                    // Cache in id_map so future requests use the fast path.
                    $this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $by_sku );
                    $this->logger->info( "[products] SKU match '{$sku}' OC #{$oc_id} → WC #{$by_sku} (skipping duplicate)." );
                }
            }
        }

        if ( $existing_wc_id ) {
            if ( $this->onDuplicate() === 'update' ) {
                return $this->updateProduct( $existing_wc_id, $row, $desc, $categories[ $oc_id ] ?? [], $extra_images[ $oc_id ] ?? [], $options[ $oc_id ] ?? [], $specials[ $oc_id ] ?? [], $sec_name, $sec_desc, $sec_metatitle, $sec_metadesc, $sec_short, $sec_metakw, $sec_tags );
            }
            // Even in skip mode: repair missing featured image without touching anything else.
            // This is a lightweight fix — no post update, no option changes, just the thumbnail.
            if ( ! get_post_thumbnail_id( $existing_wc_id ) ) {
                $this->imageMigrator = $this->imageMigrator ?? new ImageMigrator(
                    $this->oc, $this->logger, $this->checkpoint, $this->batch, $this->config
                );
                $this->assignImages( $existing_wc_id, $row['image'] ?? '', $extra_images[ $oc_id ] ?? [] );
                $this->logger->debug( "[products] Repaired missing featured image for WC #{$existing_wc_id} (OC #{$oc_id})." );
            }
            $this->logger->debug( "[products] Duplicate OC #{$oc_id} → WC #{$existing_wc_id} – skipping." );
            return false;
        }

        if ( $this->isDry() ) {
            $this->logger->debug( "[DRY-RUN] Would create product: {$name} (OC #{$oc_id})" );
            return true;
        }

        return $this->createProduct( $row, $desc, $name, $description, $short_desc, $categories[ $oc_id ] ?? [], $extra_images[ $oc_id ] ?? [], $options[ $oc_id ] ?? [], $specials[ $oc_id ] ?? [], $sec_name, $sec_desc, $sec_metatitle, $sec_metadesc, $sec_short, $sec_metakw, $sec_tags );
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
        string $sec_name      = '',
        string $sec_desc      = '',
        string $sec_metatitle = '',
        string $sec_metadesc  = '',
        string $sec_short     = '',
        string $sec_metakw    = '',
        string $sec_tags      = ''
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
                $sec_name, $sec_desc, $sec_metatitle, $sec_metadesc, $sec_short, $sec_metakw, $sec_tags
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
        string $sec_name      = '',
        string $sec_desc      = '',
        string $sec_metatitle = '',
        string $sec_metadesc  = '',
        string $sec_short     = '',
        string $sec_metakw    = '',
        string $sec_tags      = ''
    ): bool {

        // Map OpenCart status: 1 = enabled → publish, 0 = disabled → draft.
        $wc_status = ( (int) ( $row['status'] ?? 1 ) === 1 ) ? 'publish' : 'draft';

        $post_id = wp_insert_post( [
            'post_title'   => $name,
            'post_content' => wp_kses_post( $description ),
            'post_excerpt' => wp_kses_post( $short_desc ),
            'post_status'  => $wc_status,
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

        // Stock subtract flag — OC: 0=never deduct stock, 1=deduct on purchase.
        // Maps to WC manage_stock. Written ONCE here (not twice).
        $subtract = isset( $row['subtract'] ) ? (bool) $row['subtract'] : true;
        update_post_meta( $post_id, '_manage_stock',   $subtract ? 'yes' : 'no' );
        update_post_meta( $post_id, '_stock',          $qty );
        update_post_meta( $post_id, '_stock_status',   $status );
        update_post_meta( $post_id, '_backorders',     'no' );

        // Dimensions and weight.
        update_post_meta( $post_id, '_weight', $row['weight'] ?? '' );
        update_post_meta( $post_id, '_length', $row['length'] ?? '' );
        update_post_meta( $post_id, '_width',  $row['width'] ?? '' );
        update_post_meta( $post_id, '_height', $row['height'] ?? '' );
        if ( ! empty( $row['weight_class_id'] ) ) { update_post_meta( $post_id, '_octowoo_weight_class_id', (int) $row['weight_class_id'] ); }
        if ( ! empty( $row['length_class_id'] ) ) { update_post_meta( $post_id, '_octowoo_length_class_id', (int) $row['length_class_id'] ); }

        // Sort order → WP menu_order (controls display order in WC shop loops).
        if ( isset( $row['sort_order'] ) ) {
            // Use directly in wp_insert_post args via menu_order — set after creation.
            $wpdb->update( $wpdb->posts, [ 'menu_order' => (int) $row['sort_order'] ], [ 'ID' => $post_id ], [ '%d' ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }

        // Tax class (requires TaxMigrator to have run first to build the map).
        $this->applyTaxClass( $post_id, (int) ( $row['tax_class_id'] ?? 0 ) );

        // Source reference.
        update_post_meta( $post_id, '_octowoo_oc_id', $oc_id );

        // Secondary-language meta (for WPML / Polylang translation pass).
        // ALWAYS write all keys — even empty strings — so translation pass can
        // detect "no secondary data" vs "field not written yet".
        $sfx = $this->secLangSuffix();
        update_post_meta( $post_id, '_octowoo_name' . $sfx,              $sec_name );
        update_post_meta( $post_id, '_octowoo_description' . $sfx,       $sec_desc );
        update_post_meta( $post_id, '_octowoo_short_description' . $sfx, $sec_short );
        update_post_meta( $post_id, '_octowoo_metatitle' . $sfx,         $sec_metatitle );
        update_post_meta( $post_id, '_octowoo_metadesc' . $sfx,          $sec_metadesc );
        update_post_meta( $post_id, '_octowoo_metakw' . $sfx,            $sec_metakw );
        // Secondary-language tag string — Polylang pass reads this to assign translated tags.
        update_post_meta( $post_id, '_octowoo_tag' . $sfx,               $sec_tags );
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
        // Rank Math SEO (auto-detected – safe to call even when Rank Math is not active).
        \OctoWoo\Core\RankMathHelper::writePostMeta(
            $post_id,
            $this->sanitizeText( $desc['meta_title']       ?? '' ),
            $this->sanitizeText( $desc['meta_description'] ?? '' ),
            $this->sanitizeText( $desc['meta_keyword']     ?? '' )
        );
        $this->assignCategories( $post_id, $oc_categories );

        // Assign brand / manufacturer term to product (if ManufacturerMigrator has run).
        $this->assignManufacturerTerm( $post_id, (int) ( $row['manufacturer_id'] ?? 0 ) );

        // Write extra identifiers as WC product meta.
        if ( ! empty( $row['upc']  ) ) { update_post_meta( $post_id, '_octowoo_upc',  sanitize_text_field( $row['upc']  ) ); }
        if ( ! empty( $row['ean']  ) ) { update_post_meta( $post_id, '_octowoo_ean',  sanitize_text_field( $row['ean']  ) ); }
        if ( ! empty( $row['jan']  ) ) { update_post_meta( $post_id, '_octowoo_jan',  sanitize_text_field( $row['jan']  ) ); }
        if ( ! empty( $row['isbn'] ) ) { update_post_meta( $post_id, '_octowoo_isbn', sanitize_text_field( $row['isbn'] ) ); }
        if ( ! empty( $row['mpn']  ) ) { update_post_meta( $post_id, '_octowoo_mpn',  sanitize_text_field( $row['mpn']  ) ); }

        // Minimum purchase quantity (OC 'minimum' field).
        if ( (int) ( $row['minimum'] ?? 1 ) > 1 ) {
            update_post_meta( $post_id, '_wc_min_qty_product', (int) $row['minimum'] );
        }

        // Date available (OC pre-order / availability date).
        if ( ! empty( $row['date_available'] ) && $row['date_available'] !== '0000-00-00' ) {
            update_post_meta( $post_id, '_octowoo_date_available', sanitize_text_field( $row['date_available'] ) );
        }

        // Store the OC image path so the multilingual pass (and future update runs)
        // can re-attempt the import if _thumbnail_id is missing (e.g. image was
        // unavailable during the primary migration pass).
        $featured_oc_path = $row['image'] ?? '';
        if ( $featured_oc_path !== '' ) {
            update_post_meta( $post_id, '_octowoo_oc_image_path', $featured_oc_path );
        }

        // NOTE: assignImages() is intentionally called AFTER COMMIT (below) so that
        // media_handle_sideload() runs outside the transaction.  Inside a transaction
        // the attachment post would be rolled back on failure, leaving the physical
        // file on disk with no DB record (orphan file + duplicate on next run).

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

        // Populate wc_product_meta_lookup so that WC's wc_get_product_id_by_sku()
        // and admin product list work correctly, and future OctoWoo runs can also
        // find this product via the WC API if needed.
        // Static cache: check table existence only once per PHP request.
        static $has_lookup_table = null;
        $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
        if ( $has_lookup_table === null ) {
            $has_lookup_table = ( $wpdb->get_var( "SHOW TABLES LIKE '{$lookup_table}'" ) === $lookup_table ); // phpcs:ignore WordPress.DB
        }
        if ( $has_lookup_table ) { // phpcs:ignore WordPress.DB
            $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $lookup_table,
                [
                    'product_id'     => $post_id,
                    'sku'            => sanitize_text_field( $row['sku'] ?: $row['model'] ),
                    'virtual'        => 0,
                    'downloadable'   => 0,
                    'min_price'      => $price,
                    'max_price'      => $price,
                    'onsale'         => 0,
                    'stock_quantity' => $qty,
                    'stock_status'   => ( $status === 'instock' ) ? 1 : 0,
                    'rating_count'   => 0,
                    'average_rating' => '0.00',
                    'total_sales'    => 0,
                    'tax_status'     => 'taxable',
                    'tax_class'      => '',
                ],
                [ '%d', '%s', '%d', '%d', '%f', '%f', '%d', '%f', '%d', '%d', '%s', '%d', '%s', '%s' ]
            );
        }

        $wpdb->query( 'COMMIT' );

        // Import images AFTER commit: the product is safely persisted before any
        // sideload attempt.  If media_handle_sideload() fails the product remains
        // intact; the repair path in processProduct() will retry the thumbnail on
        // the next run without re-creating the product.
        $this->assignImages( $post_id, $featured_oc_path, $oc_images );

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
        string $sec_name      = '',
        string $sec_desc      = '',
        string $sec_metatitle = '',
        string $sec_metadesc  = '',
        string $sec_short     = '',
        string $sec_metakw    = '',
        string $sec_tags      = ''
    ): bool {
        $oc_id = (int) $row['product_id'];

        wp_update_post( [
            'ID'           => $wc_post_id,
            'post_title'   => $this->sanitizeName( $desc['name'] ?? '' ),
            'post_content' => wp_kses_post( $this->cleanDescription( $desc['description'] ?? '' ) ),
            // OC `tag` = SEO keywords, not a short description; clear any previously set value.
            'post_excerpt' => '',
        ] );

        $price = (float) $row['price'];
        update_post_meta( $wc_post_id, '_regular_price', $price );
        update_post_meta( $wc_post_id, '_price',         $price );

        $qty = (int) $row['quantity'];
        update_post_meta( $wc_post_id, '_stock',        $qty );
        update_post_meta( $wc_post_id, '_stock_status', $this->mapStockStatus( (int) $row['stock_status_id'], $qty ) );

        // Sync tax class.
        $this->applyTaxClass( $wc_post_id, (int) ( $row['tax_class_id'] ?? 0 ) );

        // Secondary-language meta (keep in sync on update).
        $sfx = $this->secLangSuffix();
        // Always write secondary-language fields — even empty — so translation pass detects them.
        update_post_meta( $wc_post_id, '_octowoo_name' . $sfx,              $sec_name );
        update_post_meta( $wc_post_id, '_octowoo_description' . $sfx,       $sec_desc );
        update_post_meta( $wc_post_id, '_octowoo_short_description' . $sfx, $sec_short );
        update_post_meta( $wc_post_id, '_octowoo_tag' . $sfx,               $sec_tags );
        if ( $sec_metatitle ) {
            update_post_meta( $wc_post_id, '_octowoo_metatitle' . $sfx, $sec_metatitle );
        }
        if ( $sec_metadesc ) {
            update_post_meta( $wc_post_id, '_octowoo_metadesc' . $sfx, $sec_metadesc );
        }
        if ( $sec_metakw ) {
            update_post_meta( $wc_post_id, '_octowoo_metakw' . $sfx, $sec_metakw );
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
        // Rank Math SEO (auto-detected – safe to call even when Rank Math is not active).
        \OctoWoo\Core\RankMathHelper::writePostMeta(
            $wc_post_id,
            $this->sanitizeText( $desc['meta_title']       ?? '' ),
            $this->sanitizeText( $desc['meta_description'] ?? '' ),
            $this->sanitizeText( $desc['meta_keyword']     ?? '' )
        );

        // Keep OC image path meta in sync so the multilingual pass can re-attempt
        // image import for secondary-language translations if _thumbnail_id is missing.
        $featured_oc_path = $row['image'] ?? '';
        if ( $featured_oc_path !== '' ) {
            update_post_meta( $wc_post_id, '_octowoo_oc_image_path', $featured_oc_path );
        }
        // Re-assign images on update: re-sets _thumbnail_id and _product_image_gallery.
        // This repairs products whose featured image was missing from the initial run.
        $this->assignImages( $wc_post_id, $featured_oc_path, $oc_images );

        // Keep wc_product_meta_lookup in sync — wp_update_post() alone does not
        // trigger WC_Product::save(), so price/stock filters in WC admin stay stale.
        global $wpdb;
        static $has_lookup_table_upd = null;
        $lookup_table_upd = $wpdb->prefix . 'wc_product_meta_lookup';
        if ( $has_lookup_table_upd === null ) {
            $has_lookup_table_upd = ( $wpdb->get_var( "SHOW TABLES LIKE '{$lookup_table_upd}'" ) === $lookup_table_upd ); // phpcs:ignore WordPress.DB
        }
        if ( $has_lookup_table_upd ) {
            $qty_upd    = (int) $row['quantity'];
            $price_upd  = (float) $row['price'];
            $status_upd = $this->mapStockStatus( (int) $row['stock_status_id'], $qty_upd );
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $lookup_table_upd,
                [
                    'sku'            => sanitize_text_field( $row['sku'] ?: $row['model'] ),
                    'min_price'      => $price_upd,
                    'max_price'      => $price_upd,
                    'stock_quantity' => $qty_upd,
                    'stock_status'   => ( $status_upd === 'instock' ) ? 1 : 0,
                ],
                [ 'product_id' => $wc_post_id ],
                [ '%s', '%f', '%f', '%f', '%d' ],
                [ '%d' ]
            );
        }

        wc_delete_product_transients( $wc_post_id );

        // Re-assign categories on update so products that were migrated before
        // categories were available, or whose category mapping changed, are
        // automatically corrected on the next Products run.
        $this->assignCategories( $wc_post_id, $oc_categories );

        // Also re-assign brand on update in case ManufacturerMigrator ran after last product update.
        $this->assignManufacturerTerm( $wc_post_id, (int) ( $row['manufacturer_id'] ?? 0 ) );

        // Update extra identifiers.
        if ( ! empty( $row['upc']  ) ) { update_post_meta( $wc_post_id, '_octowoo_upc',  sanitize_text_field( $row['upc']  ) ); }
        if ( ! empty( $row['ean']  ) ) { update_post_meta( $wc_post_id, '_octowoo_ean',  sanitize_text_field( $row['ean']  ) ); }
        if ( ! empty( $row['mpn']  ) ) { update_post_meta( $wc_post_id, '_octowoo_mpn',  sanitize_text_field( $row['mpn']  ) ); }

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
        $thumbnail_set = false;

        // Featured image — from oc_product.image.
        if ( $featured_oc_path ) {
            $attachment_id = $this->imageMigrator->importByOcPath( $featured_oc_path );
            if ( $attachment_id && $attachment_id > 0 ) {
                set_post_thumbnail( $post_id, $attachment_id );
                $thumbnail_set = true;
            }
        }

        // Gallery images — from oc_product_image.
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

        // Fallback: when oc_product.image is empty (or failed to import),
        // promote the first gallery image to featured so products never
        // show the WooCommerce grey placeholder.
        if ( ! $thumbnail_set && ! empty( $gallery_ids ) ) {
            set_post_thumbnail( $post_id, $gallery_ids[0] );
            array_shift( $gallery_ids );
        }

        if ( ! empty( $gallery_ids ) ) {
            update_post_meta( $post_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
        }
    }

    // ── Category assignment ───────────────────────────────────────────────────

    /**
     * Assign WooCommerce product categories to a product.
     *
     * v2.5.1 fixes:
     * - Falls back to DB meta lookup when in-memory ID map is cold (resume, wrong order).
     * - In update mode: APPENDS categories (preserves manually-added ones).
     * - Logs a clear warning when OC category IDs cannot be resolved.
     */
    private function assignCategories( int $post_id, array $oc_category_ids ): void {
        if ( empty( $oc_category_ids ) ) {
            return;
        }

        $wc_term_ids    = [];
        $unresolved_ids = [];

        foreach ( $oc_category_ids as $oc_cat_id ) {
            $oc_cat_id  = (int) $oc_cat_id;
            $wc_term_id = $this->checkpoint->getWcId( 'category', $oc_cat_id );

            // DB fallback: if in-memory cache is cold, check _octowoo_oc_id term meta.
            if ( ! $wc_term_id ) {
                global $wpdb;
                $wc_term_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "SELECT tm.term_id
                     FROM {$wpdb->termmeta} tm
                     JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                     WHERE tm.meta_key = '_octowoo_oc_id'
                       AND tm.meta_value = %s
                       AND tt.taxonomy = 'product_cat'
                     LIMIT 1",
                    (string) $oc_cat_id
                ) );

                if ( $wc_term_id > 0 ) {
                    // Warm the in-memory cache so subsequent products in this batch skip the DB hit.
                    $this->checkpoint->saveIdMap( 'category', $oc_cat_id, $wc_term_id );
                }
            }

            if ( $wc_term_id > 0 ) {
                $wc_term_ids[] = $wc_term_id;
            } else {
                $unresolved_ids[] = $oc_cat_id;
            }
        }

        if ( ! empty( $unresolved_ids ) ) {
            $this->logger->warning(
                "[products] WC post #{$post_id}: could not resolve OC category IDs [" .
                implode( ',', $unresolved_ids ) .
                "] — CategoryMigrator may not have run yet, or these categories were inactive in OC. Run CategoryMigrator first, then re-run with on_duplicate=update."
            );
        }

        if ( ! empty( $wc_term_ids ) ) {
            // append=false (replace) on create, append=true on update to preserve manual additions.
            $append = ( $this->onDuplicate() === 'update' );
            wp_set_object_terms( $post_id, array_unique( $wc_term_ids ), 'product_cat', $append );
        }
    }

    /**
     * Assign the WooCommerce brand term to a product (if ManufacturerMigrator has run).
     *
     * @param int $post_id        WC product post ID.
     * @param int $oc_manufacturer_id  OpenCart manufacturer_id (0 = no manufacturer).
     */
    private function assignManufacturerTerm( int $post_id, int $oc_manufacturer_id ): void {
        if ( $oc_manufacturer_id <= 0 ) {
            return;
        }

        $wc_term_id = $this->checkpoint->getWcId( 'manufacturer', $oc_manufacturer_id );

        // DB fallback.
        if ( ! $wc_term_id ) {
            global $wpdb;
            $wc_term_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "SELECT tm.term_id
                 FROM {$wpdb->termmeta} tm
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                 WHERE tm.meta_key = '_octowoo_oc_id'
                   AND tm.meta_value = %s
                   AND tt.taxonomy NOT IN ('product_cat','product_type','product_shipping_class','product_tag')
                 LIMIT 1",
                (string) $oc_manufacturer_id
            ) );

            if ( $wc_term_id > 0 ) {
                $this->checkpoint->saveIdMap( 'manufacturer', $oc_manufacturer_id, $wc_term_id );
            }
        }

        if ( $wc_term_id <= 0 ) {
            return;
        }

        // Find the taxonomy this term belongs to.
        $term = get_term( $wc_term_id );
        if ( ! $term || is_wp_error( $term ) ) {
            return;
        }

        wp_set_object_terms( $post_id, [ $wc_term_id ], $term->taxonomy, true );
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
