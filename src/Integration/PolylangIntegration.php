<?php
/**
 * Polylang multilingual integration — v2.5.3 complete rebuild.
 *
 * Meta key contract (must match AbstractMigrator::secLangSuffix() pattern):
 *
 *   Products (post meta written by ProductMigrator):
 *     _octowoo_name{sfx}              — secondary title
 *     _octowoo_description{sfx}       — secondary long description
 *     _octowoo_short_description{sfx} — secondary short description
 *     _octowoo_metatitle{sfx}         — secondary SEO title
 *     _octowoo_metadesc{sfx}          — secondary SEO description
 *     _octowoo_metakw{sfx}            — secondary SEO focus keyword
 *     _octowoo_tag{sfx}               — secondary comma-separated tag string
 *
 *   Categories / Tags (term meta written by CategoryMigrator):
 *     _octowoo_name{sfx}              — secondary name
 *     _octowoo_description{sfx}       — secondary description
 *     _octowoo_metatitle{sfx}         — secondary SEO title
 *     _octowoo_metadesc{sfx}          — secondary SEO description
 *     _octowoo_metakw{sfx}            — secondary SEO focus keyword
 *
 *   Pages (post meta written by InformationMigrator):
 *     _octowoo_title{sfx}             — secondary title
 *     _octowoo_desc{sfx}              — secondary content
 *
 * Where {sfx} = '_' + strtolower(first segment of secondary_locale), e.g. '_ar'.
 *
 * @package OctoWoo\Integration
 */

namespace OctoWoo\Integration;

defined( 'ABSPATH' ) || exit;

use OctoWoo\Core\Logger;
use OctoWoo\Core\CheckpointManager;
use OctoWoo\Core\RankMathHelper;

class PolylangIntegration {

	/** @var Logger */
	private Logger $logger;

	/** @var CheckpointManager */
	private CheckpointManager $checkpoint;

	/** @var array<string, mixed> */
	private array $config;

	/** @var string e.g. 'en' */
	private string $primary_lang;

	/** @var string e.g. 'ar' */
	private string $secondary_lang;

	public function __construct( Logger $logger, CheckpointManager $checkpoint, array $config ) {
		$this->logger         = $logger;
		$this->checkpoint     = $checkpoint;
		$this->config         = $config;
		$this->primary_lang   = $config['multilingual']['primary_locale']  ?? 'en';
		$this->secondary_lang = $config['multilingual']['secondary_locale'] ?? 'ar';

		// Polylang uses short slugs: 'en_US' → 'en'.
		$this->primary_lang   = strtolower( explode( '_', $this->primary_lang   )[0] );
		$this->secondary_lang = strtolower( explode( '_', $this->secondary_lang )[0] );
	}

	// ── Entry point ───────────────────────────────────────────────────────────

	/**
	 * @return array{processed:int, skipped:int, failed:int}
	 */
	public function run(): array {
		if ( ! $this->isAvailable() ) {
			$this->logger->warning( '[polylang] Polylang plugin not active — skipping.' );
			return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
		}

		$this->logger->info( sprintf(
			'[polylang] Starting. Primary: %s / Secondary: %s / sfx: %s',
			$this->primary_lang, $this->secondary_lang, $this->sfx()
		) );

		$stats = [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];

		$this->mergeStats( $stats, $this->translateProducts() );
		$this->mergeStats( $stats, $this->translateCategories() );
		$this->mergeStats( $stats, $this->translatePages() );

		$this->logger->info( sprintf(
			'[polylang] Done. processed=%d skipped=%d failed=%d',
			$stats['processed'], $stats['skipped'], $stats['failed']
		) );

		return $stats;
	}

	// ── Products ──────────────────────────────────────────────────────────────

