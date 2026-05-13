<?php
/**
 * Polylang multilingual integration.
 *
 * Links migrated WooCommerce entities (products, categories, pages, tags)
 * to their secondary-language counterparts using the Polylang API.
 *
 * Executed as part of the 'multilingual' migrator pass (same pass that runs
 * WpmlIntegration) when:
 *   - config.multilingual.enabled = true
 *   - config.multilingual.use_polylang = true
 *   - Polylang plugin is active (pll_get_post_language() function exists)
 *
 * OpenCart secondary-language data is stored in _octowoo_* meta / term-meta by
 * the primary migrators (ProductMigrator, CategoryMigrator, etc.).
 *
 * @package OctoWoo\Integration
 */

namespace OctoWoo\Integration;

defined( 'ABSPATH' ) || exit;

use OctoWoo\Core\Logger;
use OctoWoo\Core\CheckpointManager;

class PolylangIntegration {

	/** @var Logger */
	private Logger $logger;

	/** @var CheckpointManager */
	private CheckpointManager $checkpoint;

	/** @var array<string, mixed> */
	private array $config;

	/** @var string Primary language slug, e.g. 'en' */
	private string $primary_lang;

	/** @var string Secondary language slug, e.g. 'ar' */
	private string $secondary_lang;

	public function __construct( Logger $logger, CheckpointManager $checkpoint, array $config ) {
		$this->logger         = $logger;
		$this->checkpoint     = $checkpoint;
		$this->config         = $config;
		$this->primary_lang   = $config['multilingual']['primary_locale']   ?? 'en';
		$this->secondary_lang = $config['multilingual']['secondary_locale']  ?? 'ar';

		// Normalise: Polylang uses short slugs (en, ar) not full locales (en_US, ar).
		$this->primary_lang   = strtolower( explode( '_', $this->primary_lang   )[0] );
		$this->secondary_lang = strtolower( explode( '_', $this->secondary_lang )[0] );
	}

	// ── Entry point ───────────────────────────────────────────────────────────

	/**
	 * @return array{processed:int, skipped:int, failed:int}
	 */
	public function run(): array {
		if ( ! $this->isAvailable() ) {
			$this->logger->warning( '[polylang] Polylang plugin not active — skipping multilingual pass.' );
			return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
		}

		$this->logger->info( sprintf(
			'[polylang] Starting translation pass. Primary: %s / Secondary: %s',
			$this->primary_lang,
			$this->secondary_lang
		) );

		$stats = [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];

		$this->mergeStats( $stats, $this->translateProducts() );
		$this->mergeStats( $stats, $this->translateCategories() );
		$this->mergeStats( $stats, $this->translatePages() );
		$this->mergeStats( $stats, $this->translateTags() );

		$this->logger->info( sprintf(
			'[polylang] Translation pass complete. processed=%d skipped=%d failed=%d',
			$stats['processed'], $stats['skipped'], $stats['failed']
		) );

		return $stats;
	}

	// ── Products ──────────────────────────────────────────────────────────────

	private function translateProducts(): array {
		global $wpdb;

		$stats = [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];

		// Fetch all primary products created by OctoWoo.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT wc_id AS post_id, oc_id FROM {$wpdb->prefix}octowoo_id_map
             WHERE entity_type = 'product' ORDER BY oc_id ASC",
			ARRAY_A
		);

		if ( ! $rows ) {
			return $stats;
		}

		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$oc_id   = (int) $row['oc_id'];

			// Assign primary language to the post.
			$this->setPostLanguage( $post_id, $this->primary_lang );

			// Retrieve secondary-language data stored by ProductMigrator.
			$sfx = $this->sfx();
			// InformationMigrator writes _octowoo_title{sfx} and _octowoo_desc{sfx}
			$sec_title   = (string) get_post_meta( $post_id, '_octowoo_title' . $sfx, true );
			$sec_content = (string) get_post_meta( $post_id, '_octowoo_desc'  . $sfx, true );
			$sec_excerpt = (string) get_post_meta( $post_id, '_octowoo_sec_excerpt', true );

			if ( ! $sec_title ) {
				$stats['skipped']++;
				continue; // No secondary-language data stored — skip.
			}

			// Check for existing Polylang translation.
			$existing_trans = (int) pll_get_post( $post_id, $this->secondary_lang );

			if ( $existing_trans > 0 && get_post( $existing_trans ) ) {
				// Update existing translation.
				wp_update_post( [
					'ID'           => $existing_trans,
					'post_title'   => sanitize_text_field( $sec_title ),
					'post_content' => wp_kses_post( $sec_content ),
					'post_excerpt' => sanitize_textarea_field( $sec_excerpt ),
				] );
				$stats['processed']++;
				continue;
			}

			// Create a new translated product post.
			$translated_id = wp_insert_post( [
				'post_type'    => 'product',
				'post_status'  => 'publish',
				'post_title'   => sanitize_text_field( $sec_title ),
				'post_content' => wp_kses_post( $sec_content ),
				'post_excerpt' => sanitize_textarea_field( $sec_excerpt ),
			] );

