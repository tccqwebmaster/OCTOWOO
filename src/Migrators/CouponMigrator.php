<?php
/**
 * Coupon migrator.
 *
 * Imports OpenCart coupons into WooCommerce coupon posts.
 *
 * OpenCart tables read:
 *   oc_coupon         – code, type (P=percent, F=fixed), discount, dates, limits
 *   oc_coupon_product – per-product restrictions
 *
 * WooCommerce coupon post_type = 'shop_coupon'.
 * Discount type mapping:
 *   OC 'P' → WC 'percent'
 *   OC 'F' → WC 'fixed_cart'
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class CouponMigrator extends AbstractMigrator {

    /** Checkpoint key (matches MigrationManager order/config key). */
    private const KEY = 'coupons';

    /** Stable ID-map entity key used for cross-migrator references. */
    private const MAP_KEY = 'coupon';

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        $pfx       = $this->pfx();
        $resume_id = $this->checkpoint->getLastId( self::KEY );

        if ( $resume_id === PHP_INT_MAX ) {
            $this->logger->info( '[coupons] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        $coupon_products = $this->fetchCouponProducts();

        $total_callback = fn() => $this->oc->count( 'coupon', 'status = 1' );

        $batch_callback = fn( int $offset, int $limit ) => $this->oc->fetchBatch(
            "SELECT coupon_id, name, code, type, discount,
                    date_start, date_end,
                    uses_total, uses_customer,
                    logged, shipping, status, date_added
             FROM `{$pfx}coupon`
             WHERE status = 1
             ORDER BY coupon_id ASC",
            [],
            $limit,
            $offset
        );

        $item_callback = fn( array $row ) => $this->processCoupon( $row, $coupon_products );

        return $this->batch->run(
            total_callback:  $total_callback,
            batch_callback:  $batch_callback,
            item_callback:   $item_callback,
            migrator:        self::KEY,
            checkpoint:      $this->checkpoint,
            resume_after_id: $resume_id,
            id_field:        'coupon_id'
        );
    }

    // ── Per-coupon processing ─────────────────────────────────────────────────

    private function processCoupon( array $row, array $coupon_products ): bool {
        $oc_id = (int) $row['coupon_id'];
        $code  = sanitize_text_field( strtolower( $row['code'] ) );

        // Duplicate check by coupon code.
        $existing_post = $this->findCouponByCode( $code );
        if ( $existing_post ) {
            $this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $existing_post );
            if ( $this->onDuplicate() === 'update' ) {
                return $this->updateCoupon( $existing_post, $row, $coupon_products[ $oc_id ] ?? [] );
            }
            $this->logger->debug( "[coupons] Duplicate code [{$code}] → WC post #{$existing_post} – skipping." );
            return false;
        }

        // Map by checkpoint.
        $existing_wc_id = $this->checkpoint->getWcId( self::MAP_KEY, $oc_id );
        if ( $existing_wc_id ) {
            $this->logger->debug( "[coupons] Already migrated OC #{$oc_id} – skipping." );
            return false;
        }

        if ( $this->isDry() ) {
            $this->logger->debug( "[DRY-RUN] Would create coupon: {$code} (OC #{$oc_id})" );
            return true;
        }

        return $this->createCoupon( $oc_id, $row, $coupon_products[ $oc_id ] ?? [] );
    }

    // ── Create ────────────────────────────────────────────────────────────────

    private function createCoupon( int $oc_id, array $row, array $products ): bool {
        $code          = sanitize_text_field( strtolower( $row['code'] ) );
        $discount_type = $this->mapDiscountType( $row['type'] );
        $amount        = (float) $row['discount'];

        $post_id = wp_insert_post( [
            'post_title'  => $code,
            'post_name'   => $code,
            'post_type'   => 'shop_coupon',
            'post_status' => 'publish',
            'post_date'   => $row['date_added'] ?? current_time( 'mysql' ),
        ], true );

        if ( is_wp_error( $post_id ) ) {
            $this->logger->error(
                "[coupons] wp_insert_post failed for OC #{$oc_id}: " . $post_id->get_error_message()
            );
            return false;
        }

        $this->writeCouponMeta( $post_id, $discount_type, $amount, $row, $products );
        $this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $post_id );

        $this->logger->info( "[coupons] Created WC coupon #{$post_id}: [{$code}] ({$discount_type})" );
        return true;
    }

    private function updateCoupon( int $post_id, array $row, array $products ): bool {
        $discount_type = $this->mapDiscountType( $row['type'] );
        $amount        = (float) $row['discount'];

        $this->writeCouponMeta( $post_id, $discount_type, $amount, $row, $products );

        $this->logger->info( "[coupons] Updated WC coupon #{$post_id}: [{$row['code']}]" );
        return true;
    }

    // ── Meta ──────────────────────────────────────────────────────────────────

    private function writeCouponMeta(
        int    $post_id,
        string $discount_type,
        float  $amount,
        array  $row,
        array  $products
    ): void {
        update_post_meta( $post_id, 'discount_type',        $discount_type );
        update_post_meta( $post_id, 'coupon_amount',        $amount );
        update_post_meta( $post_id, 'individual_use',       'no' );
        update_post_meta( $post_id, 'usage_limit',          (int) $row['uses_total'] ?: '' );
        update_post_meta( $post_id, 'usage_limit_per_user', (int) $row['uses_customer'] ?: '' );
        update_post_meta( $post_id, 'free_shipping',        ( $row['shipping'] == '1' ) ? 'yes' : 'no' );
        update_post_meta( $post_id, 'usage_count',          0 );
        update_post_meta( $post_id, '_octowoo_oc_id',       $row['coupon_id'] );

        // Date validity.
        if ( ! empty( $row['date_start'] ) && $row['date_start'] !== '0000-00-00' ) {
            update_post_meta( $post_id, 'date_expires', strtotime( $row['date_start'] ) );
        }
        if ( ! empty( $row['date_end'] ) && $row['date_end'] !== '0000-00-00' ) {
            update_post_meta( $post_id, 'date_expires', strtotime( $row['date_end'] ) );
        }

        // Product restrictions.
        if ( ! empty( $products ) ) {
            $wc_product_ids = [];
            foreach ( $products as $oc_prod_id ) {
                $wc_id = $this->checkpoint->getWcId( 'product', (int) $oc_prod_id );
                if ( $wc_id ) {
                    $wc_product_ids[] = (int) $wc_id;
                }
            }
            if ( $wc_product_ids ) {
                update_post_meta( $post_id, 'product_ids', implode( ',', $wc_product_ids ) );
            }
        }
    }

    // ── Data helpers ──────────────────────────────────────────────────────────

    /**
     * @return array<int, int[]> [coupon_id => [product_id, ...]]
     */
    private function fetchCouponProducts(): array {
        $pfx  = $this->pfx();
        $rows = $this->oc->fetchAll( "SELECT coupon_id, product_id FROM `{$pfx}coupon_product`" );

        $indexed = [];
        foreach ( $rows as $row ) {
            $indexed[ (int) $row['coupon_id'] ][] = (int) $row['product_id'];
        }
        return $indexed;
    }

    private function findCouponByCode( string $code ): ?int {
        global $wpdb;

        $post_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_name = %s AND post_status = 'publish' LIMIT 1",
                $code
            )
        );

        return $post_id ? (int) $post_id : null;
    }

    private function mapDiscountType( string $oc_type ): string {
        return match ( strtoupper( $oc_type ) ) {
            'P'     => 'percent',
            'F'     => 'fixed_cart',
            default => 'fixed_cart',
        };
    }
}
