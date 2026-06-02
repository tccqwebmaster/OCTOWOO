<?php
declare( strict_types=1 );

namespace OctoWoo\Tests\Unit;

use OctoWoo\Core\TaxonomyTools;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the dedupe scramble-guard and the WPML reversed-input guard — the
 * two checks that prevent catastrophic data damage (merging different brands;
 * flipping an entire language).
 */
final class TaxonomyGuardTest extends TestCase {

	// ── Scramble detection ────────────────────────────────────────────────

	public function test_clean_duplicate_group_is_not_scrambled(): void {
		// Two rows, same name, same slug, slug not used by any other name.
		$group = [ [ 'slug' => 'amazon-supplier-in-qatar' ], [ 'slug' => 'amazon-supplier-in-qatar' ] ];
		$used  = static fn( string $slug ): bool => false; // no other name uses it.
		$this->assertFalse( TaxonomyTools::isScrambledGroup( $group, $used ) );
	}

	public function test_scrambled_group_is_detected(): void {
		// A row's slug is also used by a DIFFERENT name → must be flagged scrambled.
		$group = [ [ 'slug' => 'pokemon-supplier-in-qatar' ] ];
		$used  = static fn( string $slug ): bool => $slug === 'pokemon-supplier-in-qatar';
		$this->assertTrue(
			TaxonomyTools::isScrambledGroup( $group, $used ),
			'A slug shared across different names must be treated as scrambled'
		);
	}

	public function test_temp_slug_never_triggers_scramble(): void {
		// ow-t- temp slugs are expected duplicates, never "scrambled".
		$group = [ [ 'slug' => 'ow-t-37202-1780309647' ] ];
		$used  = static fn( string $slug ): bool => true; // even if "used", temp is ignored.
		$this->assertFalse( TaxonomyTools::isScrambledGroup( $group, $used ) );
	}

	public function test_empty_slug_never_triggers_scramble(): void {
		$group = [ [ 'slug' => '' ] ];
		$used  = static fn( string $slug ): bool => true;
		$this->assertFalse( TaxonomyTools::isScrambledGroup( $group, $used ) );
	}

	public function test_is_temp_slug(): void {
		$this->assertTrue( TaxonomyTools::isTempSlug( 'ow-t-37202-1780309647' ) );
		$this->assertFalse( TaxonomyTools::isTempSlug( 'gaming-in-qatar' ) );
		$this->assertFalse( TaxonomyTools::isTempSlug( 'ow-t-abc' ) );
	}

	// ── Arabic detection ──────────────────────────────────────────────────

	public function test_has_arabic(): void {
		$this->assertTrue( TaxonomyTools::hasArabic( 'ساعات' ) );
		$this->assertTrue( TaxonomyTools::hasArabic( 'Mix العاب Mix' ) );
		$this->assertFalse( TaxonomyTools::hasArabic( 'Gaming' ) );
		$this->assertFalse( TaxonomyTools::hasArabic( '电子产品' ) );
	}

	// ── Reversed-input guard (generalized, not English-specific) ──────────

	public function test_english_primary_with_arabic_named_primary_is_reversed(): void {
		// primary locale en, primary term Arabic-named, secondary English-named → reversed.
		$this->assertSame( 'reversed', TaxonomyTools::reversedInputCheck( 'en', true, false ) );
	}

	public function test_german_primary_with_arabic_named_primary_is_reversed(): void {
		// The bug fix: guard must protect NON-English primaries too.
		$this->assertSame( 'reversed', TaxonomyTools::reversedInputCheck( 'de', true, false ) );
	}

	public function test_normal_english_primary_is_ok(): void {
		// primary English-named, secondary Arabic-named → correct, allow.
		$this->assertSame( 'ok', TaxonomyTools::reversedInputCheck( 'en', false, true ) );
	}

	public function test_arabic_primary_locale_allows_arabic_named_primary(): void {
		// A store whose PRIMARY language is Arabic: an Arabic-named primary is correct.
		$this->assertSame( 'ok', TaxonomyTools::reversedInputCheck( 'ar', true, false ) );
	}

	public function test_both_non_arabic_is_ok(): void {
		$this->assertSame( 'ok', TaxonomyTools::reversedInputCheck( 'en', false, false ) );
	}
}
