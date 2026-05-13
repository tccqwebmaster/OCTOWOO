<?php
/**
 * Rank Math SEO meta writer.
 *
 * Detects whether Rank Math SEO is active and writes the correct post/term
 * meta keys alongside existing Yoast SEO keys. Both SEO plugins can coexist.
 *
 * Usage (in any migrator):
 *   use OctoWoo\Core\RankMathHelper;
 *   then: $this->writeRankMathPostMeta($post_id, $title, $desc, $focus_kw);
 *         $this->writeRankMathTermMeta($term_id, $title, $desc, $focus_kw);
 *
 * Detection: Rank Math registers the 'rank-math/rank-math.php' plugin file.
 *            We also check for the RankMath class as a fallback.
 *
 * @package OctoWoo\Core
 */

namespace OctoWoo\Core;

defined( 'ABSPATH' ) || exit;

class RankMathHelper {

	/** @var bool|null Cached detection result. */
	private static ?bool $is_active = null;

	/**
	 * Returns true when Rank Math SEO is active.
	 */
	public static function isActive(): bool {
		if ( self::$is_active !== null ) {
			return self::$is_active;
		}

		// Primary check: Rank Math main class.
		if ( class_exists( '\RankMath' ) || class_exists( '\RankMath\SEO' ) ) {
			self::$is_active = true;
			return true;
		}

		// Fallback: check the active plugins list.
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( $active as $plugin ) {
			if ( strpos( $plugin, 'rank-math' ) !== false || strpos( $plugin, 'seo-by-rank-math' ) !== false ) {
				self::$is_active = true;
				return true;
			}
		}

		self::$is_active = false;
		return false;
	}

	/**
	 * Write Rank Math SEO meta for a post (product or page).
	 *
	 * @param int         $post_id    WP post ID.
	 * @param string      $title      SEO title (can be empty — Rank Math uses post title as fallback).
	 * @param string      $desc       Meta description.
	 * @param string      $focus_kw   Focus keyword / primary keyword.
	 */
	public static function writePostMeta( int $post_id, string $title, string $desc, string $focus_kw ): void {
		if ( ! self::isActive() || $post_id <= 0 ) {
			return;
		}

		if ( $title ) {
			update_post_meta( $post_id, 'rank_math_title', sanitize_text_field( $title ) );
		}
		if ( $desc ) {
			update_post_meta( $post_id, 'rank_math_description', sanitize_textarea_field( $desc ) );
		}
		if ( $focus_kw ) {
			update_post_meta( $post_id, 'rank_math_focus_keyword', sanitize_text_field( $focus_kw ) );
		}

		// Rank Math canonical URL — leave empty to auto-generate.
		// Rank Math SEO score is calculated by Rank Math on save; we skip it.
	}

	/**
	 * Write Rank Math SEO meta for a taxonomy term (product category, brand, etc.).
	 *
	 * @param int    $term_id   WP term ID.
	 * @param string $taxonomy  Taxonomy name (e.g. 'product_cat').
	 * @param string $title     SEO title.
	 * @param string $desc      Meta description.
	 * @param string $focus_kw  Focus keyword.
	 */
	public static function writeTermMeta( int $term_id, string $taxonomy, string $title, string $desc, string $focus_kw ): void {
		if ( ! self::isActive() || $term_id <= 0 ) {
			return;
		}

		// Rank Math stores term SEO data in the term_meta table via update_term_meta.
		if ( $title ) {
			update_term_meta( $term_id, 'rank_math_title', sanitize_text_field( $title ) );
		}
		if ( $desc ) {
			update_term_meta( $term_id, 'rank_math_description', sanitize_textarea_field( $desc ) );
		}
		if ( $focus_kw ) {
			update_term_meta( $term_id, 'rank_math_focus_keyword', sanitize_text_field( $focus_kw ) );
		}
	}
}
