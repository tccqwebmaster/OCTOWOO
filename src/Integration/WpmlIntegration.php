<?php
/**
 * WPML / Polylang multilingual integration.
 *
 * This class runs as a post-migration pass (registered in MIGRATOR_ORDER as
 * the last step) that:
 *
 *  1. Queries every product, category, and page that was already migrated
 *     (from the octowoo_id_map table).
 *  2. Fetches the secondary-language data stored on each WP entity by the
 *     primary migrator (e.g. _octowoo_title_ar, _octowoo_description_ar).
 *  3. Creates a translated WP post / term in the secondary language.
 *  4. Links the primary and secondary entities using WPML or Polylang APIs.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * Supported multilingual plugins:
 *   • WPML (Multilingual CMS) – SitePress integration via action hooks.
 *   • Polylang / Polylang Pro  – PLL_* function integration.
 *
 * When neither plugin is active the integration is a no-op; it will log a
 * warning and return immediately.
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Coverage:
 *   ✔ Products (post_type = product)
 *   ✔ Product categories (taxonomy = product_cat)
 *   ✔ Information pages   (post_type = page)
 *
 * Config keys used:
 *   multilingual.enabled            – master switch
 *   multilingual.primary_locale     – e.g. "en"   (WPML language code)
 *   multilingual.secondary_locale   – e.g. "ar"
 *   multilingual.use_wpml           – true to prefer WPML even if Polylang is installed
 *   multilingual.use_polylang       – true to prefer Polylang
 *
 * @package OctoWoo\Integration
 */

namespace OctoWoo\Integration;

use OctoWoo\Core\DatabaseConnector;
use OctoWoo\Core\Logger;
use OctoWoo\Core\CheckpointManager;
use OctoWoo\Core\BatchProcessor;
use OctoWoo\Migrators\AbstractMigrator;

defined( 'ABSPATH' ) || exit;

class WpmlIntegration extends AbstractMigrator {

    private const KEY = 'multilingual';

    /** Detected adapter: 'wpml', 'polylang', or 'none'. */
    private string $adapter = 'none';

    /** Primary language code (e.g. 'en'). */
    private string $primary_lang = 'en';

    /** Secondary language code (e.g. 'ar'). */
    private string $secondary_lang = 'ar';

    /**
     * Collected secondary-language SEO redirects, flushed to wp_options at the
     * end of each translatePosts() pass (keyed old-path => new-URL).
     *
     * @var array<string, string>
     */
    private array $pending_sec_redirects = [];

    // ── Entry point (implements AbstractMigrator::migrate) ────────────────────

