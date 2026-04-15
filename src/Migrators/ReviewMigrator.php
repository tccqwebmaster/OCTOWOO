<?php
/**
 * Review migrator.
 *
 * Reads OpenCart product reviews (oc_review) and inserts them as WordPress
 * comments with type "review", compatible with WooCommerce's star-rating system.
 *
 * Must run AFTER ProductMigrator and CustomerMigrator so both ID maps are ready.
 *
 * OpenCart table used:
 *   oc_review – review_id, product_id, customer_id, author, author_email,
 *               text, rating, status, date_added
 *
 * @package OctoWoo\Migrators
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class ReviewMigrator extends AbstractMigrator {

    private const KEY = 'reviews';

    /** WC product IDs that received at least one new review – rating cache cleared at shutdown. */
    private array $dirty_products = [];

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        $resume_id = $this->checkpoint->getLastId( self::KEY );

        if ( $resume_id === PHP_INT_MAX ) {
            $this->logger->info( '[reviews] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        $pfx = $this->pfx();

        $total_callback = function () use ( $pfx ): int {
            return $this->oc->count( 'review' );
        };

        $batch_callback = function ( int $offset, int $limit ) use ( $pfx ): array {
            return $this->oc->fetchBatch(
                "SELECT review_id, product_id, customer_id, author, author_email,
                        `text`, rating, `status`, date_added
                 FROM `{$pfx}review`
                 ORDER BY review_id ASC",
                [],
                $limit,
                $offset
            );
        };

        $item_callback = function ( array $row ): bool {
            return $this->processReview( $row );
        };

        $result = $this->batch->run(
            total_callback:  $total_callback,
            batch_callback:  $batch_callback,
            item_callback:   $item_callback,
            migrator:        self::KEY,
            checkpoint:      $this->checkpoint,
            resume_after_id: $resume_id,
            id_field:        'review_id'
        );

        // Flush WC rating caches for all affected products once at the end.
        $this->flushRatingCaches();

        return $result;
    }

    // ── Per-item processing ───────────────────────────────────────────────────

    private function processReview( array $row ): bool {
        $oc_id         = (int) $row['review_id'];
        $oc_product_id = (int) $row['product_id'];

        $wc_product_id = $this->checkpoint->getWcId( 'product', $oc_product_id );
        if ( ! $wc_product_id ) {
            $this->logger->debug( "[reviews] OC product #{$oc_product_id} not in ID map – skipping review #{$oc_id}." );
            return false;
        }

        // Duplicate check.
        $existing = $this->checkpoint->getWcId( self::KEY, $oc_id );
        if ( $existing ) {
            if ( $this->onDuplicate() === 'skip' ) {
                return false;
            }
            // Update: just mark dirty so rating cache refreshes.
            $this->dirty_products[ (int) $wc_product_id ] = true;
            return false;
        }

        if ( $this->isDry() ) {
            $this->logger->debug( "[DRY-RUN] Would create review #{$oc_id} on WC product #{$wc_product_id}" );
            return true;
        }

        // Resolve WC customer (if any).
        $wc_user_id = 0;
        if ( (int) $row['customer_id'] > 0 ) {
            $wc_user_id = (int) ( $this->checkpoint->getWcId( 'customer', (int) $row['customer_id'] ) ?? 0 );
        }

        $date_local = $row['date_added'] ?? current_time( 'mysql' );
        $date_gmt   = get_gmt_from_date( $date_local );

        $comment_id = wp_insert_comment( [
            'comment_post_ID'      => (int) $wc_product_id,
            'comment_author'       => sanitize_text_field( $row['author'] ?? 'Anonymous' ),
            'comment_author_email' => sanitize_email( $row['author_email'] ?? '' ),
            'comment_content'      => $this->sanitizeText( $row['text'] ?? '' ),
            'comment_type'         => 'review',
            'comment_approved'     => (int) $row['status'] === 1 ? 1 : 0,
            'comment_date'         => $date_local,
            'comment_date_gmt'     => $date_gmt,
            'user_id'              => $wc_user_id,
        ] );

        if ( ! $comment_id ) {
            $this->logger->error( "[reviews] wp_insert_comment failed for OC review #{$oc_id}" );
            return false;
        }

        add_comment_meta( $comment_id, 'rating',                   min( 5, max( 1, (int) $row['rating'] ) ), true );
        add_comment_meta( $comment_id, '_octowoo_oc_review_id',    $oc_id,         true );
        add_comment_meta( $comment_id, 'verified',                 0,              true );

        $this->checkpoint->saveIdMap( self::KEY, $oc_id, $comment_id );
        $this->dirty_products[ (int) $wc_product_id ] = true;

        $this->logger->debug(
            "[reviews] Created WP comment #{$comment_id} ← OC review #{$oc_id} (product #{$wc_product_id}, rating {$row['rating']})"
        );

        return true;
    }

    // ── Rating cache ──────────────────────────────────────────────────────────

    /**
     * Clear and rebuild WooCommerce star-rating caches for all affected products.
     */
    private function flushRatingCaches(): void {
        if ( empty( $this->dirty_products ) ) {
            return;
        }

        foreach ( array_keys( $this->dirty_products ) as $product_id ) {
            // WC stores average rating in post meta; clear it so WC recalculates.
            delete_post_meta( $product_id, '_wc_average_rating' );
            delete_post_meta( $product_id, '_wc_review_count' );
            delete_post_meta( $product_id, '_wc_rating_count' );

            // WC 3.x+ helper.
            if ( class_exists( 'WC_Comments' ) && method_exists( 'WC_Comments', 'clear_transients' ) ) {
                \WC_Comments::clear_transients( $product_id );
            }
        }

        $this->logger->info(
            '[reviews] Cleared rating cache for ' . count( $this->dirty_products ) . ' product(s).'
        );

        $this->dirty_products = [];
    }
}