			if ( is_wp_error( $translated_id ) || ! $translated_id ) {
				$this->logger->warning( "[polylang] Could not create product translation for OC #{$oc_id}: " . ( is_wp_error( $translated_id ) ? $translated_id->get_error_message() : 'unknown' ) );
				$stats['failed']++;
				continue;
			}

			// Link the two posts as translations.
			$this->savePostTranslations( $post_id, $translated_id );

			// Copy WooCommerce product meta to the translation.
			$this->copyProductMeta( $post_id, $translated_id );

			// Write secondary-language SEO meta to the translated post.
			if ( ! empty( $sec_meta_title ) ) { update_post_meta( $translated_id, '_yoast_wpseo_title',    $sec_meta_title ); }
			if ( ! empty( $sec_meta_desc  ) ) { update_post_meta( $translated_id, '_yoast_wpseo_metadesc', $sec_meta_desc  ); }
			if ( ! empty( $sec_meta_kw    ) ) { update_post_meta( $translated_id, '_yoast_wpseo_focuskw',  $sec_meta_kw    ); }
			\OctoWoo\Core\RankMathHelper::writePostMeta( $translated_id, $sec_meta_title ?? '', $sec_meta_desc ?? '', $sec_meta_kw ?? '' );

			$this->checkpoint->saveIdMap( 'product_sec', $oc_id, $translated_id );

