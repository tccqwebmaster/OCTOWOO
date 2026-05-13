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
 *        - Secondary language description (lang_id=2)
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

        // Guard: if the migrator was already completed (last_id = PHP_INT_MAX),
        // bail early so the MigrationManager can advance to the next migrator.
        $resume_id = $this->checkpoint->getLastId( self::KEY );
        if ( $resume_id === PHP_INT_MAX ) {
            $this->logger->info( '[categories] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        // Pre-fetch all descriptions so we avoid N+1 queries.
        $descriptions = $this->fetchAllDescriptions();
        $seo_urls     = $this->fetchSeoUrls();

        // Pre-fetch and topologically sort all categories (parents before children,
        // siblings ordered by sort_order) so that parent terms always exist in WC
        // before their children are processed — eliminating the need for the
        // pending-parent fallback in the common case.
        $sorted_rows = $this->fetchAllCategoriesTopological();

        $total_callback = function () use ( $sorted_rows ): int {
            return count( $sorted_rows );
        };

        // Slice the pre-sorted in-memory array; avoids per-batch SQL and keeps
        // the parent-before-child ordering stable across chunk boundaries.
        $batch_callback = function ( int $offset, int $limit ) use ( $sorted_rows ): array {
            return array_slice( $sorted_rows, $offset, $limit );
        };

        $item_callback = function ( array $row ) use ( $descriptions, $seo_urls, $lang_id ): bool {
            return $this->processCategory( $row, $descriptions, $seo_urls, $lang_id );
        };

        // If WPML is active, switch to primary language so that wp_insert_term()
        // calls during this batch are auto-assigned to the correct language by WPML.
        $this->wpmlSwitchToPrimary();

        // resume_after_id is intentionally 0: we use OFFSET-based slicing on the
        // stable pre-sorted array, so ID-based skipping would be incorrect here.
        // In chunk mode BatchProcessor already uses processed_count as the offset.
        $result = $this->batch->run(
            total_callback:   $total_callback,
            batch_callback:   $batch_callback,
            item_callback:    $item_callback,
            migrator:         self::KEY,
            checkpoint:       $this->checkpoint,
            resume_after_id:  0,
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

        // Secondary language description.
        $lang_id_sec = $this->langIdSecondary();
        $sec_desc    = ( $lang_id_sec > 0 ) ? ( $descriptions[ $oc_id ][ $lang_id_sec ] ?? [] ) : [];
        if ( empty( $sec_desc ) && ! empty( $descriptions[ $oc_id ] ) ) {
            // Fallback: use first non-primary language row when secondary ID
            // is missing/misconfigured, so multilingual pass still has secondary data.
            foreach ( $descriptions[ $oc_id ] as $candidate_lang_id => $candidate_desc ) {
                if ( (int) $candidate_lang_id !== $lang_id ) {
                    $sec_desc = $candidate_desc;
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
        // secondary-language stub (WPML copies _octowoo_oc_id via field-sync).  Treat it
        // as "not found" so we look up – or create – the real primary-language term.
        if ( $existing_wc_id ) {
            $check_term = get_term( $existing_wc_id, 'product_cat' );
            if ( ! $check_term || is_wp_error( $check_term ) || trim( (string) $check_term->name ) === '' ) {
                $this->logger->warning(
                    "[categories] WC #{$existing_wc_id} (OC #{$oc_id}) has no usable name – likely a WPML stub; treating as new."
                );
                $existing_wc_id = null;
            }
        }

        // Robust duplicate guard — three independent lookups in priority order:
        // 1. By _octowoo_oc_id term meta (most reliable — survives parent changes).
        // 2. By name + correct parent (exact parent match).
        // 3. By name across ANY parent (catches moves/re-parents).
        if ( ! $existing_wc_id ) {
            global $wpdb;
            // Check 1: term meta lookup — ignores parent so survives category re-parenting.
            $by_meta = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "SELECT tm.term_id FROM {$wpdb->termmeta} tm
                  JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                 WHERE tm.meta_key = '_octowoo_oc_id' AND tm.meta_value = %s
                   AND tt.taxonomy = 'product_cat' LIMIT 1",
                (string) $oc_id
            ) );
            if ( $by_meta > 0 ) {
                $existing_wc_id = $by_meta;
                $this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $existing_wc_id );
                $this->logger->info( "[categories] Found existing WC term #{$existing_wc_id} by _octowoo_oc_id meta for OC #{$oc_id} – backfilled id_map." );
            }
        }

        if ( ! $existing_wc_id ) {
            // Check 2: name + exact parent.
            $existing_term = term_exists( $name, 'product_cat', $wc_parent );
            if ( ! empty( $existing_term['term_id'] ) ) {
                $existing_wc_id = (int) $existing_term['term_id'];
                $this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $existing_wc_id );
                $this->logger->info( "[categories] Found WC term #{$existing_wc_id} by name+parent for OC #{$oc_id} – backfilled id_map." );
            }
        }

        if ( ! $existing_wc_id && $wc_parent === 0 ) {
            // Check 3: name with ANY parent (catches terms that were reparented
            // after initial creation with pending_parent_oc_id).
            $existing_term_any = term_exists( $name, 'product_cat' );
            if ( ! empty( $existing_term_any['term_id'] ) ) {
                $existing_wc_id = (int) $existing_term_any['term_id'];
                $this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $existing_wc_id );
                $this->logger->info( "[categories] Found WC term #{$existing_wc_id} by name (any parent) for OC #{$oc_id} – backfilled id_map." );
            }
        }

        if ( $existing_wc_id ) {
            if ( $this->onDuplicate() === 'update' ) {
                return $this->updateCategory( $existing_wc_id, $name, $slug, $description, $wc_parent, $oc_id, $desc, $sec_desc, $image, $pending_parent_oc_id );
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

        return $this->createCategory( $name, $slug, $description, $wc_parent, $oc_id, $desc, $sec_desc, $image, $pending_parent_oc_id );
    }

    // ── Create / update ───────────────────────────────────────────────────────

    private function createCategory(
        string $name,
        string $slug,
        string $description,
        int    $wc_parent,
        int    $oc_id,
        array  $desc,
        array  $sec_desc = [],
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
                    $this->addTermMeta( $existing->term_id, $oc_id, $desc, $sec_desc, $image, $pending_parent_oc_id );
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

        $this->addTermMeta( $wc_term_id, $oc_id, $desc, $sec_desc, $image, $pending_parent_oc_id );
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
        array  $sec_desc = [],
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
                    $this->addTermMeta( $wc_term_id, $oc_id, $desc, $sec_desc, $image, $pending_parent_oc_id );
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

        $this->addTermMeta( $wc_term_id, $oc_id, $desc, $sec_desc, $image, $pending_parent_oc_id );
        $this->reparentPendingChildren( $oc_id, $wc_term_id );
        $this->logger->info( "[categories] Updated WC term #{$wc_term_id} (OC #{$oc_id})." );
        return true;
    }

    // ── Term meta ─────────────────────────────────────────────────────────────

    private function addTermMeta( int $wc_term_id, int $oc_id, array $desc, array $sec_desc = [], string $image = '', int $pending_parent_oc_id = 0 ): void {
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
        // Rank Math SEO (auto-detected – safe to call even when Rank Math is not active).
        \OctoWoo\Core\RankMathHelper::writeTermMeta(
            $wc_term_id,
            'product_cat',
            $this->sanitizeText( $desc['meta_title']       ?? '' ),
            $this->sanitizeText( $desc['meta_description'] ?? '' ),
            $this->sanitizeText( $desc['meta_keyword']     ?? '' )
        );

        // Secondary-language data for WPML / Polylang translation pass.
        if ( ! empty( $sec_desc ) ) {
            $sfx = $this->secLangSuffix();
            update_term_meta( $wc_term_id, '_octowoo_name' . $sfx,        $this->sanitizeName( $sec_desc['name']             ?? '' ) );
            update_term_meta( $wc_term_id, '_octowoo_description' . $sfx, $this->cleanDescription( $sec_desc['description']  ?? '' ) );
            update_term_meta( $wc_term_id, '_octowoo_metatitle' . $sfx,   $this->sanitizeText( $sec_desc['meta_title']       ?? '' ) );
            update_term_meta( $wc_term_id, '_octowoo_metadesc' . $sfx,    $this->sanitizeText( $sec_desc['meta_description'] ?? '' ) );
            update_term_meta( $wc_term_id, '_octowoo_metakw' . $sfx,      $this->sanitizeText( $sec_desc['meta_keyword']     ?? '' ) );
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
     * Fetch all active categories from OpenCart and return them sorted so that
     * every parent category appears before all of its children (topological /
     * BFS order). Within each sibling group, items are ordered by sort_order ASC
     * then category_id ASC for a deterministic, store-consistent sequence.
     *
     * This pre-sort is done in PHP once so that BatchProcessor's OFFSET-based
     * slicing always hands a parent to processCategory() before its children,
     * regardless of how the IDs were assigned in the source database.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllCategoriesTopological(): array {
        // Cache the sorted result in a short-lived transient so that repeated
        // calls within a chunked AJAX migration (one call per HTTP request) do
        // not re-query the OC database and do not repeat orphan warnings on every chunk.
        // Key incorporates resume_id so a fresh run (resume_id=0) always fetches fresh data.
        $resume_id     = $this->checkpoint->getLastId( self::KEY );
        $transient_key = 'octowoo_cat_all_' . md5( $this->pfx() . '_' . ( $resume_id === 0 ? 'fresh' : 'resume' ) );
        $cached = ( $resume_id > 0 ) ? get_transient( $transient_key ) : false;
        if ( is_array( $cached ) && ! empty( $cached ) ) {
            return $cached;
        }

        $pfx  = $this->pfx();
        // Fetch ALL categories regardless of status so the migrated count matches
        // what the source scanner reports.  Inactive categories are imported as
        // hidden WooCommerce terms; the store owner can manage visibility after.
        $rows = $this->oc->fetchAll(
            "SELECT category_id, parent_id, sort_order, image
             FROM `{$pfx}category`"
        );

        // Index rows and build a parent→children map.
        $by_id    = [];
        $children = []; // parent_id (int) → list of child category_ids (int[])
        foreach ( $rows as $row ) {
            $id  = (int) $row['category_id'];
            $pid = (int) $row['parent_id'];
            $by_id[ $id ]       = $row;
            $children[ $pid ][] = $id;
        }

        // Sort each sibling group: sort_order ASC, then category_id ASC.
        foreach ( $children as &$sibling_ids ) {
            usort( $sibling_ids, function ( int $a, int $b ) use ( $by_id ): int {
                $diff = (int) ( $by_id[ $a ]['sort_order'] ?? 0 ) - (int) ( $by_id[ $b ]['sort_order'] ?? 0 );
                return $diff !== 0 ? $diff : ( $a - $b );
            } );
        }
        unset( $sibling_ids );

        // BFS from root categories (parent_id = 0) to guarantee parent-before-child.
        $sorted  = [];
        $visited = [];
        $queue   = $children[0] ?? [];

        while ( ! empty( $queue ) ) {
            $id = array_shift( $queue );

            if ( isset( $visited[ $id ] ) ) {
                continue; // Guard against circular references.
            }
            $visited[ $id ] = true;

            if ( isset( $by_id[ $id ] ) ) {
                $sorted[] = $by_id[ $id ];
            }

            // Append this category's children (already sort-ordered) to the queue.
            foreach ( $children[ $id ] ?? [] as $child_id ) {
                if ( ! isset( $visited[ $child_id ] ) ) {
                    $queue[] = $child_id;
                }
            }
        }

        // Safety: append any orphans (parent_id references a missing/disabled category).
        foreach ( $by_id as $id => $row ) {
            if ( ! isset( $visited[ $id ] ) ) {
                $sorted[] = $row;
                $this->logger->warning( "[categories] Orphan category OC #{$id} (parent_id={$row['parent_id']} not found) – appended at end." );
            }
        }

        // Store in transient so subsequent chunks skip the re-sort and the
        // orphan warnings are emitted only once per migration run.
        set_transient( $transient_key, $sorted, 2 * HOUR_IN_SECONDS );

        return $sorted;
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
