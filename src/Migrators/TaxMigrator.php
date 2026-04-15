<?php
/**
 * Tax class migrator.
 *
 * Reads OpenCart tax classes from oc_tax_class and creates matching
 * WooCommerce tax classes via the WC API.  After running, a mapping of
 * OC tax_class_id → WC tax class slug is stored in the WordPress option
 * 'octowoo_tax_class_map' so that ProductMigrator can write the correct
 * _tax_class meta on every product.
 *
 * OpenCart tables read:
 *   oc_tax_class (tax_class_id, filename)
 *   oc_tax_class_description (tax_class_id, language_id, title, description)
 *
 * Should run BEFORE products so the map is available when products are created.
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class TaxMigrator extends AbstractMigrator {

    private const KEY = 'tax';

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        $pfx     = $this->pfx();
        $lang_id = $this->langId();

        // Count the total so checkpoint can show progress correctly.
        $total = (int) $this->oc->count( 'tax_class' );

        if ( $total === 0 ) {
            $this->logger->info( '[tax] No tax classes found in OC – skipping.' );
            $this->checkpoint->init( self::KEY, 0 );
            $this->checkpoint->start( self::KEY );
            $this->checkpoint->complete( self::KEY );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        // Fetch all tax classes with their primary language description.
        $rows = $this->oc->fetchAll(
            "SELECT tc.tax_class_id,
                    COALESCE(
                        (SELECT tcd.title FROM `{$pfx}tax_class_description` tcd
                         WHERE tcd.tax_class_id = tc.tax_class_id AND tcd.language_id = {$lang_id}
                         LIMIT 1),
                        (SELECT tcd2.title FROM `{$pfx}tax_class_description` tcd2
                         WHERE tcd2.tax_class_id = tc.tax_class_id
                         LIMIT 1),
                        CONCAT('Tax Class ', tc.tax_class_id)
                    ) AS title
             FROM `{$pfx}tax_class` tc
             ORDER BY tc.tax_class_id ASC"
        );

        $this->checkpoint->init( self::KEY, count( $rows ) );
        $this->checkpoint->start( self::KEY );

        // Load any existing map so we don't re-create classes on resume.
        $map       = get_option( 'octowoo_tax_class_map', [] );
        $processed = 0;
        $skipped   = 0;
        $failed    = 0;
        $last_id   = 0;

        foreach ( $rows as $row ) {
            $oc_id = (int) $row['tax_class_id'];
            $title = $this->sanitizeText( $row['title'] );
            $slug  = sanitize_title( $title );

            if ( isset( $map[ $oc_id ] ) ) {
                // Already mapped from a previous run.
                $skipped++;
                $last_id = max( $last_id, $oc_id );
                continue;
            }

            if ( $this->isDry() ) {
                $this->logger->debug( "[DRY-RUN][tax] Would create tax class: '{$title}' (slug: '{$slug}')." );
                $map[ $oc_id ] = $slug;
                $processed++;
                $last_id = max( $last_id, $oc_id );
                continue;
            }

            // WooCommerce built-in tax classes: 'standard' (empty string), 'reduced-rate', 'zero-rate'.
            // Check by slug whether it already exists as a native or custom class.
            $existing_classes = WC_Tax::get_tax_classes();
            $slug_exists      = false;

            foreach ( $existing_classes as $class_name ) {
                if ( sanitize_title( $class_name ) === $slug ) {
                    $slug_exists = true;
                    break;
                }
            }
            // Also check the standard rate (empty slug).
            if ( $slug === 'standard' || $slug === '' ) {
                $slug_exists = true;
                $slug        = ''; // WC uses empty string for standard rate.
            }

            if ( ! $slug_exists ) {
                $result = wc_create_tax_class( $title, $slug );

                if ( is_wp_error( $result ) ) {
                    $this->logger->error(
                        "[tax] Could not create WC tax class '{$title}': " . $result->get_error_message()
                    );
                    $failed++;
                    $last_id = max( $last_id, $oc_id );
                    continue;
                }

                // wc_create_tax_class returns the class array; extract slug from it.
                $slug = $result['slug'] ?? $slug;
            }

            $map[ $oc_id ] = $slug;
            $processed++;
            $last_id = max( $last_id, $oc_id );

            $this->logger->info( "[tax] Tax class OC #{$oc_id} '{$title}' → WC slug '{$slug}'." );
        }

        // Persist the map so ProductMigrator can consume it.
        if ( ! $this->isDry() ) {
            update_option( 'octowoo_tax_class_map', $map );
        }

        $this->checkpoint->update( self::KEY, $last_id, $processed + $skipped );
        $this->checkpoint->complete( self::KEY );

        $this->logger->info(
            "[tax] Done. processed={$processed}, skipped={$skipped}, failed={$failed}. Map saved to option 'octowoo_tax_class_map'."
        );

        return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed ];
    }
}