			$stats['processed']++;
		}

		return $stats;
	}

	// ── Categories ────────────────────────────────────────────────────────────

	private function translateCategories(): array {
		global $wpdb;

		$stats = [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT wc_id AS term_id, oc_id FROM {$wpdb->prefix}octowoo_id_map
             WHERE entity_type = 'category' ORDER BY oc_id ASC",
			ARRAY_A
		);

		if ( ! $rows ) {
			return $stats;
		}

		foreach ( $rows as $row ) {
			$term_id = (int) $row['term_id'];
			$oc_id   = (int) $row['oc_id'];

			// Assign primary language.
			pll_set_term_language( $term_id, $this->primary_lang );

			// Secondary-language data stored by CategoryMigrator.
			$sfx = $this->sfx();
			// Use SAME keys as CategoryMigrator writes
			$sec_name       = (string) get_term_meta( $term_id, '_octowoo_name' . $sfx,        true );
			$sec_desc       = (string) get_term_meta( $term_id, '_octowoo_description' . $sfx, true );
			$sec_meta_title = (string) get_term_meta( $term_id, '_octowoo_metatitle' . $sfx, true );
			$sec_meta_desc  = (string) get_term_meta( $term_id, '_octowoo_metadesc'  . $sfx, true );
			$sec_meta_kw    = (string) get_term_meta( $term_id, '_octowoo_metakw'    . $sfx, true );

			if ( ! $sec_name ) {
				$stats['skipped']++;
				continue;
			}

			// Check for existing translation.
			$existing_trans_id = (int) pll_get_term( $term_id, $this->secondary_lang );

			if ( $existing_trans_id > 0 ) {
				wp_update_term( $existing_trans_id, 'product_cat', [
					'name'        => sanitize_text_field( $sec_name ),
					'description' => wp_kses_post( $sec_desc ),
				] );
				$stats['processed']++;
				continue;
			}

			// Create translated term.
			$new_term = wp_insert_term( sanitize_text_field( $sec_name ), 'product_cat', [
				'description' => wp_kses_post( $sec_desc ),
			] );

			if ( is_wp_error( $new_term ) ) {
				$this->logger->warning( "[polylang] Could not create category translation for OC #{$oc_id}: " . $new_term->get_error_message() );
				$stats['failed']++;
				continue;
			}

			$new_term_id = (int) $new_term['term_id'];

			// Assign secondary language to the new term.
			pll_set_term_language( $new_term_id, $this->secondary_lang );

			// Link as translations.
			$this->saveTermTranslations( $term_id, $new_term_id );

			$stats['processed']++;
		}

		return $stats;
	}

	// ── Pages (Information pages) ─────────────────────────────────────────────

	private function translatePages(): array {
		global $wpdb;

		$stats = [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT wc_id AS post_id, oc_id FROM {$wpdb->prefix}octowoo_id_map
             WHERE entity_type = 'page' ORDER BY oc_id ASC",
			ARRAY_A
		);

		if ( ! $rows ) {
			return $stats;
		}

		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$oc_id   = (int) $row['oc_id'];

			$this->setPostLanguage( $post_id, $this->primary_lang );

			$sfx = $this->sfx();
			// InformationMigrator writes _octowoo_title{sfx} and _octowoo_desc{sfx}
			$sec_title   = (string) get_post_meta( $post_id, '_octowoo_title' . $sfx, true );
			$sec_content = (string) get_post_meta( $post_id, '_octowoo_desc'  . $sfx, true );

			if ( ! $sec_title ) {
				$stats['skipped']++;
				continue;
			}

			$existing = (int) pll_get_post( $post_id, $this->secondary_lang );

			if ( $existing > 0 ) {
				wp_update_post( [
					'ID'           => $existing,
					'post_title'   => sanitize_text_field( $sec_title ),
					'post_content' => wp_kses_post( $sec_content ),
				] );
				$stats['processed']++;
				continue;
			}

			$translated_id = wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => sanitize_text_field( $sec_title ),
				'post_content' => wp_kses_post( $sec_content ),
			] );

			if ( ! $translated_id || is_wp_error( $translated_id ) ) {
				$stats['failed']++;
				continue;
			}

			$this->savePostTranslations( $post_id, $translated_id );
			$stats['processed']++;
		}

		return $stats;
	}

	// ── Tags ──────────────────────────────────────────────────────────────────

	private function translateTags(): array {
		global $wpdb;

		$stats = [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT wc_id AS term_id, oc_id FROM {$wpdb->prefix}octowoo_id_map
             WHERE entity_type = 'tag' ORDER BY oc_id ASC",
			ARRAY_A
		);

		if ( ! $rows ) {
			return $stats;
		}

		foreach ( $rows as $row ) {
			$term_id = (int) $row['term_id'];
			$oc_id   = (int) $row['oc_id'];

			pll_set_term_language( $term_id, $this->primary_lang );

			$sfx = $this->sfx();
			// Use SAME keys as CategoryMigrator writes
			$sec_name       = (string) get_term_meta( $term_id, '_octowoo_name' . $sfx,        true );

			if ( ! $sec_name ) {
				$stats['skipped']++;
				continue;
			}

			$existing = (int) pll_get_term( $term_id, $this->secondary_lang );

			if ( $existing > 0 ) {
				$stats['skipped']++;
				continue;
			}

			$new_term = wp_insert_term( sanitize_text_field( $sec_name ), 'product_tag' );

			if ( is_wp_error( $new_term ) ) {
				$stats['failed']++;
				continue;
			}

			$new_term_id = (int) $new_term['term_id'];
			pll_set_term_language( $new_term_id, $this->secondary_lang );
			$this->saveTermTranslations( $term_id, $new_term_id );

			$stats['processed']++;
		}

		return $stats;
	}

	// ── Polylang API helpers ──────────────────────────────────────────────────

	private function isAvailable(): bool {
		return function_exists( 'pll_set_post_language' )
			&& function_exists( 'pll_set_term_language' )
			&& function_exists( 'pll_save_post_translations' )
			&& function_exists( 'pll_save_term_translations' );
	}

	private function setPostLanguage( int $post_id, string $lang ): void {
		if ( ! pll_get_post_language( $post_id ) ) {
			pll_set_post_language( $post_id, $lang );
		}
	}

	private function savePostTranslations( int $primary_id, int $secondary_id ): void {
		pll_set_post_language( $secondary_id, $this->secondary_lang );
		pll_save_post_translations( [
			$this->primary_lang   => $primary_id,
			$this->secondary_lang => $secondary_id,
		] );
	}

	private function saveTermTranslations( int $primary_id, int $secondary_id ): void {
		pll_set_term_language( $secondary_id, $this->secondary_lang );
		pll_save_term_translations( [
			$this->primary_lang   => $primary_id,
			$this->secondary_lang => $secondary_id,
		] );
	}

	/**
	 * Copy essential WooCommerce product meta from primary to translation.
	 */
	private function copyProductMeta( int $from_id, int $to_id ): void {
		$meta_keys = [
			'_price', '_regular_price', '_sale_price', '_sku', '_stock', '_stock_status',
			'_manage_stock', '_backorders', '_weight', '_length', '_width', '_height',
			'_product_attributes', '_virtual', '_downloadable', '_product_image_gallery',
		];
		foreach ( $meta_keys as $key ) {
			$val = get_post_meta( $from_id, $key, true );
			if ( $val !== '' && $val !== false ) {
				update_post_meta( $to_id, $key, $val );
			}
		}
		// Carry over the OctoWoo tag so purge can find translations.
		$oc_id = get_post_meta( $from_id, '_octowoo_oc_id', true );
		if ( $oc_id ) {
			update_post_meta( $to_id, '_octowoo_oc_id', $oc_id );
		}
		// Set the thumbnail from the primary.
		$thumb_id = get_post_thumbnail_id( $from_id );
		if ( $thumb_id ) {
			set_post_thumbnail( $to_id, $thumb_id );
		}
	}

	// ── Utility ───────────────────────────────────────────────────────────────

	private function mergeStats( array &$target, array $source ): void {
		$target['processed'] += $source['processed'];
		$target['skipped']   += $source['skipped'];
		$target['failed']    += $source['failed'];
	}
}