	private function translateProducts(): array {
		global $wpdb;
		$stats = [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
		$sfx   = $this->sfx();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT wc_id AS post_id, oc_id FROM {$wpdb->prefix}octowoo_id_map
             WHERE entity_type = 'product' ORDER BY oc_id ASC",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			$this->logger->info( '[polylang] No migrated products in id_map.' );
			return $stats;
		}

		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$oc_id   = (int) $row['oc_id'];

			// Tag primary language.
			$this->setPostLanguage( $post_id, $this->primary_lang );

			// ── Read ALL secondary-language meta stored by ProductMigrator ─────
			$sec_title      = (string) get_post_meta( $post_id, '_octowoo_name'              . $sfx, true );
			$sec_content    = (string) get_post_meta( $post_id, '_octowoo_description'       . $sfx, true );
			$sec_excerpt    = (string) get_post_meta( $post_id, '_octowoo_short_description' . $sfx, true );
			$sec_meta_title = (string) get_post_meta( $post_id, '_octowoo_metatitle'         . $sfx, true );
			$sec_meta_desc  = (string) get_post_meta( $post_id, '_octowoo_metadesc'          . $sfx, true );
			$sec_meta_kw    = (string) get_post_meta( $post_id, '_octowoo_metakw'            . $sfx, true );
			$sec_tag_raw    = (string) get_post_meta( $post_id, '_octowoo_tag'               . $sfx, true );

			if ( $sec_title === '' ) {
				$stats['skipped']++;
				continue;
			}

			$primary = get_post( $post_id );
			if ( ! $primary ) { $stats['failed']++; continue; }

			// Use primary fallbacks for empty secondary fields.
			if ( $sec_content === '' ) { $sec_content = $primary->post_content; }
			if ( $sec_excerpt === '' ) { $sec_excerpt = $primary->post_excerpt; }

			// ── Update existing translation ───────────────────────────────────
			$existing_id = (int) pll_get_post( $post_id, $this->secondary_lang );
			if ( $existing_id > 0 && get_post( $existing_id ) ) {
				wp_update_post( [
					'ID'           => $existing_id,
					'post_title'   => $sec_title,
					'post_content' => wp_kses_post( $sec_content ),
					'post_excerpt' => wp_kses_post( $sec_excerpt ),
					'post_status'  => $primary->post_status,
				] );
				$this->copyProductMeta( $post_id, $existing_id );
				$this->writeProductSeoMeta( $existing_id, $sec_meta_title, $sec_meta_desc, $sec_meta_kw );
				$this->assignSecondaryTags( $existing_id, $sec_tag_raw );
				$stats['processed']++;
				continue;
			}

			// ── Create new translation post ───────────────────────────────────
			$trans_id = wp_insert_post( [
				'post_type'    => 'product',
				'post_status'  => $primary->post_status,
				'post_title'   => $sec_title,
				'post_content' => wp_kses_post( $sec_content ),
				'post_excerpt' => wp_kses_post( $sec_excerpt ),
				'post_date'    => $primary->post_date,
				'menu_order'   => (int) $primary->menu_order,
			], true );

			if ( is_wp_error( $trans_id ) || ! $trans_id ) {
				$this->logger->error( "[polylang] Failed creating product translation for OC #{$oc_id}: "
					. ( is_wp_error( $trans_id ) ? $trans_id->get_error_message() : '0 returned' ) );
				$stats['failed']++;
				continue;
			}

			// Link via Polylang BEFORE copying meta (WPML field-sync can overwrite).
			$this->savePostTranslations( $post_id, $trans_id );

			// Copy all WC product meta.
			$this->copyProductMeta( $post_id, $trans_id );

			// Product type term (simple / variable / …).
			$type_terms = wp_get_object_terms( $post_id, 'product_type', [ 'fields' => 'names' ] );
			if ( ! is_wp_error( $type_terms ) && ! empty( $type_terms ) ) {
				wp_set_object_terms( $trans_id, $type_terms, 'product_type' );
			}

			// Secondary-language category terms.
			$cat_ids = wp_get_object_terms( $post_id, 'product_cat', [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $cat_ids ) && ! empty( $cat_ids ) ) {
				$sec_cat_ids = [];
				foreach ( array_map( 'intval', $cat_ids ) as $cat_id ) {
					$sec_cat = (int) pll_get_term( $cat_id, $this->secondary_lang );
					$sec_cat_ids[] = $sec_cat > 0 ? $sec_cat : $cat_id;
				}
				wp_set_object_terms( $trans_id, $sec_cat_ids, 'product_cat' );
			}

			// Secondary-language SEO meta.
			$this->writeProductSeoMeta( $trans_id, $sec_meta_title, $sec_meta_desc, $sec_meta_kw );

			// Secondary-language product tags.
			$this->assignSecondaryTags( $trans_id, $sec_tag_raw );

			// Shared featured image + gallery.
			$thumb_id = (int) get_post_thumbnail_id( $post_id );
			if ( $thumb_id > 0 ) { set_post_thumbnail( $trans_id, $thumb_id ); }

			$this->checkpoint->saveIdMap( 'product_sec', $oc_id, $trans_id );
			$this->logger->info( "[polylang] Created product translation #{$trans_id} ({$this->secondary_lang}) ← primary #{$post_id} (OC #{$oc_id})." );
			$stats['processed']++;
		}

