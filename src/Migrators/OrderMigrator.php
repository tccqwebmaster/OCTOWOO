<?php
/**
 * Order migrator.
 *
 * Imports OpenCart orders into WooCommerce including:
 *  - Order items (products, quantities, prices).
 *  - Order totals (shipping, discount, tax).
 *  - Billing / shipping addresses.
 *  - Customer linkage (OC customer → WP user).
 *  - Status mapping.
 *
 * OpenCart tables read:
 *   oc_order, oc_order_product, oc_order_total,
 *   oc_order_option (for variation info), oc_currency
 *
 * Uses WooCommerce's low-level wc_create_order() API so all
 * WC hooks fire correctly.
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class OrderMigrator extends AbstractMigrator {

    /** Checkpoint key (matches MigrationManager order/config key). */
    private const KEY = 'orders';

    /** Stable ID-map entity key used by lookups and legacy mappings. */
    private const MAP_KEY = 'order';

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        $pfx       = $this->pfx();
        $resume_id = $this->checkpoint->getLastId( self::KEY );

        if ( $resume_id === PHP_INT_MAX ) {
            $this->logger->info( '[orders] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        // Pre-fetch order items and totals.
        $order_products = $this->fetchOrderProducts();
        $order_totals   = $this->fetchOrderTotals();
        $currencies     = $this->fetchCurrencies();

        $total_callback = fn() => $this->oc->count( 'order' );

        // v2.4.72: OC1 schema differences:
        //  - No currency_value column (OC2+)
        //  - No ip column in some OC1 builds
        //  - comment column may be missing in OC 1.0.x
        $oc_major       = $this->ocMajor();
        $currency_val   = ( $oc_major === 1 ) ? '1.000000 AS currency_value' : 'o.currency_value';
        $ip_col         = ( $oc_major === 1 ) ? "'' AS ip" : 'o.ip';
        $comment_col    = ( $oc_major === 1 ) ? "COALESCE(o.comment, '') AS comment" : 'o.comment';

        $batch_callback = fn( int $offset, int $limit ) => $this->oc->fetchBatch(
            "SELECT o.order_id, o.customer_id, o.firstname, o.lastname,
                    o.email, o.telephone,
                    o.payment_firstname, o.payment_lastname, o.payment_company,
                    o.payment_address_1, o.payment_address_2, o.payment_city,
                    o.payment_postcode, o.payment_country, o.payment_zone,
                    o.payment_method, o.payment_code,
                    o.shipping_firstname, o.shipping_lastname, o.shipping_company,
                    o.shipping_address_1, o.shipping_address_2, o.shipping_city,
                    o.shipping_postcode, o.shipping_country, o.shipping_zone,
                    o.shipping_method,
                    {$comment_col}, o.total, o.order_status_id,
                    o.currency_code, {$currency_val},
                    o.date_added, o.date_modified, {$ip_col}
             FROM `{$pfx}order` o
             ORDER BY o.order_id ASC",
            [],
            $limit,
            $offset
        );

        $item_callback = fn( array $row ) => $this->processOrder(
            $row, $order_products, $order_totals, $currencies
        );

        return $this->batch->run(
            total_callback:  $total_callback,
            batch_callback:  $batch_callback,
            item_callback:   $item_callback,
            migrator:        self::KEY,
            checkpoint:      $this->checkpoint,
            resume_after_id: $resume_id,
            id_field:        'order_id'
        );
    }

    // ── Per-order processing ──────────────────────────────────────────────────

    private function processOrder(
        array $row,
        array $order_products,
        array $order_totals,
        array $currencies
    ): bool {
        $oc_id = (int) $row['order_id'];

        // Duplicate check.
        $existing_wc_id = $this->checkpoint->getWcId( self::MAP_KEY, $oc_id );
        if ( $existing_wc_id ) {
            // On update strategy: re-link order items to current product IDs (by SKU)
            // without touching amounts, addresses, or status.
            if ( $this->onDuplicate() === 'update' ) {
                $this->relinkOrderItems( $existing_wc_id, $order_products[ $oc_id ] ?? [] );
            }
            $this->logger->debug( "[orders] Already migrated OC #{$oc_id} → WC #{$existing_wc_id} – skipping." );
            return false;
        }

        if ( $this->isDry() ) {
            $this->logger->debug( "[DRY-RUN] Would create order for OC #{$oc_id}." );
            return true;
        }

        return $this->createOrder( $row, $order_products[ $oc_id ] ?? [], $order_totals[ $oc_id ] ?? [], $currencies );
    }

    // ── Create order ──────────────────────────────────────────────────────────

    private function createOrder(
        array $row,
        array $products,
        array $totals,
        array $currencies
    ): bool {
        global $wpdb;

        $oc_id = (int) $row['order_id'];

        // Resolve WP customer ID.
        $oc_customer_id = (int) $row['customer_id'];
        $wc_user_id     = 0;

        if ( $oc_customer_id > 0 ) {
            $wc_user_id = (int) ( $this->checkpoint->getWcId( 'customer', $oc_customer_id ) ?? 0 );
        }

        // ── Begin per-record transaction ──────────────────────────────────────
        // Ensures a partially-written order (missing items / totals) is never
        // committed to the database. If any step throws, the entire order is rolled back.
        $wpdb->query( 'START TRANSACTION' );

        try {
            return $this->doCreateOrder( $wpdb, $oc_id, $oc_customer_id, $wc_user_id, $row, $products, $totals, $currencies );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            $this->logger->error(
                "[orders] Transaction rolled back for OC #{$oc_id}: " . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Internal helper — runs inside the transaction opened by createOrder().
     */
    private function doCreateOrder(
        \wpdb $wpdb,
        int   $oc_id,
        int   $oc_customer_id,
        int   $wc_user_id,
        array $row,
        array $products,
        array $totals,
        array $currencies
    ): bool {
        // Create the WC order.
        $order = wc_create_order( [
            'customer_id' => $wc_user_id,
            'created_via' => 'octowoo_migration',
        ] );

        if ( is_wp_error( $order ) ) {
            $wpdb->query( 'ROLLBACK' );
            $this->logger->error(
                "[orders] wc_create_order failed for OC #{$oc_id}: " . $order->get_error_message()
            );
            return false;
        }

        $wc_order_id = $order->get_id();

        // ── Addresses ────────────────────────────────────────────────────────

        $order->set_billing_first_name(  $this->sanitizeText( $row['payment_firstname'] ?? $row['firstname'] ) );
        $order->set_billing_last_name(   $this->sanitizeText( $row['payment_lastname']  ?? $row['lastname'] ) );
        $order->set_billing_company(     $this->sanitizeText( $row['payment_company']   ?? '' ) );
        $order->set_billing_address_1(   $this->sanitizeText( $row['payment_address_1'] ?? '' ) );
        $order->set_billing_address_2(   $this->sanitizeText( $row['payment_address_2'] ?? '' ) );
        $order->set_billing_city(        $this->sanitizeText( $row['payment_city']       ?? '' ) );
        $order->set_billing_postcode(    sanitize_text_field( $row['payment_postcode']   ?? '' ) );
        $order->set_billing_country(     sanitize_text_field( $row['payment_country']    ?? '' ) );
        $order->set_billing_state(       sanitize_text_field( $row['payment_zone']       ?? '' ) );
        $order->set_billing_email(       sanitize_email( $row['email'] ) );
        $order->set_billing_phone(       sanitize_text_field( $row['telephone'] ?? '' ) );

        $order->set_shipping_first_name( $this->sanitizeText( $row['shipping_firstname'] ?? '' ) );
        $order->set_shipping_last_name(  $this->sanitizeText( $row['shipping_lastname']  ?? '' ) );
        $order->set_shipping_company(    $this->sanitizeText( $row['shipping_company']   ?? '' ) );
        $order->set_shipping_address_1(  $this->sanitizeText( $row['shipping_address_1'] ?? '' ) );
        $order->set_shipping_address_2(  $this->sanitizeText( $row['shipping_address_2'] ?? '' ) );
        $order->set_shipping_city(       $this->sanitizeText( $row['shipping_city']      ?? '' ) );
        $order->set_shipping_postcode(   sanitize_text_field( $row['shipping_postcode']  ?? '' ) );
        $order->set_shipping_country(    sanitize_text_field( $row['shipping_country']   ?? '' ) );
        $order->set_shipping_state(      sanitize_text_field( $row['shipping_zone']      ?? '' ) );

        $order->set_payment_method(       sanitize_key( $row['payment_code']   ?? '' ) );
        $order->set_payment_method_title( sanitize_text_field( $row['payment_method'] ?? '' ) );

        // Currency.
        $order->set_currency( sanitize_text_field( $row['currency_code'] ?? get_woocommerce_currency() ) );

        // Date.
        if ( ! empty( $row['date_added'] ) ) {
            $order->set_date_created( $row['date_added'] );
        }

        // ── Order items ───────────────────────────────────────────────────────

        foreach ( $products as $oc_item ) {
            $this->addOrderItem( $order, $oc_item );
        }

        // ── Totals (shipping, discount, fee) ──────────────────────────────────

        $this->applyOrderTotals( $order, $totals );

        // ── Status ───────────────────────────────────────────────────────────

        $wc_status = $this->mapOrderStatus( (int) $row['order_status_id'] );
        $order->set_status( $wc_status );

        // ── Save & finalize ───────────────────────────────────────────────────

        $order->set_customer_note( $this->sanitizeText( $row['comment'] ?? '' ) );
        $order->add_meta_data( '_octowoo_oc_order_id', $oc_id );
        $order->add_meta_data( '_octowoo_oc_customer_id', $oc_customer_id );
        // Generic mapping so orders can be resolved via a unified meta key.
        $order->add_meta_data( '_octowoo_oc_id', $oc_id );

        $order->calculate_totals();
        $order->save();

        $wc_order_id = $order->get_id();

        // ── Custom order number ───────────────────────────────────────────────
        // Preserve the original OpenCart order number so store owners can
        // cross-reference orders without renumbering everything.
        //
        // Supported integrations:
        //  - OctoWoo native meta (_octowoo_oc_order_number)
        //  - Sequential Order Numbers plugin (WooCommerce.com)
        //  - WooCommerce Sequential Order Numbers Pro (various authors)
        update_post_meta( $wc_order_id, '_octowoo_oc_order_number', $oc_id );

        if ( function_exists( 'WC_Seq_Order_Number' ) || class_exists( 'WC_Seq_Order_Number_Pro' ) ) {
            update_post_meta( $wc_order_id, '_order_number',           $oc_id );
            update_post_meta( $wc_order_id, '_order_number_formatted', '#' . $oc_id );
        }

        // Add a private order note so the original OC ID is always visible.
        $order->add_order_note(
            /* translators: %d: OpenCart order ID */
            sprintf( __( 'Imported from OpenCart. Original order #%d.', 'octowoo' ), $oc_id ),
            false, // not customer-facing
            false
        );

        $this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $wc_order_id );

        $wpdb->query( 'COMMIT' );

        $this->logger->info( "[orders] Created WC order #{$wc_order_id} (OC #{$oc_id}), status: {$wc_status}." );

        return true;
    }

    // ── Order items ───────────────────────────────────────────────────────────

    private function addOrderItem( \WC_Order $order, array $oc_item ): void {
        $name     = $this->sanitizeText( $oc_item['name'] ?? 'Unknown Product' );
        $qty      = max( 1, (int) $oc_item['quantity'] );
        $price    = (float) $oc_item['price'];
        $tax      = (float) $oc_item['tax'];
        $oc_prod  = (int) $oc_item['product_id'];
        $oc_model = trim( (string) ( $oc_item['model'] ?? '' ) );

        // Try to link to the migrated WC product.
        // Strategy 1: id_map lookup (fastest when id_map is warm).
        $wc_product_id = $this->checkpoint->getWcId( 'product', $oc_prod );
        $product       = $wc_product_id ? wc_get_product( $wc_product_id ) : null;

        // Strategy 2: SKU / model fallback — used when the id_map points to a
        // product that was deleted after the initial migration (re-migration scenario).
        // Also used when the Products step hasn't run yet (e.g. order-first import).
        if ( ! $product && $oc_model !== '' ) {
            $product = $this->findProductBySku( $oc_model );
            if ( $product ) {
                // Back-fill the id_map so subsequent order-item lookups use the fast path.
                $this->checkpoint->saveIdMap( 'product', $oc_prod, $product->get_id() );
                $this->logger->debug( "[orders] Linked OC product #{$oc_prod} to WC #{" . $product->get_id() . "} via SKU '{$oc_model}'." );
            }
        }

        $item = new \WC_Order_Item_Product();
        $item->set_name( $name );
        $item->set_quantity( $qty );
        $item->set_subtotal( $price * $qty );
        $item->set_total( (float) $oc_item['total'] );
        $item->set_subtotal_tax( $tax * $qty );
        $item->set_total_tax( $tax * $qty );

        // Always store the OC product reference so the repair tool can re-link
        // after products are deleted and re-migrated.
        $item->add_meta_data( '_octowoo_oc_product_id', $oc_prod, true );
        if ( $oc_model !== '' ) {
            $item->add_meta_data( '_octowoo_oc_product_model', $oc_model, true );
        }

        if ( $product ) {
            $item->set_product( $product );
        }

        $order->add_item( $item );
    }

    /**
     * Look up a WooCommerce product by SKU, querying wp_postmeta directly so
     * we never depend on the wc_product_meta_lookup table which can be stale.
     */
    private function findProductBySku( string $sku ): ?\WC_Product {
        global $wpdb;

        $id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key   = '_sku'
                   AND pm.meta_value = %s
                   AND p.post_type  IN ('product','product_variation')
                   AND p.post_status != 'trash'
                 LIMIT 1",
                $sku
            )
        );

        return $id > 0 ? wc_get_product( $id ) : null;
    }

    /**
     * Re-link order items in an already-migrated WC order to their current
     * WC product IDs using id_map → SKU fallback.
     *
     * Called on `on_duplicate=update` so that after products are deleted and
     * re-migrated (getting new WC post IDs), a second Orders run corrects all
     * order item product_id references without re-creating the order.
     */
    private function relinkOrderItems( int $wc_order_id, array $oc_products ): void {
        global $wpdb;

        if ( empty( $oc_products ) ) {
            return;
        }

        $order = wc_get_order( $wc_order_id );
        if ( ! $order ) {
            return;
        }

        // Build a map: oc_product_id → best WC product (id_map then SKU).
        $resolve = function ( int $oc_prod, string $model ): ?\WC_Product {
            $wc_id   = $this->checkpoint->getWcId( 'product', $oc_prod );
            $product = $wc_id ? wc_get_product( $wc_id ) : null;
            if ( ! $product && $model !== '' ) {
                $product = $this->findProductBySku( $model );
                if ( $product ) {
                    $this->checkpoint->saveIdMap( 'product', $oc_prod, $product->get_id() );
                }
            }
            return $product;
        };

        // Index OC items by oc_product_id.
        $oc_by_id = [];
        foreach ( $oc_products as $oc_item ) {
            $oc_by_id[ (int) $oc_item['product_id'] ] = $oc_item;
        }

        $relinked = 0;
        foreach ( $order->get_items() as $item_id => $item ) {
            if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
                continue;
            }

            $oc_prod  = (int) $item->get_meta( '_octowoo_oc_product_id', true );
            $oc_model = (string) $item->get_meta( '_octowoo_oc_product_model', true );

            // Back-fill model from OC data if not yet stored on the item.
            if ( $oc_prod > 0 && $oc_model === '' && isset( $oc_by_id[ $oc_prod ] ) ) {
                $oc_model = trim( (string) ( $oc_by_id[ $oc_prod ]['model'] ?? '' ) );
            }

            if ( $oc_prod <= 0 ) {
                continue; // Not an OctoWoo-migrated item.
            }

            $product = $resolve( $oc_prod, $oc_model );
            if ( ! $product ) {
                continue;
            }

            $new_wc_id = $product->get_id();
            $old_wc_id = (int) $item->get_product_id();

            if ( $new_wc_id === $old_wc_id && $product instanceof \WC_Product ) {
                continue; // Already pointing to a valid product.
            }

            // Update _product_id and _variation_id directly in order item meta.
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prefix . 'woocommerce_order_itemmeta',
                [ 'meta_value' => $new_wc_id ],
                [ 'order_item_id' => $item_id, 'meta_key' => '_product_id' ]
            );
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prefix . 'woocommerce_order_itemmeta',
                [ 'meta_value' => 0 ],
                [ 'order_item_id' => $item_id, 'meta_key' => '_variation_id' ]
            );

            // Also update OctoWoo model meta if back-filled.
            if ( $oc_model !== '' ) {
                $existing = $item->get_meta( '_octowoo_oc_product_model', true );
                if ( $existing === '' ) {
                    wc_update_order_item_meta( $item_id, '_octowoo_oc_product_model', $oc_model );
                }
            }

            $relinked++;
            $this->logger->debug( "[orders] Re-linked order #{$wc_order_id} item #{$item_id}: product_id {$old_wc_id} → {$new_wc_id} (OC SKU '{$oc_model}')." );
        }

        if ( $relinked > 0 ) {
            // Bust order caches so updated product data shows immediately.
            $order->get_data_store()->clear_caches( $order );
            $this->logger->info( "[orders] Re-linked {$relinked} item(s) in WC order #{$wc_order_id}." );
        }
    }

    // ── Order totals ──────────────────────────────────────────────────────────

    private function applyOrderTotals( \WC_Order $order, array $totals ): void {
        foreach ( $totals as $total ) {
            $code  = $total['code'] ?? '';
            $value = (float) $total['value'];
            $title = $this->sanitizeText( $total['title'] ?? $code );

            switch ( $code ) {
                case 'shipping':
                    $item = new \WC_Order_Item_Shipping();
                    $item->set_name( $title );
                    $item->set_total( $value );
                    $order->add_item( $item );
                    break;

                case 'coupon':
                case 'voucher':
                    $item = new \WC_Order_Item_Coupon();
                    $item->set_name( $title );
                    $item->set_discount( abs( $value ) );
                    $order->add_item( $item );
                    break;

                case 'tax':
                    $item = new \WC_Order_Item_Tax();
                    $item->set_name( $title );
                    $item->set_tax_total( $value );
                    $order->add_item( $item );
                    break;

                case 'total':
                case 'sub_total':
                    // Handled by calculate_totals(); skip.
                    break;

                default:
                    // Generic fee for custom totals (handling fee, etc.).
                    if ( abs( $value ) > 0 ) {
                        $item = new \WC_Order_Item_Fee();
                        $item->set_name( $title );
                        $item->set_total( $value );
                        $order->add_item( $item );
                    }
                    break;
            }
        }
    }

    // ── Data fetching helpers ─────────────────────────────────────────────────

    /** @return array<int, array<int, array<string,mixed>>> [order_id => [products]] */
    private function fetchOrderProducts(): array {
        $pfx  = $this->pfx();
        $rows = $this->oc->fetchAll(
            "SELECT order_id, product_id, name, model, quantity, price, total, tax
             FROM `{$pfx}order_product`"
        );

        $indexed = [];
        foreach ( $rows as $row ) {
            $indexed[ (int) $row['order_id'] ][] = $row;
        }
        return $indexed;
    }

    /** @return array<int, array<int, array<string,mixed>>> [order_id => [totals]] */
    private function fetchOrderTotals(): array {
        $pfx  = $this->pfx();
        $rows = $this->oc->fetchAll(
            "SELECT order_id, code, title, value, sort_order
             FROM `{$pfx}order_total`
             ORDER BY sort_order ASC"
        );

        $indexed = [];
        foreach ( $rows as $row ) {
            $indexed[ (int) $row['order_id'] ][] = $row;
        }
        return $indexed;
    }

    /** @return array<string, float> [currency_code => value] */
    private function fetchCurrencies(): array {
        $pfx  = $this->pfx();
        $rows = $this->oc->fetchAll( "SELECT code, value FROM `{$pfx}currency`" );

        $map = [];
        foreach ( $rows as $row ) {
            $map[ $row['code'] ] = (float) $row['value'];
        }
        return $map;
    }
}