    public function migrate(): array {
        $settings_enabled = ! empty( $this->config['multilingual']['enabled'] );
        $run_enabled      = ! empty( $this->config['migration']['run_multilingual'] );

        if ( ! $settings_enabled && ! $run_enabled ) {
            $this->logger->info( '[multilingual] Disabled in config – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        // Guard against re-execution when a prior chunk already completed this step.
        if ( $this->checkpoint->isCompleted( self::KEY ) ) {
            $this->logger->info( '[multilingual] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        $this->primary_lang   = $this->config['multilingual']['primary_locale']   ?? 'en';
        $this->secondary_lang = $this->config['multilingual']['secondary_locale']  ?? 'ar';

        $this->adapter = $this->detectAdapter();

        $this->resolveLanguageCodes();

        if ( $this->adapter === 'none' ) {
            $this->logger->warning( '[multilingual] Neither WPML nor Polylang is active. Skipping translation pass.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        $this->logger->info( "[multilingual] Using adapter: {$this->adapter}. Primary: {$this->primary_lang} | Secondary: {$this->secondary_lang}" );

        $processed = 0;
        $skipped   = 0;
        $failed    = 0;

        // Pre-fetch Arabic SEO keywords from oc_seo_url so we can register
        // old-OC-path → new-WC-Arabic-URL redirects as each translation is linked.
        $sec_seo_map = $this->fetchSecondaryLangSeoMap();

        // ── 1. Translate taxonomy terms FIRST ────────────────────────────────
        // Arabic category and brand terms must exist before products are
        // translated so copyProductDataToTranslation() can resolve them.

        // Translate product categories.
        $cat_seo_map = $this->fetchSecondaryCategorySeoMap();
        [ $p, $s, $f ] = $this->translateTerms( 'product_cat', $cat_seo_map, 'category' );
        $processed += $p; $skipped += $s; $failed += $f;

        // Translate brand terms so Arabic products can be linked to Arabic brand term IDs.
        $brand_tax = $this->detectActiveBrandTaxonomy();
        if ( $brand_tax !== '' ) {
            [ $p, $s, $f ] = $this->translateTerms( $brand_tax, [], 'manufacturer' );
            $processed += $p; $skipped += $s; $failed += $f;
        }

        // ── 2. Translate posts (now that terms exist) ────────────────────────

        // Translate products.
        [ $p, $s, $f ] = $this->translatePosts( 'product', '_octowoo_name_ar', '_octowoo_description_ar', $sec_seo_map );
        $processed += $p; $skipped += $s; $failed += $f;

        // Translate pages (from InformationMigrator).
        [ $p, $s, $f ] = $this->translatePosts( 'page', '_octowoo_title_ar', '_octowoo_desc_ar' );
        $processed += $p; $skipped += $s; $failed += $f;

        $this->logger->info( "[multilingual] Done. Translated: {$processed}, Skipped: {$skipped}, Errors: {$failed}" );

        return [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed ];
    }

    // ── Post translation pass ─────────────────────────────────────────────────

    /**
     * Iterate every WC product / WP page that was migrated, read secondary
     * language meta and create a translated post linked to the primary.
     *
     * @return int[] [processed, skipped, failed]
     */
    private function translatePosts( string $post_type, string $title_meta_key, string $content_meta_key, array $sec_seo_map = [] ): array {
        global $wpdb;

        // Determine entity_type string used in id_map.
        $entity_type = $post_type === 'product' ? 'product' : 'information';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT wc_id FROM {$wpdb->prefix}octowoo_id_map WHERE entity_type = %s",
                $entity_type
            ),
            ARRAY_A
        );

        $processed = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ( $rows as $row ) {
            $primary_id = (int) $row['wc_id'];
            $oc_id      = (int) $row['oc_id'];

            // Fetch secondary language data from post meta.
            $ar_title   = (string) get_post_meta( $primary_id, $title_meta_key,   true );
            $ar_content = (string) get_post_meta( $primary_id, $content_meta_key, true );

            // Arabic short description — only relevant for products.
            $ar_excerpt = $post_type === 'product'
                ? (string) get_post_meta( $primary_id, '_octowoo_short_description_ar', true )
                : '';

            // Fall back to English values when Arabic meta is missing so every
            // product always gets a translation post (WPML still routes it under
            // /ar/ and WC meta, SKU, tags, and brands are all correctly assigned).
            $primary_post_raw = get_post( $primary_id );
            if ( ! $primary_post_raw ) {
                $failed++;
                continue;
            }

            if ( $ar_title === '' ) {
                $ar_title = $primary_post_raw->post_title;
                $this->logger->debug( "[multilingual] No Arabic title for {$post_type} #{$primary_id} – using English title as fallback." );
            }
            if ( $ar_content === '' ) {
                $ar_content = $primary_post_raw->post_content;
            }
            if ( $ar_excerpt === '' && $post_type === 'product' ) {
                $ar_excerpt = $primary_post_raw->post_excerpt;
            }

            // If translation already exists (e.g. WPML duplicated English),
            // update it with Arabic data instead of skipping permanently.
            $existing_translation_id = $this->getExistingTranslationId( $primary_id, 'post_' . $post_type );
            if ( $existing_translation_id > 0 ) {
                if ( $this->isDry() ) {
                    $this->logger->debug( "[DRY-RUN] Would update existing {$this->secondary_lang} translation for {$post_type} #{$primary_id}: {$ar_title}" );
                    $processed++;
                    continue;
                }

                $primary_post_for_slug = $primary_post_raw;
                $update_data = [
                    'ID'           => $existing_translation_id,
                    'post_title'   => $ar_title,
                    'post_content' => $ar_content,
                    'post_excerpt' => $ar_excerpt,
                    'post_name'    => $primary_post_for_slug ? $primary_post_for_slug->post_name : '',
                ];
                $updated = wp_update_post( $update_data, true );

                if ( is_wp_error( $updated ) ) {
                    $this->logger->error( "[multilingual] Failed updating existing translated post #{$existing_translation_id}: " . $updated->get_error_message() );
                    $failed++;
                    continue;
                }

                // For products, also sync WC meta + terms so SKU, price,
                // stock, tags, and brands all appear on the Arabic version.
                if ( $post_type === 'product' ) {
                    $this->copyProductDataToTranslation( $primary_id, $existing_translation_id );
                }

                // Sync Yoast SEO meta to the existing translation.
                $this->applyYoastPostMeta( $primary_id, $existing_translation_id );

                // After wp_update_post() the slug uniqueness check may have
                // appended -2 again. Force the correct slug now.
                if ( $primary_post_for_slug ) {
                    $this->fixTranslationSlug( $existing_translation_id, $primary_post_for_slug->post_name );
                }

                // Register the secondary-language SEO redirect so the old OC
                // Arabic URL (e.g. /ar/some-slug) points to the new WC URL.
                if ( ! empty( $sec_seo_map[ $oc_id ] ) ) {
                    $this->queueSecondaryLangRedirect( $existing_translation_id, $sec_seo_map[ $oc_id ] );
                }

                $this->logger->debug( "[multilingual] Updated existing {$post_type} translation #{$existing_translation_id} from primary #{$primary_id}." );
                $processed++;
                continue;
            }

            if ( $this->isDry() ) {
                $this->logger->debug( "[DRY-RUN] Would create {$this->secondary_lang} translation for {$post_type} #{$primary_id}: {$ar_title}" );
                $processed++;
                continue;
            }

            $translated_id = $this->createTranslatedPost( $primary_post_raw, $ar_title, $ar_content, $post_type, $ar_excerpt );

            if ( ! $translated_id ) {
                $failed++;
                continue;
            }

            // For products, sync WC meta + terms after the post is linked.
            if ( $post_type === 'product' ) {
                $this->copyProductDataToTranslation( $primary_id, $translated_id );
            }

            $this->linkPostTranslation( $primary_id, $translated_id, $post_type );

            // Now that WPML knows this post is the secondary-language translation,
            // its slug uniqueness is scoped per-language. Force the slug to match
            // the primary so Arabic URLs look identical (just with the /ar/ prefix):
            //   English: /product/zelda-switch/
            //   Arabic:  /ar/product/zelda-switch/   (NOT /ar/product/zelda-switch-2/)
            $this->fixTranslationSlug( $translated_id, $primary_post_raw->post_name );

            // Register the secondary-language SEO redirect so the old OC
            // Arabic URL (e.g. /ar/some-slug) points to the new WC URL.
            if ( ! empty( $sec_seo_map[ $oc_id ] ) ) {
                $this->queueSecondaryLangRedirect( $translated_id, $sec_seo_map[ $oc_id ] );
            }

            $this->logger->debug( "[multilingual] Linked {$post_type} #{$primary_id} ({$this->primary_lang}) ↔ #{$translated_id} ({$this->secondary_lang})" );
            $processed++;
        }

        // Persist any queued secondary-language redirects into the same
        // octowoo_redirects option that SeoMigrator uses (served by
        // SeoMigrator::handleWpRedirect() on every front-end request).
        $this->flushSecondaryLangRedirects();

        return [ $processed, $skipped, $failed ];
    }

    /**
     * Create a translated WP post in the secondary language.
     */
    private function createTranslatedPost( \WP_Post $source, string $title, string $content, string $post_type, string $excerpt = '' ): int {
        // Always use the primary (English) slug so Arabic URLs stay clean
        // (e.g. /ar/product/apple-cable/ instead of /ar/product/%d8%a7%d8%a8%d9%84-...).
        $slug = $source->post_name;

        // Avoid metadata contamination – create a plain duplicate.
        $insert_data = [
            'post_title'     => $title,
            'post_content'   => $content ?: $source->post_content,
            'post_excerpt'   => $excerpt,
            'post_status'    => $source->post_status,
            'post_type'      => $source->post_type,
            'post_name'      => $slug,
            'post_author'    => $source->post_author,
            'menu_order'     => $source->menu_order,
        ];

        $new_id = wp_insert_post( $insert_data, true );

        if ( is_wp_error( $new_id ) ) {
            $this->logger->error( "[multilingual] Failed creating translated post ({$this->secondary_lang}): " . $new_id->get_error_message() );
            return 0;
        }

        // Copy Yoast SEO meta for secondary language.
        // Fall back to English when Arabic meta is absent so the translated post
        // always has meaningful Yoast data instead of blank fields.
        $ar_meta_title = (string) get_post_meta( (int) $source->ID, '_octowoo_metatitle_ar', true );
        $ar_meta_desc  = (string) get_post_meta( (int) $source->ID, '_octowoo_metadesc_ar',  true );
        $ar_meta_kw    = (string) get_post_meta( (int) $source->ID, '_octowoo_metakw_ar',    true );

        if ( $ar_meta_title === '' ) {
            $ar_meta_title = (string) get_post_meta( (int) $source->ID, '_yoast_wpseo_title', true );
        }
        if ( $ar_meta_desc === '' ) {
            $ar_meta_desc = (string) get_post_meta( (int) $source->ID, '_yoast_wpseo_metadesc', true );
        }
        if ( $ar_meta_kw === '' ) {
            $ar_meta_kw = (string) get_post_meta( (int) $source->ID, '_yoast_wpseo_focuskw', true );
        }

        if ( $ar_meta_title ) {
            update_post_meta( $new_id, '_yoast_wpseo_title',   $ar_meta_title );
        }
        if ( $ar_meta_desc ) {
            update_post_meta( $new_id, '_yoast_wpseo_metadesc', $ar_meta_desc );
        }
        if ( $ar_meta_kw ) {
            update_post_meta( $new_id, '_yoast_wpseo_focuskw', $ar_meta_kw );
        }

        // Mark as a translation.
        update_post_meta( $new_id, '_octowoo_translation_of', $source->ID );
        update_post_meta( $new_id, '_octowoo_translation_lang', $this->secondary_lang );

        return (int) $new_id;
    }

    /**
     * Force a post's slug (post_name) to exactly $desired_slug, bypassing
     * WordPress's wp_unique_post_slug() uniqueness check.
     *
     * Why this is needed: when wp_insert_post() runs for the Arabic translation,
     * WordPress sees the English post already has the same slug and appends "-2",
     * producing ugly URLs like /ar/product/zelda-switch-2/.
     *
     * This must be called AFTER linkPostTranslation() so WPML already knows the
     * post is in the secondary language. WPML then routes it under /ar/ making
     * the full URL unique — we just need the post_name to be identical.
     *
     * We write directly to wp_posts and bust the object cache; no hooks fire.
     */
    private function fixTranslationSlug( int $post_id, string $desired_slug ): void {
        if ( $desired_slug === '' ) {
            return;
        }
        $current = get_post_field( 'post_name', $post_id );
        if ( $current === $desired_slug ) {
            return; // Already correct — nothing to do.
        }
        global $wpdb;
        $wpdb->update( $wpdb->posts, [ 'post_name' => $desired_slug ], [ 'ID' => $post_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        clean_post_cache( $post_id );
        $this->logger->debug( "[multilingual] Slug fixed for post #{$post_id}: '{$current}' → '{$desired_slug}'" );
    }

    // ── Yoast SEO meta helpers ─────────────────────────────────────────────────

    /**
     * Write Yoast SEO meta (title, metadesc, focuskw) to an Arabic translated post.
     * Reads Arabic _octowoo_*_ar meta from the primary post; falls back to the
     * English Yoast values so the translated post always has complete SEO data.
     */
    private function applyYoastPostMeta( int $primary_id, int $translated_id ): void {
        $title = (string) get_post_meta( $primary_id, '_octowoo_metatitle_ar', true );
        $desc  = (string) get_post_meta( $primary_id, '_octowoo_metadesc_ar',  true );
        $kw    = (string) get_post_meta( $primary_id, '_octowoo_metakw_ar',    true );

        if ( $title === '' ) { $title = (string) get_post_meta( $primary_id, '_yoast_wpseo_title',    true ); }
        if ( $desc  === '' ) { $desc  = (string) get_post_meta( $primary_id, '_yoast_wpseo_metadesc', true ); }
        if ( $kw    === '' ) { $kw    = (string) get_post_meta( $primary_id, '_yoast_wpseo_focuskw',  true ); }

        if ( $title ) { update_post_meta( $translated_id, '_yoast_wpseo_title',   $title ); }
        if ( $desc )  { update_post_meta( $translated_id, '_yoast_wpseo_metadesc', $desc ); }
        if ( $kw )    { update_post_meta( $translated_id, '_yoast_wpseo_focuskw',  $kw ); }
    }

    /**
     * Write Yoast SEO meta to an Arabic translated term.
     * Reads Arabic _octowoo_*_ar meta from the primary term; falls back to English.
     */
    private function applyYoastTermMeta( int $primary_term_id, int $translated_term_id ): void {
        $title = (string) get_term_meta( $primary_term_id, '_octowoo_metatitle_ar', true );
        $desc  = (string) get_term_meta( $primary_term_id, '_octowoo_metadesc_ar',  true );
        $kw    = (string) get_term_meta( $primary_term_id, '_octowoo_metakw_ar',    true );

        if ( $title === '' ) { $title = (string) get_term_meta( $primary_term_id, '_yoast_wpseo_title',    true ); }
        if ( $desc  === '' ) { $desc  = (string) get_term_meta( $primary_term_id, '_yoast_wpseo_metadesc', true ); }
        if ( $kw    === '' ) { $kw    = (string) get_term_meta( $primary_term_id, '_yoast_wpseo_focuskw',  true ); }

        if ( $title ) { update_term_meta( $translated_term_id, '_yoast_wpseo_title',   $title ); }
        if ( $desc )  { update_term_meta( $translated_term_id, '_yoast_wpseo_metadesc', $desc ); }
        if ( $kw )    { update_term_meta( $translated_term_id, '_yoast_wpseo_focuskw',  $kw ); }
    }

    // ── Secondary-language SEO redirects ──────────────────────────────────────

    /**
     * Pre-fetch all secondary-language SEO keywords from oc_seo_url indexed by
     * OC product_id.  Used to map old OpenCart Arabic product paths to new WC
     * Arabic URLs.
     *
     * Returns an empty array when the secondary language is disabled, the
     * oc_seo_url table does not exist, or no secondary-language rows are found.
     *
     * @return array<int, string>  [ oc_product_id => sanitised_slug ]
     */
    private function fetchSecondaryLangSeoMap(): array {
        $lang_id_sec = $this->langIdSecondary();
        if ( $lang_id_sec === 0 ) {
            return [];
        }

        $pfx = $this->pfx();

        // Guard: table may not exist on older OC installs.
        $table_exists = $this->oc->fetchColumn(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [ $pfx . 'seo_url' ]
        );
        if ( ! $table_exists ) {
            return [];
        }

        $rows = $this->oc->fetchAll(
            "SELECT query, keyword
             FROM `{$pfx}seo_url`
             WHERE store_id = 0 AND language_id = ? AND keyword != ''",
            [ $lang_id_sec ]
        );

        $map = [];
        foreach ( $rows as $row ) {
            if ( preg_match( '/^product_id=(\d+)$/', $row['query'], $m ) ) {
                $map[ (int) $m[1] ] = sanitize_title( $row['keyword'] );
            }
        }

        $this->logger->debug( '[multilingual] Fetched ' . count( $map ) . ' secondary-language SEO keywords for redirect mapping.' );

        return $map;
    }

    /**
     * Pre-fetch all secondary-language SEO keywords for categories from
     * oc_seo_url indexed by OC category_id.
     *
     * @return array<int, string>  [ oc_category_id => sanitised_slug ]
     */
    private function fetchSecondaryCategorySeoMap(): array {
        $lang_id_sec = $this->langIdSecondary();
        if ( $lang_id_sec === 0 ) {
            return [];
        }

        $pfx = $this->pfx();

        $table_exists = $this->oc->fetchColumn(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [ $pfx . 'seo_url' ]
        );
        if ( ! $table_exists ) {
            return [];
        }

        $rows = $this->oc->fetchAll(
            "SELECT query, keyword
             FROM `{$pfx}seo_url`
             WHERE store_id = 0 AND language_id = ? AND keyword != ''",
            [ $lang_id_sec ]
        );

        $map = [];
        foreach ( $rows as $row ) {
            if ( preg_match( '/^category_id=(\d+)$/', $row['query'], $m ) ) {
                $map[ (int) $m[1] ] = sanitize_title( $row['keyword'] );
            }
        }

        $this->logger->debug( '[multilingual] Fetched ' . count( $map ) . ' secondary-language SEO keywords for category redirect mapping.' );

        return $map;
    }

    /**
     * Collect a secondary-language SEO redirect into the pending batch.
     *
     * Old path  = /{secondary_lang}/{oc_keyword}  (e.g. /ar/some-product-slug)
     * New URL   = WPML-aware permalink of the translated post.
     */
    private function queueSecondaryLangRedirect( int $translated_id, string $oc_keyword ): void {
        // Use WPML's permalink filter so the returned URL includes the correct
        // language prefix (e.g. /ar/) even when called outside of a request context.
        $new_url = apply_filters( 'wpml_permalink', get_permalink( $translated_id ), $this->secondary_lang );
        if ( empty( $new_url ) ) {
            return;
        }

        $old_path = '/' . $this->secondary_lang . '/' . $oc_keyword;
        $this->pending_sec_redirects[ $old_path ] = $new_url;
    }

    /**
     * Collect a secondary-language SEO redirect for a translated taxonomy term.
     *
     * Old path = /{secondary_lang}/{oc_keyword}  (e.g. /ar/electronics-in-qatar)
     * New URL  = WPML-aware term link            (e.g. /ar/product-category/electronics-in-qatar/)
     */
    private function queueSecondaryTermRedirect( int $translated_term_id, string $taxonomy, string $oc_keyword ): void {
        $term_link = apply_filters( 'wpml_permalink', get_term_link( $translated_term_id, $taxonomy ), $this->secondary_lang );
        if ( empty( $term_link ) || is_wp_error( $term_link ) ) {
            return;
        }

        $old_path = '/' . $this->secondary_lang . '/' . $oc_keyword;
        $this->pending_sec_redirects[ $old_path ] = $term_link;
    }

    /**
     * Merge all pending secondary-language redirects into the octowoo_redirects
     * WP option (the same store that SeoMigrator writes to, served by
     * SeoMigrator::handleWpRedirect() on every front-end request).
     */
    private function flushSecondaryLangRedirects(): void {
        if ( empty( $this->pending_sec_redirects ) ) {
            return;
        }

        if ( $this->isDry() ) {
            $this->logger->debug( '[DRY-RUN] Would register ' . count( $this->pending_sec_redirects ) . ' secondary-language SEO redirects.' );
            $this->pending_sec_redirects = [];
            return;
        }

        $existing = get_option( 'octowoo_redirects', [] );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }

        $merged = array_merge( $existing, $this->pending_sec_redirects );
        update_option( 'octowoo_redirects', $merged, false );

        $this->logger->info( '[multilingual] Registered ' . count( $this->pending_sec_redirects ) . ' secondary-language SEO redirects.' );
        $this->pending_sec_redirects = [];
    }

    /**
     * Set language and link the post pair with WPML or Polylang.
     */
    private function linkPostTranslation( int $primary_id, int $translated_id, string $post_type ): void {
        $element_type = 'post_' . $post_type;

        if ( $this->adapter === 'wpml' ) {
            // Get the existing trid FIRST (WPML may have auto-assigned one during
            // wp_insert_post). Passing the existing trid avoids creating a duplicate
            // translation group for the same post.
            $existing_trid = $this->wpmlGetTrid( $primary_id, $element_type );
            $this->wpmlSetPostLanguage( $primary_id, $element_type, $this->primary_lang, $existing_trid );
            // Re-fetch trid after language update to ensure we have the canonical value.
            $trid = $this->wpmlGetTrid( $primary_id, $element_type );
            $this->wpmlSetPostLanguage( $translated_id, $element_type, $this->secondary_lang, $trid );

        } elseif ( $this->adapter === 'polylang' ) {
            $this->polylangSetPostLanguage( $primary_id,    $this->primary_lang );
            $this->polylangSetPostLanguage( $translated_id, $this->secondary_lang );
            $this->polylangLinkPostTranslations( [
                $this->primary_lang   => $primary_id,
                $this->secondary_lang => $translated_id,
            ] );
        }
    }

    // ── Term translation pass ─────────────────────────────────────────────────

    /**
     * Copy all WooCommerce-specific meta and taxonomy term assignments from the
     * English primary product to its Arabic translation post.
     *
     * WPML does NOT automatically carry these over when we create the translated
     * post manually, so we must copy them explicitly:
     *   – Core WC product meta (SKU, price, stock, weight, attributes …)
     *   – product_type term  (simple/variable)
     *   – product_tag terms
     *   – Brand taxonomy terms (whichever plugin is active)
     */
    private function copyProductDataToTranslation( int $source_id, int $target_id ): void {
        // ── WooCommerce core product meta ──────────────────────────────────
        $wc_meta_keys = [
            '_sku', '_regular_price', '_price', '_sale_price',
            '_stock', '_stock_status', '_manage_stock', '_backorders',
            '_weight', '_length', '_width', '_height',
            '_virtual', '_downloadable', '_sold_individually',
            '_tax_status', '_tax_class', '_product_attributes',
            '_octowoo_oc_id',
        ];
        foreach ( $wc_meta_keys as $key ) {
            $value = get_post_meta( $source_id, $key, true );
            // update_post_meta handles '' safely (clears the meta).
            update_post_meta( $target_id, $key, $value );
        }

        // ── product_type term (simple / variable / …) ──────────────────────
        $type_terms = wp_get_object_terms( $source_id, 'product_type', [ 'fields' => 'names' ] );
        if ( ! is_wp_error( $type_terms ) && ! empty( $type_terms ) ) {
            wp_set_object_terms( $target_id, $type_terms, 'product_type' );
        }

        // ── product_cat terms → resolve to Arabic translated category terms ─
        // Without this the Arabic product has no category at all, so the
        // breadcrumb shows "Home › Shop › Product" with no category segment.
        $cat_ids = wp_get_object_terms( $source_id, 'product_cat', [ 'fields' => 'ids' ] );
        if ( ! is_wp_error( $cat_ids ) && ! empty( $cat_ids ) ) {
            $translated_cat_ids = [];
            foreach ( array_map( 'intval', $cat_ids ) as $cat_id ) {
                $ar_cat_id = $this->getExistingTranslationId( $cat_id, 'tax_product_cat' );
                // Fall back to the English term ID if no Arabic translation exists yet.
                $translated_cat_ids[] = $ar_cat_id > 0 ? $ar_cat_id : $cat_id;
            }
            wp_set_object_terms( $target_id, $translated_cat_ids, 'product_cat' );
        }

        // ── product_tag terms ──────────────────────────────────────────────
        // Use tag IDs so the same term objects are shared (no duplicates).
        $tag_ids = wp_get_object_terms( $source_id, 'product_tag', [ 'fields' => 'ids' ] );
        if ( ! is_wp_error( $tag_ids ) && ! empty( $tag_ids ) ) {
            wp_set_object_terms( $target_id, array_map( 'intval', $tag_ids ), 'product_tag', true );
        }

        // ── Brand / manufacturer taxonomy ──────────────────────────────────
        // Resolve English brand term IDs → Arabic translated term IDs.
        // Falls back to the English term ID when no Arabic translation exists.
        $brand_tax = $this->detectActiveBrandTaxonomy();
        if ( $brand_tax !== '' ) {
            $brand_ids = wp_get_object_terms( $source_id, $brand_tax, [ 'fields' => 'ids' ] );
            if ( ! is_wp_error( $brand_ids ) && ! empty( $brand_ids ) ) {
                $translated_brand_ids = [];
                foreach ( array_map( 'intval', $brand_ids ) as $bid ) {
                    $ar_bid = $this->getExistingTranslationId( $bid, "tax_{$brand_tax}" );
                    $translated_brand_ids[] = $ar_bid > 0 ? $ar_bid : $bid;
                }
                wp_set_object_terms( $target_id, $translated_brand_ids, $brand_tax );
            }
        }
    }

    /**
     * Return the first registered brand taxonomy slug on this site, or ''.
     */
    private function detectActiveBrandTaxonomy(): string {
        $candidates = [
            'product_brand',        // WooCommerce Brands (official) · Ultimate WooCommerce Brands
            'pwb-brand',            // Perfect WooCommerce Brands
            'yith_product_brand',   // YITH WooCommerce Brands
            'berocket_brand',       // Brands for WooCommerce by BeRocket
            'pa_brand',             // Attribute-based brand
            'brand',                // Generic / theme-based
            'product_manufacturer', // OctoWoo fallback
        ];
        foreach ( $candidates as $tax ) {
            if ( taxonomy_exists( $tax ) ) {
                return $tax;
            }
        }
        return '';
    }

    /**
     * Iterate every migrated taxonomy term and create secondary language counterparts.
     *
     * @param string $taxonomy      WP taxonomy slug (e.g. 'product_cat', 'product_brand').
     * @param array  $sec_seo_map   OC-ID → SEO-slug map for redirect registration.
     * @param string $entity_type   Value used in octowoo_id_map (default 'category').
     * @return int[] [processed, skipped, failed]
     */
    private function translateTerms( string $taxonomy, array $sec_seo_map = [], string $entity_type = 'category' ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT oc_id, wc_id FROM {$wpdb->prefix}octowoo_id_map WHERE entity_type = %s",
                $entity_type
            ),
            ARRAY_A
        );

        $processed = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ( $rows as $row ) {
            $primary_term_id = (int) $row['wc_id'];
            $oc_id           = (int) $row['oc_id'];

            $ar_name        = get_term_meta( $primary_term_id, '_octowoo_name_ar',        true );
            $ar_description = get_term_meta( $primary_term_id, '_octowoo_description_ar', true );

            // Fetch primary term for slug and fallback values.
            $primary_term = get_term( $primary_term_id, $taxonomy );
            if ( ! $primary_term || is_wp_error( $primary_term ) ) {
                $failed++;
                continue;
            }

            // Fall back to English when Arabic meta is absent so every
            // category still gets a linked Arabic term.
            if ( ! $ar_name ) {
                $ar_name = $primary_term->name;
                $this->logger->debug( "[multilingual] No Arabic name meta for {$taxonomy} term WC #{$primary_term_id} – using English name as fallback." );
            }
            if ( ! $ar_description ) {
                $ar_description = $primary_term->description;
            }

            $existing_translation_id = $this->getExistingTranslationId( $primary_term_id, "tax_{$taxonomy}" );
            if ( $existing_translation_id > 0 ) {
                if ( $this->isDry() ) {
                    $this->logger->debug( "[DRY-RUN] Would update existing {$this->secondary_lang} translation for {$taxonomy} term #{$primary_term_id}: {$ar_name}" );
                    $processed++;
                    continue;
                }

                $updated = wp_update_term( $existing_translation_id, $taxonomy, [
                    'name'        => $ar_name,
                    'description' => $ar_description,
                    'slug'        => $primary_term->slug,
                ] );

                if ( is_wp_error( $updated ) ) {
                    $this->logger->error( "[multilingual] Failed updating existing translated term #{$existing_translation_id}: " . $updated->get_error_message() );
                    $failed++;
                    continue;
                }

                // Sync Yoast SEO meta to the existing translated term.
                $this->applyYoastTermMeta( $primary_term_id, $existing_translation_id );

                // Register old OC Arabic URL → new WC Arabic category URL.
                if ( ! empty( $sec_seo_map[ $oc_id ] ) ) {
                    $this->queueSecondaryTermRedirect( $existing_translation_id, $taxonomy, $sec_seo_map[ $oc_id ] );
                }

                $this->logger->debug( "[multilingual] Updated existing {$taxonomy} translation term #{$existing_translation_id} from primary #{$primary_term_id}." );
                $processed++;
                continue;
            }

            if ( $this->isDry() ) {
                $this->logger->debug( "[DRY-RUN] Would create {$this->secondary_lang} translation for {$taxonomy} term #{$primary_term_id}: {$ar_name}" );
                $processed++;
                continue;
            }

            $translated_term_id = $this->createTranslatedTerm( $primary_term, $ar_name, $ar_description, $taxonomy );

            if ( ! $translated_term_id ) {
                $failed++;
                continue;
            }

            $this->linkTermTranslation( $primary_term_id, $translated_term_id, $taxonomy );

            // Register old OC Arabic URL → new WC Arabic category URL.
            if ( ! empty( $sec_seo_map[ $oc_id ] ) ) {
                $this->queueSecondaryTermRedirect( $translated_term_id, $taxonomy, $sec_seo_map[ $oc_id ] );
            }

            $this->logger->debug( "[multilingual] Linked {$taxonomy} term #{$primary_term_id} ({$this->primary_lang}) ↔ #{$translated_term_id} ({$this->secondary_lang})" );
            $processed++;
        }

        // Persist any queued secondary-language category redirects.
        $this->flushSecondaryLangRedirects();

        return [ $processed, $skipped, $failed ];
    }

    /**
     * Create a translated taxonomy term in the secondary language.
     */
    private function createTranslatedTerm( \WP_Term $source, string $name, string $description, string $taxonomy ): int {
        // Always use the primary (English) slug so Arabic URLs stay clean.
        $slug = $source->slug;

        $result = wp_insert_term( $name, $taxonomy, [
            'description' => $description ?: $source->description,
            'slug'        => $slug,
            'parent'      => 0, // WPML/Polylang manage their own parent hierarchy.
        ] );

        if ( is_wp_error( $result ) && $result->get_error_code() === 'term_exists' ) {
            return (int) $result->get_error_data( 'term_exists' );
        }

        if ( is_wp_error( $result ) ) {
            $this->logger->error( "[multilingual] Failed creating translated term ({$this->secondary_lang}): " . $result->get_error_message() );
            return 0;
        }

        $translated_term_id = (int) $result['term_id'];

        // Copy Yoast SEO meta.
        // Fall back to English when Arabic meta is absent.
        $ar_meta_title = (string) get_term_meta( $source->term_id, '_octowoo_metatitle_ar', true );
        $ar_meta_desc  = (string) get_term_meta( $source->term_id, '_octowoo_metadesc_ar',  true );
        $ar_meta_kw    = (string) get_term_meta( $source->term_id, '_octowoo_metakw_ar',    true );

        if ( $ar_meta_title === '' ) {
            $ar_meta_title = (string) get_term_meta( $source->term_id, '_yoast_wpseo_title', true );
        }
        if ( $ar_meta_desc === '' ) {
            $ar_meta_desc = (string) get_term_meta( $source->term_id, '_yoast_wpseo_metadesc', true );
        }
        if ( $ar_meta_kw === '' ) {
            $ar_meta_kw = (string) get_term_meta( $source->term_id, '_yoast_wpseo_focuskw', true );
        }

        if ( $ar_meta_title ) {
            update_term_meta( $translated_term_id, '_yoast_wpseo_title',   $ar_meta_title );
        }
        if ( $ar_meta_desc ) {
            update_term_meta( $translated_term_id, '_yoast_wpseo_metadesc', $ar_meta_desc );
        }
        if ( $ar_meta_kw ) {
            update_term_meta( $translated_term_id, '_yoast_wpseo_focuskw', $ar_meta_kw );
        }

        update_term_meta( $translated_term_id, '_octowoo_translation_of',   $source->term_id );
        update_term_meta( $translated_term_id, '_octowoo_translation_lang', $this->secondary_lang );

        return $translated_term_id;
    }

    /**
     * Set language and link the term pair with WPML or Polylang.
     */
    private function linkTermTranslation( int $primary_term_id, int $translated_term_id, string $taxonomy ): void {
        $primary_term = get_term( $primary_term_id, $taxonomy );
        if ( ! $primary_term || is_wp_error( $primary_term ) ) {
            return;
        }

        $element_type = "tax_{$taxonomy}";

        if ( $this->adapter === 'wpml' ) {
            // Get existing trid FIRST (WPML may have auto-assigned one during
            // wp_insert_term) to avoid creating a duplicate translation group.
            $existing_trid = $this->wpmlGetTridForTerm( $primary_term, $element_type );
            $this->wpmlSetTermLanguage( $primary_term, $element_type, $this->primary_lang, $existing_trid );
            // Re-fetch after update for canonical trid.
            $trid = $this->wpmlGetTridForTerm( $primary_term, $element_type );
            $translated_term = get_term( $translated_term_id, $taxonomy );
            if ( $translated_term && ! is_wp_error( $translated_term ) ) {
                $this->wpmlSetTermLanguage( $translated_term, $element_type, $this->secondary_lang, $trid );
            }

        } elseif ( $this->adapter === 'polylang' ) {
            $this->polylangSetTermLanguage( $primary_term_id,    $this->primary_lang );
            $this->polylangSetTermLanguage( $translated_term_id, $this->secondary_lang );
            $this->polylangLinkTermTranslations( $taxonomy, [
                $this->primary_lang   => $primary_term_id,
                $this->secondary_lang => $translated_term_id,
            ] );
        }
    }

    // ── WPML helpers ──────────────────────────────────────────────────────────

    private function wpmlSetPostLanguage( int $post_id, string $element_type, string $lang, ?int $trid ): void {
        do_action( 'wpml_set_element_language_details', [
            'element_id'           => $post_id,
            'element_type'         => $element_type,
            'trid'                 => $trid,
            'language_code'        => $lang,
            'source_language_code' => $trid === null ? null : $this->primary_lang,
        ] );
    }

    private function wpmlGetTrid( int $post_id, string $element_type ): ?int {
        $trid = apply_filters( 'wpml_element_trid', null, $post_id, $element_type );
        return $trid ? (int) $trid : null;
    }

    private function wpmlSetTermLanguage( \WP_Term $term, string $element_type, string $lang, ?int $trid ): void {
        do_action( 'wpml_set_element_language_details', [
            'element_id'           => (int) $term->term_taxonomy_id,
            'element_type'         => $element_type,
            'trid'                 => $trid,
            'language_code'        => $lang,
            'source_language_code' => $trid === null ? null : $this->primary_lang,
        ] );
    }

    private function wpmlGetTridForTerm( \WP_Term $term, string $element_type ): ?int {
        $trid = apply_filters( 'wpml_element_trid', null, (int) $term->term_taxonomy_id, $element_type );
        return $trid ? (int) $trid : null;
    }

    // ── Polylang helpers ──────────────────────────────────────────────────────

    private function polylangSetPostLanguage( int $post_id, string $lang ): void {
        if ( function_exists( 'pll_set_post_language' ) ) {
            pll_set_post_language( $post_id, $lang );
        }
    }

    /**
     * @param array<string, int> $lang_to_id  e.g. ['en' => 1, 'ar' => 2]
     */
    private function polylangLinkPostTranslations( array $lang_to_id ): void {
        if ( function_exists( 'pll_save_post_translations' ) ) {
            pll_save_post_translations( $lang_to_id );
        }
    }

    private function polylangSetTermLanguage( int $term_id, string $lang ): void {
        if ( function_exists( 'pll_set_term_language' ) ) {
            pll_set_term_language( $term_id, $lang );
        }
    }

    /**
     * @param array<string, int> $lang_to_id
     */
    private function polylangLinkTermTranslations( string $taxonomy, array $lang_to_id ): void {
        if ( function_exists( 'pll_save_term_translations' ) ) {
            pll_save_term_translations( $lang_to_id );
        }
    }

    // ── Translation existence check ───────────────────────────────────────────

    /**
     * Check whether $element_id already has a translation in the secondary language.
     */
    private function translationExists( int $element_id, string $element_type ): bool {
        return $this->getExistingTranslationId( $element_id, $element_type ) > 0;
    }

    /**
     * Return translated object ID in secondary language, or 0 when missing.
     */
    private function getExistingTranslationId( int $element_id, string $element_type ): int {
        if ( $this->adapter === 'wpml' ) {
            $translated = apply_filters(
                'wpml_object_id',
                $element_id,
                str_replace( [ 'post_', 'tax_' ], '', $element_type ),
                false,
                $this->secondary_lang
            );
            $translated_id = (int) $translated;
            return ( $translated_id > 0 && $translated_id !== $element_id ) ? $translated_id : 0;
        }

        if ( $this->adapter === 'polylang' ) {
            if ( strpos( $element_type, 'post_' ) === 0 && function_exists( 'pll_get_post' ) ) {
                $translated = pll_get_post( $element_id, $this->secondary_lang );
                $translated_id = (int) $translated;
                return ( $translated_id > 0 && $translated_id !== $element_id ) ? $translated_id : 0;
            }
            if ( strpos( $element_type, 'tax_' ) === 0 && function_exists( 'pll_get_term' ) ) {
                $translated = pll_get_term( $element_id, $this->secondary_lang );
                $translated_id = (int) $translated;
                return ( $translated_id > 0 && $translated_id !== $element_id ) ? $translated_id : 0;
            }
        }

        // Fallback: check our own meta.
        if ( strpos( $element_type, 'post_' ) === 0 ) {
            $existing = get_posts( [
                'meta_key'       => '_octowoo_translation_of',
                'meta_value'     => $element_id,
                'meta_query'     => [
                    'relation' => 'AND',
                    [
                        'key'   => '_octowoo_translation_of',
                        'value' => $element_id,
                    ],
                    [
                        'key'   => '_octowoo_translation_lang',
                        'value' => $this->secondary_lang,
                    ],
                ],
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ] );
            return ! empty( $existing ) ? (int) $existing[0] : 0;
        }

        return 0;
    }

    // ── Adapter detection ─────────────────────────────────────────────────────

    /**
     * Determine which multilingual plugin is active.
     *
     * @return string  'wpml' | 'polylang' | 'none'
     */
    private function detectAdapter(): string {
        $prefer_wpml      = ! empty( $this->config['multilingual']['use_wpml'] );
        $prefer_polylang  = ! empty( $this->config['multilingual']['use_polylang'] );

        $has_wpml     = defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' );
        $has_polylang = function_exists( 'pll_the_languages' ) || class_exists( 'Polylang' );

        if ( $prefer_wpml && $has_wpml ) {
            return 'wpml';
        }
        if ( $prefer_polylang && $has_polylang ) {
            return 'polylang';
        }
        if ( $has_wpml ) {
            return 'wpml';
        }
        if ( $has_polylang ) {
            return 'polylang';
        }

        return 'none';
    }

    /**
     * Normalize configured language values (locale/code) to active plugin codes.
     */
    private function resolveLanguageCodes(): void {
        $configured_primary   = (string) $this->primary_lang;
        $configured_secondary = (string) $this->secondary_lang;

        if ( $this->adapter === 'wpml' ) {
            $langs = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
            if ( is_array( $langs ) && ! empty( $langs ) ) {
                $this->primary_lang = $this->resolveAgainstWpmlLanguages( $configured_primary, $langs, 'en' );
                $this->secondary_lang = $this->resolveAgainstWpmlLanguages( $configured_secondary, $langs, 'ar' );
            }
        } elseif ( $this->adapter === 'polylang' && function_exists( 'pll_languages_list' ) ) {
            $active_slugs = (array) pll_languages_list( [ 'fields' => 'slug' ] );
            if ( ! empty( $active_slugs ) ) {
                $this->primary_lang = $this->resolveAgainstSimpleSlugs( $configured_primary, $active_slugs, 'en' );
                $this->secondary_lang = $this->resolveAgainstSimpleSlugs( $configured_secondary, $active_slugs, 'ar' );
            }
        }

        if ( $this->primary_lang === $this->secondary_lang ) {
            $this->logger->warning( "[multilingual] Primary and secondary resolved to same language '{$this->primary_lang}'. Forcing fallback secondary 'ar'." );
            $this->secondary_lang = 'ar';
        }
    }

    /**
     * Resolve a configured language value against WPML active languages.
     *
     * @param string $configured Language code or locale (e.g. en, en_US).
     * @param array  $langs      WPML active languages payload.
     * @param string $fallback   Fallback code.
     */
    private function resolveAgainstWpmlLanguages( string $configured, array $langs, string $fallback ): string {
        $configured = trim( $configured );
        if ( $configured === '' ) {
            return $fallback;
        }

        if ( isset( $langs[ $configured ] ) ) {
            return $configured;
        }

        $norm_target = $this->normalizeLangCode( $configured );

        foreach ( $langs as $code => $info ) {
            if ( $this->normalizeLangCode( (string) $code ) === $norm_target ) {
                return (string) $code;
            }

            $locale = (string) ( $info['default_locale'] ?? $info['locale'] ?? '' );
            if ( $locale !== '' && $this->normalizeLangCode( $locale ) === $norm_target ) {
                return (string) $code;
            }
        }

        return $fallback;
    }

    /**
     * Resolve configured language code/locale against simple slug arrays.
     *
     * @param string   $configured Language code or locale.
     * @param string[] $slugs      Active slugs.
     * @param string   $fallback   Fallback slug.
     */
    private function resolveAgainstSimpleSlugs( string $configured, array $slugs, string $fallback ): string {
        $configured = trim( $configured );
        if ( $configured === '' ) {
            return $fallback;
        }

        if ( in_array( $configured, $slugs, true ) ) {
            return $configured;
        }

        $norm_target = $this->normalizeLangCode( $configured );
        foreach ( $slugs as $slug ) {
            if ( $this->normalizeLangCode( (string) $slug ) === $norm_target ) {
                return (string) $slug;
            }
        }

        return $fallback;
    }

    /**
     * Normalize locales/codes (en_US, en-GB, EN) to base lowercase code (en).
     */
    private function normalizeLangCode( string $value ): string {
        $value = strtolower( trim( $value ) );
        if ( $value === '' ) {
            return '';
        }

        $parts = preg_split( '/[_-]/', $value );
        return (string) ( $parts[0] ?? $value );
    }

    // ── Static registration helper ────────────────────────────────────────────

    /**
     * Hook into OctoWoo action events (for real-time translation as migrators run).
     * Called by MigrationManager::bootstrap() when multilingual is enabled.
     *
     * @param array $config  Full resolved config.
     */
    public static function registerHooks( array $config ): void {
        if ( empty( $config['multilingual']['enabled'] ) ) {
            return;
        }

        /**
         * Fires after octowoo_migration_finished so all ID maps are populated
         * before we attempt the translation pass.
         */
        add_action( 'octowoo_migration_finished', function ( string $run_id, array $report, array $resolved_config ) {
            // WpmlIntegration is run as a formal migrator in MIGRATOR_ORDER,
            // so this hook is intentionally left as a lightweight callback.
            do_action( 'octowoo_multilingual_pass_complete', $run_id );
        }, 10, 3 );
    }
}
