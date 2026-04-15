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

    // ── Entry point (implements AbstractMigrator::migrate) ────────────────────

    public function migrate(): array {
        if ( empty( $this->config['multilingual']['enabled'] ) ) {
            $this->logger->info( '[multilingual] Disabled in config – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        $this->primary_lang   = $this->config['multilingual']['primary_locale']   ?? 'en';
        $this->secondary_lang = $this->config['multilingual']['secondary_locale']  ?? 'ar';

        $this->adapter = $this->detectAdapter();

        if ( $this->adapter === 'none' ) {
            $this->logger->warning( '[multilingual] Neither WPML nor Polylang is active. Skipping translation pass.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        $this->logger->info( "[multilingual] Using adapter: {$this->adapter}. Primary: {$this->primary_lang} | Secondary: {$this->secondary_lang}" );

        $processed = 0;
        $skipped   = 0;
        $failed    = 0;

        // Translate products.
        [ $p, $s, $f ] = $this->translatePosts( 'product', '_octowoo_name_ar', '_octowoo_description_ar' );
        $processed += $p; $skipped += $s; $failed += $f;

        // Translate pages (from InformationMigrator).
        [ $p, $s, $f ] = $this->translatePosts( 'page', '_octowoo_title_ar', '_octowoo_desc_ar' );
        $processed += $p; $skipped += $s; $failed += $f;

        // Translate product categories.
        [ $p, $s, $f ] = $this->translateTerms( 'product_cat' );
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
    private function translatePosts( string $post_type, string $title_meta_key, string $content_meta_key ): array {
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

            // Check if a translation already exists.
            if ( $this->translationExists( $primary_id, 'post_' . $post_type ) ) {
                $skipped++;
                continue;
            }

            // Fetch secondary language data from post meta.
            $ar_title   = get_post_meta( $primary_id, $title_meta_key,   true );
            $ar_content = get_post_meta( $primary_id, $content_meta_key, true );

            if ( ! $ar_title ) {
                $skipped++;
                continue;
            }

            $primary_post = get_post( $primary_id );
            if ( ! $primary_post ) {
                $failed++;
                continue;
            }

            if ( $this->isDry() ) {
                $this->logger->debug( "[DRY-RUN] Would create {$this->secondary_lang} translation for {$post_type} #{$primary_id}: {$ar_title}" );
                $processed++;
                continue;
            }

            $translated_id = $this->createTranslatedPost( $primary_post, $ar_title, $ar_content, $post_type );

            if ( ! $translated_id ) {
                $failed++;
                continue;
            }

            $this->linkPostTranslation( $primary_id, $translated_id, $post_type );

            $this->logger->debug( "[multilingual] Linked {$post_type} #{$primary_id} ({$this->primary_lang}) ↔ #{$translated_id} ({$this->secondary_lang})" );
            $processed++;
        }

        return [ $processed, $skipped, $failed ];
    }

    /**
     * Create a translated WP post in the secondary language.
     */
    private function createTranslatedPost( \WP_Post $source, string $title, string $content, string $post_type ): int {
        $slug = $this->toSlug( $title );

        // Avoid metadata contamination – create a plain duplicate.
        $insert_data = [
            'post_title'     => $title,
            'post_content'   => $content ?: $source->post_content,
            'post_excerpt'   => '',
            'post_status'    => $source->post_status,
            'post_type'      => $source->post_type,
            'post_name'      => $slug ?: ( $source->post_name . '-' . $this->secondary_lang ),
            'post_author'    => $source->post_author,
            'menu_order'     => $source->menu_order,
        ];

        $new_id = wp_insert_post( $insert_data, true );

        if ( is_wp_error( $new_id ) ) {
            $this->logger->error( "[multilingual] Failed creating translated post ({$this->secondary_lang}): " . $new_id->get_error_message() );
            return 0;
        }

        // Copy Yoast SEO meta for secondary language.
        $ar_meta_title = get_post_meta( (int) $source->ID, '_octowoo_metatitle_ar', true );
        $ar_meta_desc  = get_post_meta( (int) $source->ID, '_octowoo_metadesc_ar',  true );
        if ( $ar_meta_title ) {
            update_post_meta( $new_id, '_yoast_wpseo_title',   $ar_meta_title );
        }
        if ( $ar_meta_desc ) {
            update_post_meta( $new_id, '_yoast_wpseo_metadesc', $ar_meta_desc );
        }

        // Mark as a translation.
        update_post_meta( $new_id, '_octowoo_translation_of', $source->ID );
        update_post_meta( $new_id, '_octowoo_translation_lang', $this->secondary_lang );

        return (int) $new_id;
    }

    /**
     * Set language and link the post pair with WPML or Polylang.
     */
    private function linkPostTranslation( int $primary_id, int $translated_id, string $post_type ): void {
        $element_type = 'post_' . $post_type;

        if ( $this->adapter === 'wpml' ) {
            $this->wpmlSetPostLanguage( $primary_id,    $element_type, $this->primary_lang,   null );
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
     * Iterate every product_cat term that was migrated and create secondary
     * language counterparts.
     *
     * @return int[] [processed, skipped, failed]
     */
    private function translateTerms( string $taxonomy ): array {
        global $wpdb;

        $entity_type = 'category';

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
            $primary_term_id = (int) $row['wc_id'];

            if ( $this->translationExists( $primary_term_id, "tax_{$taxonomy}" ) ) {
                $skipped++;
                continue;
            }

            $ar_name        = get_term_meta( $primary_term_id, '_octowoo_name_ar',        true );
            $ar_description = get_term_meta( $primary_term_id, '_octowoo_description_ar', true );

            if ( ! $ar_name ) {
                $skipped++;
                continue;
            }

            $primary_term = get_term( $primary_term_id, $taxonomy );
            if ( ! $primary_term || is_wp_error( $primary_term ) ) {
                $failed++;
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

            $this->logger->debug( "[multilingual] Linked {$taxonomy} term #{$primary_term_id} ({$this->primary_lang}) ↔ #{$translated_term_id} ({$this->secondary_lang})" );
            $processed++;
        }

        return [ $processed, $skipped, $failed ];
    }

    /**
     * Create a translated taxonomy term in the secondary language.
     */
    private function createTranslatedTerm( \WP_Term $source, string $name, string $description, string $taxonomy ): int {
        $slug = $this->toSlug( $name );

        // Ensure unique slug.
        $slug = $slug ?: ( $source->slug . '-' . $this->secondary_lang );

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
        $ar_meta_title = get_term_meta( $source->term_id, '_octowoo_metatitle_ar', true );
        $ar_meta_desc  = get_term_meta( $source->term_id, '_octowoo_metadesc_ar',  true );
        if ( $ar_meta_title ) {
            update_term_meta( $translated_term_id, '_yoast_wpseo_title',   $ar_meta_title );
        }
        if ( $ar_meta_desc ) {
            update_term_meta( $translated_term_id, '_yoast_wpseo_metadesc', $ar_meta_desc );
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
            $this->wpmlSetTermLanguage( $primary_term,      $element_type, $this->primary_lang,   null );
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
        if ( $this->adapter === 'wpml' ) {
            $translated = apply_filters(
                'wpml_object_id',
                $element_id,
                str_replace( [ 'post_', 'tax_' ], '', $element_type ),
                false,
                $this->secondary_lang
            );
            return $translated && (int) $translated !== $element_id;
        }

        if ( $this->adapter === 'polylang' ) {
            if ( strpos( $element_type, 'post_' ) === 0 && function_exists( 'pll_get_post' ) ) {
                $translated = pll_get_post( $element_id, $this->secondary_lang );
                return ! empty( $translated ) && (int) $translated !== $element_id;
            }
            if ( strpos( $element_type, 'tax_' ) === 0 && function_exists( 'pll_get_term' ) ) {
                $translated = pll_get_term( $element_id, $this->secondary_lang );
                return ! empty( $translated ) && (int) $translated !== $element_id;
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
            return ! empty( $existing );
        }

        return false;
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
