# CartShift — Automated Tests

## Unit tests (no WordPress/MySQL required)

These exercise the migration's most safety-critical pure logic in isolation —
the Arabic/Unicode-preserving slug generator, the dedupe scramble-guard (which
prevents merging different brands), and the WPML reversed-input guard (which
prevents flipping an entire language). They use PHPUnit + Brain Monkey and run
without a live site.

```bash
composer install
composer test           # runs phpunit against tests/Unit
composer test-coverage  # HTML coverage report in ./coverage
```

Requirements: PHP 8.0+ with the `mbstring` extension (WordPress requires it too).

## What is covered

| Area | File | Why it matters |
|---|---|---|
| Arabic/Unicode slugs | tests/Unit/SlugTest.php | Regression guard: sanitize_title() percent-encoded Arabic and broke URLs + WPML shared slugs |
| Scramble detection | tests/Unit/TaxonomyGuardTest.php | Prevents merging two different brands that share a slug (data loss) |
| Reversed-input guard | tests/Unit/TaxonomyGuardTest.php | Prevents the catastrophic English→Arabic language flip; now language-agnostic |

The tested logic lives in `src/Core/TaxonomyTools.php`, a dependency-free helper
that the migrators, WP-CLI commands, and AJAX cleanup handlers all share — so a
single test run validates the behaviour used by every entry point.

## Future work (integration tests)

A separate `tests/Integration` suite using `wp-phpunit` against a real
WP + WooCommerce + WPML stack would cover the database-touching paths
(RestorePoint capture/restore, DataPurger, the full migrators). That requires a
provisioned test database and is tracked as a follow-up.
