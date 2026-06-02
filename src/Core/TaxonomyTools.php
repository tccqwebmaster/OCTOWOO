<?php
/**
 * TaxonomyTools — pure, dependency-free helpers for taxonomy logic.
 *
 * These functions contain the migration's most safety-critical decisions
 * (Arabic/Unicode-preserving slugs and scrambled-duplicate detection). They are
 * deliberately free of any WordPress or database dependency so they can be unit
 * tested in isolation and reused identically by the migrators, the CLI, and the
 * AJAX cleanup handlers — guaranteeing all three behave the same way.
 */

namespace OctoWoo\Core;

defined( 'ABSPATH' ) || exit;

final class TaxonomyTools {

	/**
	 * Convert a name to an Arabic/Unicode-preserving slug.
	 *
	 * WordPress's sanitize_title() percent-encodes Arabic ("ساعة" →
	 * "%d8%b3%d8%a7%d8%b9%d8%a9"), producing unreadable URLs and breaking the
	 * shared-slug WPML pairing. This keeps Unicode letters/digits intact, turns
	 * whitespace (incl. zero-width chars) into hyphens, strips unsafe characters,
	 * collapses repeats, and trims. Returns '' for empty/garbage input so callers
	 * can decide on a fallback (the previous random suffix made output
	 * non-deterministic and therefore untestable and non-idempotent).
	 */
	public static function cleanSlug( string $text ): string {
		$slug = trim( $text );
		// Lowercase. Prefer mb_strtolower (handles multibyte); fall back to a
		// Unicode-aware regex if the mbstring extension is unavailable so the
		// helper never fatals on a minimal PHP build.
		if ( \function_exists( 'mb_strtolower' ) ) {
			$slug = \mb_strtolower( $slug, 'UTF-8' );
		} else {
			$slug = (string) \preg_replace_callback(
				'/[A-Z]+/',
				static fn( array $m ): string => strtolower( $m[0] ),
				$slug
			);
		}
		// Whitespace and zero-width separators → hyphen.
		$slug = (string) \preg_replace( '/[\s\x{200B}\x{200C}\x{200D}\x{FEFF}]+/u', '-', $slug );
		// Keep only Unicode letters, numbers, hyphen and dot.
		$slug = (string) \preg_replace( '/[^\p{L}\p{N}\-\.]/u', '', $slug );
		// Collapse repeated hyphens.
		$slug = (string) \preg_replace( '/-{2,}/', '-', $slug );
		return trim( $slug, '-' );
	}

	/**
	 * Does a string contain Arabic-script characters?
	 */
	public static function hasArabic( string $s ): bool {
		return (bool) \preg_match( '/[\x{0600}-\x{06FF}]/u', $s );
	}

	/**
	 * Detect a "scrambled" duplicate group.
	 *
	 * Given a set of terms that share the same NAME, decide whether they are SAFE
	 * to merge. They are NOT safe (scrambled) when any non-temp slug in the group
	 * is also used by a DIFFERENT name elsewhere — that means distinct brands /
	 * categories are wrongly sharing a slug, and merging would destroy real data.
	 * This is the exact guard that prevented merging different brands.
	 *
	 * @param array<int,array{slug:string}> $group        Rows sharing one name (each has 'slug').
	 * @param callable                      $slugUsedByOther fn(string $slug): bool — true if the
	 *                                                     slug is used by a different name.
	 * @return bool True if the group is scrambled and must be skipped.
	 */
	public static function isScrambledGroup( array $group, callable $slugUsedByOther ): bool {
		foreach ( $group as $row ) {
			$slug = (string) ( $row['slug'] ?? '' );
			if ( $slug === '' || strncmp( $slug, 'ow-t-', 5 ) === 0 ) {
				continue; // empty or temp slug never indicates scrambling.
			}
			if ( $slugUsedByOther( $slug ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is this an OctoWoo temporary slug (ow-t-<id>-<ts>)?
	 */
	public static function isTempSlug( string $slug ): bool {
		return (bool) \preg_match( '/^ow-t-\d+-\d+$/', $slug );
	}

	/**
	 * Decide the correct WPML language slot for a term, given the configured
	 * primary/secondary locales and whether the term's NAME is Arabic-script.
	 *
	 * Returns 'reversed' when the inputs look swapped (a non-Arabic-script primary
	 * locale paired with an Arabic-named primary term while the secondary is not
	 * Arabic-named) — the caller should then ABORT linking rather than corrupt
	 * language data. This is the generalized (non-English-specific) guard.
	 *
	 * @return string 'ok' | 'reversed'
	 */
	public static function reversedInputCheck(
		string $primaryLocale,
		bool $primaryNameIsArabic,
		bool $secondaryNameIsArabic
	): string {
		$primaryIsArabicLocale = ( strncmp( $primaryLocale, 'ar', 2 ) === 0 );
		if ( ! $primaryIsArabicLocale && $primaryNameIsArabic && ! $secondaryNameIsArabic ) {
			return 'reversed';
		}
		return 'ok';
	}
}
