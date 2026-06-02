<?php
declare( strict_types=1 );

namespace OctoWoo\Tests\Unit;

use OctoWoo\Core\TaxonomyTools;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Arabic/Unicode-preserving slug logic.
 *
 * Regression target: sanitize_title() percent-encoded Arabic, producing slugs
 * like "%d8%b3..." which broke URLs and WPML shared-slug pairing. These tests
 * lock in the correct behaviour so it can never regress silently.
 */
final class SlugTest extends TestCase {

	public function test_english_slug_is_lowercased_and_hyphenated(): void {
		$this->assertSame( 'gaming-in-qatar', TaxonomyTools::cleanSlug( 'Gaming In Qatar' ) );
	}

	public function test_arabic_is_preserved_not_percent_encoded(): void {
		$slug = TaxonomyTools::cleanSlug( 'ساعة' );
		$this->assertSame( 'ساعة', $slug );
		$this->assertStringNotContainsString( '%', $slug, 'Arabic must not be percent-encoded' );
	}

	public function test_arabic_phrase_spaces_become_hyphens(): void {
		$this->assertSame( 'العاب-فيديو', TaxonomyTools::cleanSlug( 'العاب فيديو' ) );
	}

	public function test_cyrillic_is_preserved(): void {
		$slug = TaxonomyTools::cleanSlug( 'Привет Мир' );
		// The characters must always survive (not stripped, not percent-encoded).
		$this->assertStringNotContainsString( '%', $slug );
		$this->assertSame( 'привет-мир', mb_strtolower( $slug, 'UTF-8' ) );
		if ( ! function_exists( 'mb_strtolower' ) ) {
			$this->markTestSkipped( 'mbstring not available; full case-folding not asserted.' );
		}
	}

	public function test_cjk_is_preserved(): void {
		$this->assertSame( '电子产品', TaxonomyTools::cleanSlug( '电子产品' ) );
	}

	public function test_zero_width_characters_become_hyphens_then_collapse(): void {
		// Zero-width space between words should not survive as a literal char.
		$slug = TaxonomyTools::cleanSlug( "foo\u{200B}bar" );
		$this->assertSame( 'foo-bar', $slug );
	}

	public function test_unsafe_characters_are_stripped(): void {
		$this->assertSame( 'abc-def', TaxonomyTools::cleanSlug( 'abc/<>&!@#-def' ) );
	}

	public function test_repeated_and_edge_hyphens_collapse_and_trim(): void {
		$this->assertSame( 'a-b', TaxonomyTools::cleanSlug( '--a---b--' ) );
	}

	public function test_empty_or_garbage_returns_empty_string_for_caller_fallback(): void {
		$this->assertSame( '', TaxonomyTools::cleanSlug( '   ' ) );
		$this->assertSame( '', TaxonomyTools::cleanSlug( '###' ) );
	}

	public function test_is_deterministic(): void {
		// Same input must always give same output (idempotency / re-run safety).
		$a = TaxonomyTools::cleanSlug( 'Al Harameen ساعات' );
		$b = TaxonomyTools::cleanSlug( 'Al Harameen ساعات' );
		$this->assertSame( $a, $b );
	}
}
