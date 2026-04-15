<?php
/**
 * Related-products migrator.
 *
 * After all products have been imported through ProductMigrator, this pass
 * resolves OpenCart's oc_product_related table and writes the WooCommerce
 * upsell (_upsells) post-meta so that product pages show "You may also like".
 *
 * OpenCart tables read:
 *   oc_product_related (product_id, related_id)
 *
 * Must run AFTER 'products' so the OC→WC ID map is populated.
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class RelatedProductsMigrator extends AbstractMigrator {

    private const KEY = 'related';

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        $pfx = $this->pfx();

        // Fetch distinct "source" products that have related entries.
        $products = $this->oc->fetchAll(
            "SELECT DISTINCT product_id FROM `{$pfx}product_related` ORDER BY product_id ASC"
        );

        $total = count( $products );

        if ( $total === 0 ) {
            $this->logger->info( '[related] No related-product entries found in oc_product_related – skipping.' );
            $this->checkpoint->init( self::KEY, 0 );
            $this->checkpoint->start( self::KEY );
            $this->checkpoint->complete( self::KEY );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        // Build the full related-products map: [product_id => [related_id, ...]]
        $all_related = $this->oc->fetchAll(
            "SELECT product_id, related_id FROM `{$pfx}product_related` ORDER BY product_id ASC"
        );

        $related_map = [];
        foreach ( $all_related as $row ) {
            $related_map[ (int) $row['product_id'] ][] = (int) $row['related_id'];
        }

        $this->checkpoint->init( self::KEY, $total );
        $this->checkpoint->start( self::KEY );

        $processed = 0;
        $skipped   = 0;
        $failed    = 0;
        $last_id   = 0;

        foreach ( $products as $product_row ) {
            $oc_id = (int) $product_row['product_id'];
            $last_id = max( $last_id, $oc_id );

            // Resolve OC product ID → WC post ID.
            $wc_post_id = $this->checkpoint->getWcId( 'product', $oc_id );

            if ( ! $wc_post_id ) {
                // Product was not imported (perhaps filtered out or failed).
                $this->logger->debug( "[related] OC #{$oc_id} has no WC mapping – skipping its related products." );
                $skipped++;
                continue;
            }

            $oc_related_ids = $related_map[ $oc_id ] ?? [];

            if ( empty( $oc_related_ids ) ) {
                $skipped++;
                continue;
            }

            // Resolve each related OC ID to a WC ID.
            $wc_related_ids = [];
            foreach ( $oc_related_ids as $oc_rel ) {
                $wc_rel = $this->checkpoint->getWcId( 'product', $oc_rel );
                if ( $wc_rel ) {
                    $wc_related_ids[] = (int) $wc_rel;
                }
            }

            if ( empty( $wc_related_ids ) ) {
                $this->logger->debug( "[related] OC #{$oc_id} WC #{$wc_post_id}: no related products resolved (none imported)." );
                $skipped++;
                continue;
            }

            if ( $this->isDry() ) {
                $this->logger->debug( "[DRY-RUN][related] Would set upsells for WC #{$wc_post_id}: " . implode( ', ', $wc_related_ids ) );
                $processed++;
                continue;
            }

            // Merge with any existing upsells so we don't overwrite manually set ones.
            $existing = (array) get_post_meta( $wc_post_id, '_upsells', true );
            $merged   = array_unique( array_merge( $existing, $wc_related_ids ) );

            update_post_meta( $wc_post_id, '_upsells', array_values( $merged ) );

            $this->logger->info(
                sprintf(
                    '[related] WC #%d (OC #%d): set %d upsell(s) → [%s].',
                    $wc_post_id,
                    $oc_id,
                    count( $merged ),
                    implode( ', ', $merged )
                )
            );

            $processed++;
        }

        $this->checkpoint->update( self::KEY, $last_id, $processed + $skipped );
        $this->checkpoint->complete( self::KEY );

        $this->logger->info(
            "[related] Done. processed={$processed}, skipped={$skipped}, failed={$failed}."
        );

        return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed ];
    }
}