		return $stats;
	}

	// ── Categories ────────────────────────────────────────────────────────────

	private function translateCategories(): array {
		global $wpdb;
		$stats = [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
		$sfx   = $this->sfx();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT wc_id AS term_id, oc_id FROM {$wpdb->prefix}octowoo_id_map
             WHERE entity_type = 'category' ORDER BY oc_id ASC",
			ARRAY_A
		);

		if ( empty( $rows ) ) { return $stats; }

		foreach ( $rows as $row ) {
			$term_id = (int) $row['term_id'];
			$oc_id   = (int) $row['oc_id'];

			pll_set_term_language( $term_id, $this->primary_lang );

			$sec_name       = (string) get_term_meta( $term_id, '_octowoo_name'        . $sfx, true );
			$sec_desc       = (string) get_term_meta( $term_id, '_octowoo_description' . $sfx, true );
			$sec_meta_title = (string) get_term_meta( $term_id, '_octowoo_metatitle'   . $sfx, true );
			$sec_meta_desc  = (string) get_term_meta( $term_id, '_octowoo_metadesc'    . $sfx, true );
			$sec_meta_kw    = (string) get_term_meta( $term_id, '_octowoo_metakw'      . $sfx, true );

			// Fallback: use primary name so Polylang still creates a translation link.
			if ( $sec_name === '' ) {
				$primary_term = get_term( $term_id, 'product_cat' );
				if ( ! $primary_term || is_wp_error( $primary_term ) ) { $stats['skipped']++; continue; }
				$sec_name = $primary_term->name;
			}

			// Update existing translation.
			$existing_id = (int) pll_get_term( $term_id, $this->secondary_lang );
			if ( $existing_id > 0 ) {
				wp_update_term( $existing_id, 'product_cat', [
					'name'        => sanitize_text_field( $sec_name ),
					'description' => wp_kses_post( $sec_desc ),
				] );
				$this->writeCategorySeoMeta( $existing_id, $sec_meta_title, $sec_meta_desc, $sec_meta_kw );
				$stats['processed']++;
				continue;
			}

			// Create new term.
			$result = wp_insert_term( sanitize_text_field( $sec_name ), 'product_cat', [
				'description' => wp_kses_post( $sec_desc ),
			] );

			if ( is_wp_error( $result ) ) {
				if ( $result->get_error_code() === 'term_exists' ) {
					$eid = (int) $result->get_error_data( 'term_exists' );
					if ( $eid > 0 ) {
						pll_set_term_language( $eid, $this->secondary_lang );
						$this->saveTermTranslations( $term_id, $eid );
						$this->writeCategorySeoMeta( $eid, $sec_meta_title, $sec_meta_desc, $sec_meta_kw );
						$stats['processed']++;
					} else { $stats['skipped']++; }
				} else {
					$this->logger->warning( "[polylang] Category term insert failed for OC #{$oc_id}: " . $result->get_error_message() );
					$stats['failed']++;
				}
				continue;
			}

			$new_id = (int) $result['term_id'];
			pll_set_term_language( $new_id, $this->secondary_lang );
			$this->saveTermTranslations( $term_id, $new_id );
			$this->writeCategorySeoMeta( $new_id, $sec_meta_title, $sec_meta_desc, $sec_meta_kw );
			$this->logger->info( "[polylang] Created category translation #{$new_id} ({$this->secondary_lang}) for OC #{$oc_id}." );
			$stats['processed']++;
		}

		return $stats;
	}

	// ── Pages ─────────────────────────────────────────────────────────────────

	private function translatePages(): array {
		global $wpdb;
		$stats = [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
		$sfx   = $this->sfx();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT wc_id AS post_id, oc_id FROM {$wpdb->prefix}octowoo_id_map
             WHERE entity_type = 'page' ORDER BY oc_id ASC",
			ARRAY_A
		);

		if ( empty( $rows ) ) { return $stats; }

		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$oc_id   = (int) $row['oc_id'];

			$this->setPostLanguage( $post_id, $this->primary_lang );

			// InformationMigrator writes _octowoo_title{sfx} + _octowoo_desc{sfx}.
			$sec_title   = (string) get_post_meta( $post_id, '_octowoo_title' . $sfx, true );
			$sec_content = (string) get_post_meta( $post_id, '_octowoo_desc'  . $sfx, true );

			if ( $sec_title === '' ) { $stats['skipped']++; continue; }

			$primary = get_post( $post_id );
			if ( $sec_content === '' && $primary ) { $sec_content = $primary->post_content; }

			$existing_id = (int) pll_get_post( $post_id, $this->secondary_lang );
			if ( $existing_id > 0 && get_post( $existing_id ) ) {
				wp_update_post( [
					'ID'           => $existing_id,
					'post_title'   => sanitize_text_field( $sec_title ),
					'post_content' => wp_kses_post( $sec_content ),
				] );
				$stats['processed']++;
				continue;
			}

			$trans_id = wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => sanitize_text_field( $sec_title ),
				'post_content' => wp_kses_post( $sec_content ),
			], true );

			if ( is_wp_error( $trans_id ) || ! $trans_id ) { $stats['failed']++; continue; }

			$this->savePostTranslations( $post_id, $trans_id );
			$this->logger->info( "[polylang] Created page translation #{$trans_id} ({$this->secondary_lang}) for OC #{$oc_id}." );
			$stats['processed']++;
		}

		return $stats;
	}

	// ── API wrappers ──────────────────────────────────────────────────────────

	private function isAvailable(): bool {
		return function_exists( 'pll_set_post_language' )
			&& function_exists( 'pll_set_term_language' )
			&& function_exists( 'pll_save_post_translations' )
			&& function_exists( 'pll_save_term_translations' )
			&& function_exists( 'pll_get_post' )
			&& function_exists( 'pll_get_term' );
	}

	/**
	 * The secondary-language meta key suffix.
	 * Matches AbstractMigrator::secLangSuffix() exactly.
	 */
	private function sfx(): string {
		$sec = $this->config['multilingual']['secondary_locale'] ?? 'ar';
		return '_' . strtolower( explode( '_', $sec )[0] );
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

	// ── Meta writers ──────────────────────────────────────────────────────────

	/**
	 * Copy all WooCommerce product meta from primary to translation.
	 * Does NOT copy post_title / post_content / post_excerpt (those are translated).
	 */
	private function copyProductMeta( int $from_id, int $to_id ): void {
		$keys = [
			'_price', '_regular_price', '_sale_price',
			'_sku', '_stock', '_stock_status', '_manage_stock', '_backorders', '_sold_individually',
			'_weight', '_length', '_width', '_height',
			'_virtual', '_downloadable',
			'_product_attributes',
			'_tax_status', '_tax_class',
			'_product_image_gallery',
			'_octowoo_oc_id', '_octowoo_oc_image_path',
		];

		foreach ( $keys as $key ) {
			$val = get_post_meta( $from_id, $key, true );
			update_post_meta( $to_id, $key, $val );
		}

		// Shared featured image (language-neutral in WordPress).
		$thumb_id = (int) get_post_thumbnail_id( $from_id );
		if ( $thumb_id > 0 ) {
			set_post_thumbnail( $to_id, $thumb_id );
		}
	}

	/**
	 * Write secondary-language SEO meta to a translated product post.
	 */
	private function writeProductSeoMeta( int $post_id, string $title, string $desc, string $kw ): void {
		if ( $title !== '' ) { update_post_meta( $post_id, '_yoast_wpseo_title',    $title ); }
		if ( $desc  !== '' ) { update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc  ); }
		if ( $kw    !== '' ) { update_post_meta( $post_id, '_yoast_wpseo_focuskw',  $kw    ); }
		RankMathHelper::writePostMeta( $post_id, $title, $desc, $kw );
	}

	/**
	 * Write secondary-language SEO meta to a translated category term.
	 */
	private function writeCategorySeoMeta( int $term_id, string $title, string $desc, string $kw ): void {
		if ( $title !== '' ) { update_term_meta( $term_id, '_yoast_wpseo_title',    $title ); }
		if ( $desc  !== '' ) { update_term_meta( $term_id, '_yoast_wpseo_metadesc', $desc  ); }
		if ( $kw    !== '' ) { update_term_meta( $term_id, '_yoast_wpseo_focuskw',  $kw    ); }
		RankMathHelper::writeTermMeta( $term_id, 'product_cat', $title, $desc, $kw );
	}

	/**
	 * Assign secondary-language product tags from a comma-separated string.
	 */
	private function assignSecondaryTags( int $post_id, string $tag_raw ): void {
		if ( $tag_raw === '' ) { return; }

		$tag_names = array_values( array_filter(
			array_map( 'sanitize_text_field', explode( ',', $tag_raw ) ),
			static fn( string $t ) => $t !== ''
		) );

		if ( empty( $tag_names ) ) { return; }

		$result = wp_set_object_terms( $post_id, $tag_names, 'product_tag', true );

		if ( is_wp_error( $result ) ) {
			$this->logger->warning( "[polylang] Tag assignment failed for post #{$post_id}: " . $result->get_error_message() );
		}
	}

	// ── Utility ───────────────────────────────────────────────────────────────

	private function mergeStats( array &$target, array $source ): void {
		$target['processed'] += $source['processed'];
		$target['skipped']   += $source['skipped'];
		$target['failed']    += $source['failed'];
	}
}
