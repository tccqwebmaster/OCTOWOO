<?php
/**
 * Category migrator.
 *
 * Reads OpenCart categories (including nested parent–child hierarchy) and
 * creates WooCommerce product_cat terms.
 *
 * OpenCart tables used:
 *   oc_category             – IDs, parent_id, status
 *   oc_category_description – name, description, meta fields (per language)
 *   oc_seo_url              – SEO slug if available
 *
 * Algorithm:
 *   1. Fetch all OC categories sorted by parent_id ASC so parents always
 *      come before their children.
 *   2. For each category, look up:
 *        - Primary language description (English, lang_id=1)
 *        - Secondary language description (Arabic, lang_id=2)
 *   3. Resolve the WC parent term_id from the checkpoint ID-map.
 *   4. Create / update the product_cat term.
 *   5. Store the OC→WC ID mapping in the checkpoint manager.
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class CategoryMigrator extends AbstractMigrator {

    /** Migrator key used in checkpoints/logs (must match MigrationManager key). */
    private const KEY = 'categories';

    /** Entity key used in the OC->WC ID map (kept stable for cross-migrator lookups). */
    private const MAP_KEY = 'category';

    /** @var ImageMigrator Shared image importer instance. */
    private ImageMigrator $imageMigrator;

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        $this->imageMigrator = new ImageMigrator(
            $this->oc, $this->logger, $this->checkpoint, $this->batch, $this->config
        );

        $pfx         = $this->pfx();
        $lang_id     = $this->langId();
        $resume_id   = $this->checkpoint->getLastId( self::KEY );

        if ( $resume_id === PHP_INT_MAX ) {
            $this->logger->info( '[categories] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        // Pre-fetch all descriptions so we avoid N+1 queries.
        $descriptions = $this->fetchAllDescriptions();
        $seo_urls     = $this->fetchSeoUrls();

        $total_callback = function () use ( $pfx ): int {
            return $this->oc->count( 'category', 'status = 1' );
        };

        $batch_callback = function ( int $offset, int $limit ) use ( $pfx ): array {
            return $this->oc->fetchBatch(
                "SELECT category_id, parent_id, sort_order, image
                 FROM `{$pfx}category`
                 WHERE status = 1
                 ORDER BY category_id ASC, parent_id ASC, sort_order ASC",
                [],
                $limit,
                $offset
            );
        };

        $item_callback = function ( array $row ) use ( $descriptions, $seo_urls, $lang_id ): bool {
            return $this->processCategory( $row, $descriptions, $seo_urls, $lang_id );
        };

        // If WPML is active, switch to primary language so that wp_insert_term()
        // calls during this batch are auto-assigned to the correct language by WPML.
        $this->wpmlSwitchToPrimary();

        $result = $this->batch->run(
            total_callback:   $total_callback,
            batch_callback:   $batch_callback,
            item_callback:    $item_callback,
            migrator:         self::KEY,
            checkpoint:       $this->checkpoint,
            resume_after_id:  $resume_id,
            id_field:         'category_id'
        );

        $this->wpmlRestoreLanguage();

        return $result;
    }

    // ── Per-item processing ───────────────────────────────────────────────────

    private function processCategory(
        array $row,
        array $descriptions,
        array $seo_urls,
        int   $lang_id
    ): bool {
        $oc_id    = (int) $row['category_id'];
        $oc_parent = (int) $row['parent_id'];

        // Description for primary language.
        $desc = $descriptions[ $oc_id ][ $lang_id ] ?? $descriptions[ $oc_id ][ array_key_first( $descriptions[ $oc_id ] ?? [] ) ] ?? null;

        if ( ! $desc ) {
            $this->logger->warning( "[categories] No description found for OC category #{$oc_id} – skipping." );
            return false;
        }

        $name        = $this->sanitizeName( $desc['name'] ?? '' );
        $description = $this->cleanDescription( $desc['description'] ?? '' );

        if ( $name === '' ) {
            $this->logger->warning( "[categories] Empty name for OC #{$oc_id} – skipping." );
            return false;
        }

        // Secondary language description (Arabic).
        $lang_id_sec = $this->langIdSecondary();
        $desc_ar     = ( $lang_id_sec > 0 ) ? ( $descriptions[ $oc_id ][ $lang_id_sec ] ?? [] ) : [];
        if ( empty( $desc_ar ) && ! empty( $descriptions[ $oc_id ] ) ) {
            // Fallback: use first non-primary language row when secondary ID
            // is missing/misconfigured, so multilingual pass still has Arabic data.
            foreach ( $descriptions[ $oc_id ] as $candidate_lang_id => $candidate_desc ) {
                if ( (int) $candidate_lang_id !== $lang_id ) {
                    $desc_ar = $candidate_desc;
                    $this->logger->warning( "[categories] Secondary language ID {$lang_id_sec} not found for OC #{$oc_id}; using language_id={$candidate_lang_id} as fallback." );
                    break;
                }
            }
        }

        // Category thumbnail image path.
        $image = $row['image'] ?? '';

        // Resolve SEO slug.
        $slug = $seo_urls[ $oc_id ] ?? $this->toSlug( $name );

        // Resolve WC parent term ID.
        $wc_parent = 0;
        $pending_parent_oc_id = 0;
        if ( $oc_parent > 0 ) {
            $wc_parent = (int) ( $this->checkpoint->getWcId( self::MAP_KEY, $oc_parent ) ?? 0 );
            if ( $wc_parent <= 0 ) {
                // Parent can be imported later; keep this category at root for now,
                // then re-parent automatically once the parent mapping is available.
                $pending_parent_oc_id = $oc_parent;
                $this->logger->warning( "[categories] Parent OC #{$oc_parent} not mapped yet for OC #{$oc_id}; creating as root temporarily and scheduling re-parent." );
                $wc_parent = 0;
            } else {
                // Validate the resolved parent is a real, usable term — it could be a
                // stale id_map entry pointing to a now-deleted term, or a WPML stub
                // that reused the old ID after AUTO_INCREMENT reset.
                $parent_check = get_term( $wc_parent, 'product_cat' );
                if ( ! $parent_check || is_wp_error( $parent_check ) || trim( (string) $parent_check->name ) === '' ) {
                    $this->logger->warning( "[categories] Resolved parent WC #{$wc_parent} (OC #{$oc_parent}) is missing or a WPML stub; scheduling re-parent for OC #{$oc_id}." );
                    $pending_parent_oc_id = $oc_parent;
                    $wc_parent = 0;
                }
            }
        }

        // Duplicate check.
        $existing_wc_id = $this->checkpoint->getWcId( self::MAP_KEY, $oc_id );

        // Guard: if the found WC term has an empty name it is almost certainly a
        // WPML Arabic stub (WPML copies _octowoo_oc_id via field-sync).  Treat it
        // as "not found" so we look up – or create – the real English primary term.
        if ( $existing_wc_id ) {
            $check_term = get_term( $existing_wc_id, 'product_cat' );
            if ( ! $check_term || is_wp_error( $check_term ) || trim( (string) $check_term->name ) === '' ) {
                $this->logger->warning(
                    "[categories] WC #{$existing_wc_id} (OC #{$oc_id}) has no usable name – likely a WPML stub; treating as new."
                );
                $existing_wc_id = null;
            }
        }

        // Last-resort guard: even if id_map and OC-meta are both empty (e.g. after
        // a Reset, or when the store already had a matching category), look up the
        // WC taxonomy directly by name + parent to prevent creating a real duplicate.
        if ( ! $existing_wc_id ) {
            $existing_term = term_exists( $name, 'product_cat', $wc_parent );
            if ( ! empty( $existing_term['term_id'] ) ) {
                $existing_wc_id = (int) $existing_term['term_id'];
                // Backfill the map so future lookups are instant.
                $this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $existing_wc_id );
                $this->logger->info( "[categories] Found existing WC term #{$existing_wc_id} by name for OC #{$oc_id} – backfilled id_map." );
            }
        }

        if ( $existing_wc_id ) {
            if ( $this->onDuplicate() === 'update' ) {
                return $this->updateCategory( $existing_wc_id, $name, $slug, $description, $wc_parent, $oc_id, $desc, $desc_ar, $image, $pending_parent_oc_id );
            }

            if ( ! $this->isDry() ) {
                if ( $pending_parent_oc_id > 0 ) {
                    update_term_meta( $existing_wc_id, '_octowoo_pending_parent_oc_id', $pending_parent_oc_id );
                } else {
                    delete_term_meta( $existing_wc_id, '_octowoo_pending_parent_oc_id' );
                }
                $this->reparentPendingChildren( $oc_id, $existing_wc_id );
            }

            $this->logger->debug( "[categories] Duplicate OC #{$oc_id} → WC #{$existing_wc_id} – skipping." );
            return false; // 'skip'
        }

        if ( $this->isDry() ) {
            $this->logger->debug( "[DRY-RUN] Would create category: {$name} (slug: {$slug}, parent WC: {$wc_parent})" );
            return true;
        }

        return $this->createCategory( $name, $slug, $description, $wc_parent, $oc_id, $desc, $desc_ar, $image, $pending_parent_oc_id );
    }

    // ── Create / update ───────────────────────────────────────────────────────

    private function createCategory(
        string $name,
        string $slug,
        string $description,
        int    $wc_parent,
        int    $oc_id,
        array  $desc,
        array  $desc_ar = [],
        string $image   = '',
        int    $pending_parent_oc_id = 0
    ): bool {
        // Ensure slug uniqueness.
        $slug = $this->uniqueSlug( $slug, 0 );

        $result = wp_insert_term(
            $name,
            'product_cat',
            [
                'slug'        => $slug,
                'description' => $description,
                'parent'      => $wc_parent,
            ]
        );

        if ( is_wp_error( $result ) ) {
            // Term already exists: use the term_id carried in the WP_Error data
            // (wp_insert_term embeds the existing term_id as get_error_data()).
            // NOTE: do NOT look up by the uniquified $slug — the existing term
            // almost certainly has the *original* slug, not the suffixed one.
            if ( $result->get_error_code() === 'term_exists' ) {
                $existing_id = (int) $result->get_error_data( 'term_exists' );
                $existing    = $existing_id > 0 ? get_term( $existing_id, 'product_cat' ) : null;

                if ( $existing && ! is_wp_error( $existing ) ) {
                    $this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $existing->term_id );
                    $this->addTermMeta( $existing->term_id, $oc_id, $desc, $desc_ar, $image, $pending_parent_oc_id );
                    $this->logger->info( "[categories] Linked existing WC term #{$existing->term_id} to OC #{$oc_id} (term_exists)." );
                    $this->reparentPendingChildren( $oc_id, (int) $existing->term_id );
                    return true;
                }
            }

            $this->logger->error(
                "[categories] wp_insert_term failed for OC #{$oc_id}: " . $result->get_error_message(),
                [ 'name' => $name, 'slug' => $slug ]
            );
            return false;
        }

        $wc_term_id = (int) $result['term_id'];

        $this->addTermMeta( $wc_term_id, $oc_id, $desc, $desc_ar, $image, $pending_parent_oc_id );
        $this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $wc_term_id );
        $this->reparentPendingChildren( $oc_id, $wc_term_id );

        $this->logger->info( "[categories] Created WC term #{$wc_term_id} from OC #{$oc_id}: \"{$name}\"" );
        return true;
    }

    private function updateCategory(
        int    $wc_term_id,
        string $name,
        string $slug,
        string $description,
        int    $wc_parent,
        int    $oc_id,
        array  $desc,
        array  $desc_ar = [],
        string $image   = '',
        int    $pending_parent_oc_id = 0
    ): bool {
        $existing_term = get_term( $wc_term_id, 'product_cat' );
        $safe_name = $this->sanitizeName( $name );
        if ( $safe_name === '' && $existing_term && ! is_wp_error( $existing_term ) ) {
            $safe_name = $this->sanitizeName( (string) $existing_term->name );
        }

        $update_args = [
            'slug'        => $slug,
            'description' => $description,
            'parent'      => $wc_parent,
        ];
        if ( $safe_name !== '' ) {
            $update_args['name'] = $safe_name;
        }

        $result = wp_update_term(
            $wc_term_id,
            'product_cat',
            $update_args
        );

        if ( is_wp_error( $result ) ) {
            // Some catalogs contain malformed names that WP normalizes to an
            // empty value. Retry without forcing "name" so WP keeps existing.
            $error_code    = (string) $result->get_error_code();
            $error_message = (string) $result->get_error_message();
            $is_empty_term = $error_code === 'empty_term_name'
                || stripos( $error_code, 'empty' ) !== false
                || stripos( $error_message, 'empty term' ) !== false;

            if ( $is_empty_term ) {
                $retry_args = [
                    'slug'        => $slug,
                    'description' => $description,
                    'parent'      => $wc_parent,
                ];

                $retry = wp_update_term( $wc_term_id, 'product_cat', $retry_args );
                if ( ! is_wp_error( $retry ) ) {
                    $this->addTermMeta( $wc_term_id, $oc_id, $desc, $desc_ar, $image, $pending_parent_oc_id );
                    $this->reparentPendingChildren( $oc_id, $wc_term_id );
                    $this->logger->warning( "[categories] Name update skipped for WC #{$wc_term_id} (OC #{$oc_id}) due to empty normalized term name; kept existing term name." );
                    return true;
                }
            }

            $this->logger->error(
                "[categories] wp_update_term failed for WC #{$wc_term_id} (OC #{$oc_id}): " . $result->get_error_message()
            );
            return false;
        }

        $this->addTermMeta( $wc_term_id, $oc_id, $desc, $desc_ar, $image, $pending_parent_oc_id );
        $this->reparentPendingChildren( $oc_id, $wc_term_id );
        $this->logger->info( "[categories] Updated WC term #{$wc_term_id} (OC #{$oc_id})." );
        return true;
    }

    // ── Term meta ─────────────────────────────────────────────────────────────

    private function addTermMeta( int $wc_term_id, int $oc_id, array $desc, array $desc_ar = [], string $image = '', int $pending_parent_oc_id = 0 ): void {
        // Store OC source ID for cross-referencing.
        update_term_meta( $wc_term_id, '_octowoo_oc_id', $oc_id );

        if ( $pending_parent_oc_id > 0 ) {
            update_term_meta( $wc_term_id, '_octowoo_pending_parent_oc_id', $pending_parent_oc_id );
        } else {
            delete_term_meta( $wc_term_id, '_octowoo_pending_parent_oc_id' );
        }

        // Yoast / Rank Math SEO meta.
        if ( ! empty( $desc['meta_title'] ) ) {
            update_term_meta( $wc_term_id, '_yoast_wpseo_title', $this->sanitizeText( $desc['meta_title'] ) );
        }
        if ( ! empty( $desc['meta_description'] ) ) {
            update_term_meta( $wc_term_id, '_yoast_wpseo_metadesc', $this->sanitizeText( $desc['meta_description'] ) );
        }
        if ( ! empty( $desc['meta_keyword'] ) ) {
            update_term_meta( $wc_term_id, '_yoast_wpseo_focuskw', $this->sanitizeText( $desc['meta_keyword'] ) );
        }

        // Secondary language data for WPML / Polylang translation pass.
        if ( ! empty( $desc_ar ) ) {
            update_term_meta( $wc_term_id, '_octowoo_name_ar',        $this->sanitizeName( $desc_ar['name']             ?? '' ) );
            update_term_meta( $wc_term_id, '_octowoo_description_ar', $this->cleanDescription( $desc_ar['description']  ?? '' ) );
            update_term_meta( $wc_term_id, '_octowoo_metatitle_ar',   $this->sanitizeText( $desc_ar['meta_title']       ?? '' ) );
            update_term_meta( $wc_term_id, '_octowoo_metadesc_ar',    $this->sanitizeText( $desc_ar['meta_description'] ?? '' ) );
        }

        // Category thumbnail: import the OC image and assign as WC thumbnail.
        if ( ! empty( $image ) && ! $this->isDry() ) {
            $attachment_id = $this->imageMigrator->importByOcPath( $image );
            if ( $attachment_id && $attachment_id > 0 ) {
                update_term_meta( $wc_term_id, 'thumbnail_id', $attachment_id );
            }
        }
    }

    /**
     * Re-parent any categories that were waiting on this OC parent ID.
     */
    private function reparentPendingChildren( int $resolved_parent_oc_id, int $resolved_parent_wc_id ): void {
        global $wpdb;

        $termmeta_table      = $wpdb->termmeta;
        $term_taxonomy_table = $wpdb->term_taxonomy;

        $term_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT tm.term_id
                 FROM `{$termmeta_table}` tm
                 INNER JOIN `{$term_taxonomy_table}` tt ON tt.term_id = tm.term_id
                 WHERE tm.meta_key = %s
                   AND tm.meta_value = %s
                   AND tt.taxonomy = 'product_cat'",
                '_octowoo_pending_parent_oc_id',
                (string) $resolved_parent_oc_id
            )
        );

        if ( empty( $term_ids ) ) {
            return;
        }

        foreach ( $term_ids as $term_id_raw ) {
            $child_term_id = (int) $term_id_raw;

            if ( $child_term_id <= 0 || $child_term_id === $resolved_parent_wc_id ) {
                continue;
            }

            $updated = wp_update_term(
                $child_term_id,
                'product_cat',
                [ 'parent' => $resolved_parent_wc_id ]
            );

            if ( is_wp_error( $updated ) ) {
                $this->logger->warning(
                    "[categories] Failed to re-parent WC #{$child_term_id} under WC #{$resolved_parent_wc_id}: " . $updated->get_error_message()
                );
                continue;
            }

            delete_term_meta( $child_term_id, '_octowoo_pending_parent_oc_id' );
            $this->logger->info( "[categories] Re-parented WC #{$child_term_id} under WC #{$resolved_parent_wc_id}." );
        }
    }

    // ── Data fetching helpers ─────────────────────────────────────────────────

    /**
     * Fetch all category descriptions keyed by [category_id][language_id].
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function fetchAllDescriptions(): array {
        $pfx  = $this->pfx();
        $rows = $this->oc->fetchAll(
            "SELECT category_id, language_id, name, description, meta_title, meta_description, meta_keyword
             FROM `{$pfx}category_description`"
        );

        $indexed = [];
        foreach ( $rows as $row ) {
            $indexed[ (int) $row['category_id'] ][ (int) $row['language_id'] ] = $row;
        }
        return $indexed;
    }

    /**
     * Fetch SEO slugs for categories from oc_seo_url.
     * Returns an array of [oc_category_id => slug].
     *
     * @return array<int, string>
     */
    private function fetchSeoUrls(): array {
        $pfx = $this->pfx();

        // Check if the seo_url table exists (OpenCart 3.x).
        $has_seo = $this->oc->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = '{$pfx}seo_url'"
        );

        if ( ! $has_seo ) {
            return [];
        }

        $rows = $this->oc->fetchAll(
            "SELECT keyword, query
             FROM `{$pfx}seo_url`
             WHERE query LIKE 'category_id=%'
               AND store_id = 0
               AND language_id = ?",
            [ $this->langId() ]
        );

        $map = [];
        foreach ( $rows as $row ) {
            if ( preg_match( '/^category_id=(\d+)$/', $row['query'], $m ) ) {
                $map[ (int) $m[1] ] = sanitize_title( $row['keyword'] );
            }
        }
        return $map;
    }

    /**
     * Generate a unique slug within the product_cat taxonomy.
     */
    private function uniqueSlug( string $slug, int $term_id ): string {
        $existing = get_term_by( 'slug', $slug, 'product_cat' );

        if ( ! $existing || (int) $existing->term_id === $term_id ) {
            return $slug;
        }

        $i = 1;
        do {
            $candidate = $slug . '-' . $i;
            $existing  = get_term_by( 'slug', $candidate, 'product_cat' );
            $i++;
        } while ( $existing && (int) $existing->term_id !== $term_id );

        return $candidate;
    }
}
