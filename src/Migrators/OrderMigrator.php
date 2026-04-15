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

    private const KEY = 'order';

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
                    o.comment, o.total, o.order_status_id,
                    o.currency_code, o.currency_value,
                    o.date_added, o.date_modified, o.ip
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
        $existing_wc_id = $this->checkpoint->getWcId( self::KEY, $oc_id );
        if ( $existing_wc_id ) {
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
        $oc_id = (int) $row['order_id'];

        // Resolve WP customer ID.
        $oc_customer_id = (int) $row['customer_id'];
        $wc_user_id     = 0;

        if ( $oc_customer_id > 0 ) {
            $wc_user_id = (int) ( $this->checkpoint->getWcId( 'customer', $oc_customer_id ) ?? 0 );
        }

        // Create the WC order.
        $order = wc_create_order( [
            'customer_id' => $wc_user_id,
            'created_via' => 'octowoo_migration',
        ] );

        if ( is_wp_error( $order ) ) {
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

        $this->checkpoint->saveIdMap( self::KEY, $oc_id, $wc_order_id );
        $this->logger->info( "[orders] Created WC order #{$wc_order_id} (OC #{$oc_id}), status: {$wc_status}." );

        return true;
    }

    // ── Order items ───────────────────────────────────────────────────────────

    private function addOrderItem( \WC_Order $order, array $oc_item ): void {
        $name    = $this->sanitizeText( $oc_item['name'] ?? 'Unknown Product' );
        $qty     = max( 1, (int) $oc_item['quantity'] );
        $price   = (float) $oc_item['price'];
        $tax     = (float) $oc_item['tax'];
        $oc_prod = (int) $oc_item['product_id'];

        // Try to link to the migrated WC product.
        $wc_product_id = $this->checkpoint->getWcId( 'product', $oc_prod );
        $product       = $wc_product_id ? wc_get_product( $wc_product_id ) : null;

        $item = new \WC_Order_Item_Product();
        $item->set_name( $name );
        $item->set_quantity( $qty );
        $item->set_subtotal( $price * $qty );
        $item->set_total( (float) $oc_item['total'] );
        $item->set_subtotal_tax( $tax * $qty );
        $item->set_total_tax( $tax * $qty );

        if ( $product ) {
            $item->set_product( $product );
        } else {
            $item->add_meta_data( '_octowoo_oc_product_id', $oc_prod );
        }

        $order->add_item( $item );
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
