<?php
/**
 * OctoWoo version bump script.
 *
 * Updates ALL version references in one pass:
 *   1. octowoo.php  → plugin header  "* Version: X.Y.Z"
 *   2. octowoo.php  → constant       define('OCTOWOO_VERSION', 'X.Y.Z')
 *   3. readme.txt   → "Stable tag: X.Y.Z"
 *   4. readme.txt   → "WC tested up to:" (optional prompt)
 *   5. composer.json → "version": "X.Y.Z"
 *
 * Usage (from plugin root):
 *   php scripts/bump_version.php patch           # e.g. 2.4.68 → 2.4.69
 *   php scripts/bump_version.php minor           # e.g. 2.4.68 → 2.5.0
 *   php scripts/bump_version.php major           # e.g. 2.4.68 → 3.0.0
 *   php scripts/bump_version.php 2.4.70          # explicit version
 *   php scripts/bump_version.php patch --commit  # bump + git commit + tag
 */

if ( PHP_SAPI !== 'cli' ) {
	echo "Run from CLI.\n";
	exit( 1 );
}

$arg    = $argv[1] ?? 'patch';
$commit = in_array( '--commit', $argv, true );

// Resolve root: script lives in scripts/ subdirectory.
$root         = dirname( __DIR__ );
$php_file     = $root . DIRECTORY_SEPARATOR . 'octowoo.php';
$composer_file = $root . DIRECTORY_SEPARATOR . 'composer.json';
$readme_file  = $root . DIRECTORY_SEPARATOR . 'readme.txt';

foreach ( [ $php_file, $composer_file, $readme_file ] as $f ) {
	if ( ! file_exists( $f ) ) {
		echo "Cannot locate: {$f}\n";
		echo "Run this script from the plugin root or scripts/ subdirectory.\n";
		exit( 1 );
	}
}

// ── Detect current version from constant (most reliable) ─────────────────────
$contents = file_get_contents( $php_file );
if ( ! preg_match( "/define\(\s*'OCTOWOO_VERSION'\s*,\s*'([0-9]+\.[0-9]+\.[0-9]+)'\s*\)/", $contents, $m ) ) {
	echo "Could not detect current version constant in octowoo.php\n";
	exit( 1 );
}
$current = $m[1];

// ── Calculate new version ─────────────────────────────────────────────────────
function incr_version( string $ver, string $part ): string {
	list( $maj, $min, $patch ) = array_map( 'intval', explode( '.', $ver ) );
	if ( 'major' === $part ) {
		++$maj;
		$min   = 0;
		$patch = 0;
	} elseif ( 'minor' === $part ) {
		++$min;
		$patch = 0;
	} else {
		++$patch;
	}
	return "{$maj}.{$min}.{$patch}";
}

if ( in_array( $arg, array( 'major', 'minor', 'patch' ), true ) ) {
	$new = incr_version( $current, $arg );
} elseif ( preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/', $arg ) ) {
	$new = $arg;
} else {
	echo "Invalid argument. Use major|minor|patch or an explicit x.y.z version.\n";
	exit( 1 );
}

echo "Bumping: {$current} → {$new}\n";

// ── 1+2. Update octowoo.php ───────────────────────────────────────────────────
// Replace plugin header line: " * Version: X.Y.Z"
$new_contents = preg_replace(
	'/(\*\s+Version:\s+)[0-9]+\.[0-9]+\.[0-9]+/',
	'${1}' . $new,
	$contents,
	1,
	$count_header
);

if ( $count_header === 0 ) {
	echo "WARNING: Could not find '* Version:' header line in octowoo.php\n";
	$new_contents = $contents; // Proceed without this replacement.
}

// Replace constant: define('OCTOWOO_VERSION', 'X.Y.Z')
$new_contents = preg_replace(
	"/(define\(\s*'OCTOWOO_VERSION'\s*,\s*')[0-9]+\.[0-9]+\.[0-9]+('\s*\))/",
	'${1}' . $new . '${2}',
	$new_contents,
	1,
	$count_const
);

if ( $count_const === 0 ) {
	echo "ERROR: Could not replace OCTOWOO_VERSION constant in octowoo.php\n";
	exit( 1 );
}

file_put_contents( $php_file, $new_contents );
echo "  ✔ octowoo.php  — header + constant updated\n";

// ── 3. Update readme.txt ──────────────────────────────────────────────────────
$readme = file_get_contents( $readme_file );

// Stable tag.
$readme = preg_replace(
	'/^(Stable tag:\s*)[0-9]+\.[0-9]+\.[0-9]+/m',
	'${1}' . $new,
	$readme,
	1,
	$count_stable
);

if ( $count_stable === 0 ) {
	echo "WARNING: Could not find 'Stable tag:' in readme.txt\n";
}

file_put_contents( $readme_file, $readme );
echo "  ✔ readme.txt   — Stable tag updated\n";

// ── 4. Update composer.json ───────────────────────────────────────────────────
$comp = json_decode( file_get_contents( $composer_file ), true );
if ( ! is_array( $comp ) ) {
	echo "WARNING: Invalid composer.json — skipping.\n";
} else {
	$comp['version'] = $new;
	file_put_contents( $composer_file, json_encode( $comp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	echo "  ✔ composer.json — version updated\n";
}

// ── 5. Optional git commit + tag ──────────────────────────────────────────────
if ( $commit ) {
	chdir( $root );
	$cmds = array(
		"git add octowoo.php composer.json readme.txt",
		"git commit -m \"Bump version to {$new}\"",
		"git tag v{$new}",
		'git push origin HEAD',
		'git push origin --tags',
	);
	foreach ( $cmds as $c ) {
		echo "  Running: {$c}\n";
		passthru( $c, $rc );
		if ( $rc !== 0 ) {
			echo "  ⚠ Command failed (rc={$rc}): {$c}\n";
		}
	}
}

echo "\nDone. New version: {$new}\n";
exit( 0 );
