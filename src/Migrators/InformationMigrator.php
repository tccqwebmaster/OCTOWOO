<?php
/**
 * Information page migrator.
 *
 * Reads OpenCart static pages (oc_information + oc_information_description)
 * and creates WordPress pages.
 *
 * OpenCart tables used:
 *   oc_information             – IDs, sort_order, status
 *   oc_information_description – title, description, meta_* (per language)
 *   oc_seo_url                 – SEO slug when present
 *
 * @package OctoWoo\Migrators
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class InformationMigrator extends AbstractMigrator {

    private const KEY = 'information';

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        $resume_id = $this->checkpoint->getLastId( self::KEY );

        if ( $resume_id === PHP_INT_MAX ) {
            $this->logger->info( '[information] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        $pfx     = $this->pfx();
        $lang_id = $this->langId();

        $total_callback = function () use ( $pfx ): int {
            return $this->oc->count( 'information', 'status = 1' );
        };

        $batch_callback = function ( int $offset, int $limit ) use ( $pfx ): array {
            return $this->oc->fetchBatch(
                "SELECT information_id, sort_order
                 FROM `{$pfx}information`
                 WHERE status = 1
                 ORDER BY sort_order ASC, information_id ASC",
                [],
                $limit,
                $offset
            );
        };

        $item_callback = function ( array $row ) use ( $lang_id ): bool {
            return $this->processSinglePage( $row, $lang_id );
        };

        return $this->batch->run(
            total_callback:  $total_callback,
            batch_callback:  $batch_callback,
            item_callback:   $item_callback,
            migrator:        self::KEY,
            checkpoint:      $this->checkpoint,
            resume_after_id: $resume_id,
            id_field:        'information_id'
        );
    }

    // ── Per-item processing ───────────────────────────────────────────────────

    private function processSinglePage( array $row, int $lang_id ): bool {
        $oc_id = (int) $row['information_id'];
        $desc  = $this->fetchDescription( $oc_id, $lang_id );

        if ( ! $desc ) {
            $this->logger->warning( "[information] No description found for OC page #{$oc_id} – skipping." );
            return false;
        }

        $title = $this->sanitizeText( $desc['title'] ?? '' );
        if ( $title === '' ) {
            $this->logger->warning( "[information] Empty title for OC page #{$oc_id} – skipping." );
            return false;
        }

        // Duplicate check.
        $existing = $this->checkpoint->getWcId( self::KEY, $oc_id );
        if ( $existing ) {
            if ( $this->onDuplicate() === 'update' ) {
                return $this->updatePage( (int) $existing, $desc );
            }
            $this->logger->debug( "[information] OC #{$oc_id} already mapped → WP #{$existing} – skipping." );
            return false;
        }

        if ( $this->isDry() ) {
            $this->logger->debug( "[DRY-RUN] Would create page: {$title} (OC #{$oc_id})" );
            return true;
        }

        return $this->createPage( $oc_id, $desc );
    }

    // ── Create / Update ───────────────────────────────────────────────────────

    private function createPage( int $oc_id, array $desc ): bool {
        $title   = $this->sanitizeText( $desc['title'] ?? '' );
        $content = $this->cleanDescription( $desc['description'] ?? '' );
        $slug    = $this->fetchSeoSlug( $oc_id ) ?: $this->toSlug( $title );

        $post_id = wp_insert_post(
            [
                'post_title'     => $title,
                'post_content'   => $content,
                'post_status'    => 'publish',
                'post_type'      => 'page',
                'post_name'      => $slug,
                'menu_order'     => (int) ( $desc['sort_order'] ?? 0 ),
            ],
            true
        );

        if ( is_wp_error( $post_id ) ) {
            $this->logger->error(
                "[information] Failed to create page '{$title}': " . $post_id->get_error_message()
            );
            return false;
        }

        // Core meta.
        update_post_meta( $post_id, '_octowoo_oc_information_id', $oc_id );
        // Generic mapping key used across migrators.
        update_post_meta( $post_id, '_octowoo_oc_id', $oc_id );

        // Yoast SEO meta.
        if ( ! empty( $desc['meta_title'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_title', $this->sanitizeText( $desc['meta_title'] ) );
        }
        if ( ! empty( $desc['meta_description'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', $this->sanitizeText( $desc['meta_description'] ) );
        }
        if ( ! empty( $desc['meta_keyword'] ) ) {
            update_post_meta( $post_id, 'octowoo_meta_keyword', $this->sanitizeText( $desc['meta_keyword'] ) );
        }

        // Store secondary-language data as post meta (consumed by WpmlIntegration).
        $this->storeSecondaryLanguage( $post_id, $oc_id );

        // Record in ID map.
        $this->checkpoint->saveIdMap( self::KEY, $oc_id, $post_id );

        $this->logger->info( "[information] Created WP page #{$post_id} ← OC #{$oc_id}: '{$title}'" );

        /**
         * Fires after a WordPress page has been created from an OpenCart information page.
         *
         * @param int   $oc_id   OpenCart information_id.
         * @param int   $post_id WordPress post ID.
         * @param array $desc    Raw OC description row (primary language).
         * @param array $config  Full plugin config.
         */
        do_action( 'octowoo_after_migrate_information', $oc_id, $post_id, $desc, $this->config );

        return true;
    }

    private function updatePage( int $post_id, array $desc ): bool {
        wp_update_post( [
            'ID'           => $post_id,
            'post_title'   => $this->sanitizeText( $desc['title'] ?? '' ),
            'post_content' => $this->cleanDescription( $desc['description'] ?? '' ),
        ] );

        if ( ! empty( $desc['meta_title'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_title', $this->sanitizeText( $desc['meta_title'] ) );
        }
        if ( ! empty( $desc['meta_description'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', $this->sanitizeText( $desc['meta_description'] ) );
        }

        $this->logger->debug( "[information] Updated WP page #{$post_id}." );
        return true;
    }

    // ── Data fetchers ─────────────────────────────────────────────────────────

    /**
     * Fetch description row, falling back to any language when primary not found.
     */
    private function fetchDescription( int $oc_id, int $lang_id ): ?array {
        $pfx  = $this->pfx();
        $desc = $this->oc->fetchRow(
            "SELECT id.title, id.description, id.meta_title, id.meta_description, id.meta_keyword,
                    i.sort_order
             FROM `{$pfx}information_description` id
             JOIN `{$pfx}information` i USING (information_id)
             WHERE id.information_id = ? AND id.language_id = ?",
            [ $oc_id, $lang_id ]
        );

        if ( ! $desc ) {
            $desc = $this->oc->fetchRow(
                "SELECT id.title, id.description, id.meta_title, id.meta_description, id.meta_keyword,
                        i.sort_order
                 FROM `{$pfx}information_description` id
                 JOIN `{$pfx}information` i USING (information_id)
                 WHERE id.information_id = ?
                 ORDER BY id.language_id ASC LIMIT 1",
                [ $oc_id ]
            );
        }

        return $desc ?: null;
    }

    /**
     * Attempt to retrieve a clean slug from oc_seo_url.
     */
    private function fetchSeoSlug( int $oc_id ): string {
        $pfx = $this->pfx();
        try {
            $row = $this->oc->fetchRow(
                "SELECT keyword FROM `{$pfx}seo_url`
                 WHERE query = ? AND store_id = 0 LIMIT 1",
                [ "information_id={$oc_id}" ]
            );
            return $row ? $this->toSlug( $row['keyword'] ) : '';
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    /**
     * Fetch secondary-language description and store on the WP post for later use by WpmlIntegration.
     */
    private function storeSecondaryLanguage( int $post_id, int $oc_id ): void {
        $lang2 = $this->langIdSecondary();
        if ( ! $lang2 ) {
            return;
        }

        $pfx  = $this->pfx();
        $desc = $this->oc->fetchRow(
            "SELECT title, description, meta_title, meta_description
             FROM `{$pfx}information_description`
             WHERE information_id = ? AND language_id = ?",
            [ $oc_id, $lang2 ]
        );

        if ( ! $desc ) {
            // Fallback: use first non-primary language row when configured
            // secondary language ID is missing/misconfigured.
            $desc = $this->oc->fetchRow(
                "SELECT title, description, meta_title, meta_description, language_id
                 FROM `{$pfx}information_description`
                 WHERE information_id = ? AND language_id <> ?
                 ORDER BY language_id ASC
                 LIMIT 1",
                [ $oc_id, $this->langId() ]
            );

            if ( $desc ) {
                $fallback_lang_id = (int) ( $desc['language_id'] ?? 0 );
                $this->logger->warning( "[information] Secondary language ID {$lang2} not found for OC #{$oc_id}; using language_id={$fallback_lang_id} as fallback." );
            }
        }

        if ( ! $desc ) {
            return;
        }

        update_post_meta( $post_id, '_octowoo_title_ar',    $this->sanitizeText( $desc['title']            ?? '' ) );
        update_post_meta( $post_id, '_octowoo_desc_ar',     $this->cleanDescription( $desc['description']  ?? '' ) );
        update_post_meta( $post_id, '_octowoo_metatitle_ar',$this->sanitizeText( $desc['meta_title']       ?? '' ) );
        update_post_meta( $post_id, '_octowoo_metadesc_ar', $this->sanitizeText( $desc['meta_description'] ?? '' ) );
    }
}
