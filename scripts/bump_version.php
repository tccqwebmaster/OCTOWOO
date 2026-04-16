<?php
// Bump version script for OctoWoo
// Usage:
// php bump_version.php [major|minor|patch|x.y.z] [--commit]

if (PHP_SAPI !== 'cli') {
    echo "Run from CLI.\n";
    exit(1);
}

$arg = $argv[1] ?? 'patch';
$commit = in_array('--commit', $argv, true);

$root = dirname(__DIR__);
$phpFile = $root . DIRECTORY_SEPARATOR . 'octowoo.php';
$composerFile = $root . DIRECTORY_SEPARATOR . 'composer.json';

if (!file_exists($phpFile) || !file_exists($composerFile)) {
    echo "Cannot locate project files. Run this from the plugin root/scripts folder.\n";
    exit(1);
}

$contents = file_get_contents($phpFile);
if (!preg_match("/define\(\s*'OCTOWOO_VERSION'\s*,\s*'([0-9]+\.[0-9]+\.[0-9]+)'\s*\)/", $contents, $m)) {
    echo "Could not detect current version in octowoo.php\n";
    exit(1);
}
$current = $m[1];

function incr_version($ver, $part) {
    [$maj,$min,$patch] = array_map('intval', explode('.', $ver));
    if ($part === 'major') { $maj++; $min = 0; $patch = 0; }
    elseif ($part === 'minor') { $min++; $patch = 0; }
    else { $patch++; }
    return "$maj.$min.$patch";
}

if (in_array($arg, ['major','minor','patch'], true)) {
    $new = incr_version($current, $arg);
} elseif (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $arg)) {
    $new = $arg;
} else {
    echo "Invalid argument. Use major|minor|patch or explicit x.y.z\n";
    exit(1);
}

// Update octowoo.php
$newContents = preg_replace(
    "/(define\(\s*'OCTOWOO_VERSION'\s*,\s*')([0-9]+\.[0-9]+\.[0-9]+)('\s*\))/",
    "\\1{$new}\\3",
    $contents,
    1,
    $count
);
if ($count === 0) {
    echo "Failed to replace version in octowoo.php\n";
    exit(1);
}
file_put_contents($phpFile, $newContents);

// Update composer.json (add or replace version)
$comp = json_decode(file_get_contents($composerFile), true);
if (!is_array($comp)) {
    echo "Invalid composer.json\n";
    exit(1);
}
$comp['version'] = $new;
file_put_contents($composerFile, json_encode($comp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "Version updated: {$current} -> {$new}\n";

if ($commit) {
    // Try to run git commands; ignore failures but report them.
    chdir($root);
    $cmds = [
        'git add octowoo.php composer.json',
        'git commit -m "Bump version to ' . $new . '"',
        'git tag v' . $new,
        'git push origin --tags',
        'git push origin HEAD'
    ];
    foreach ($cmds as $c) {
        echo "Running: {$c}\n";
        passthru($c, $rc);
        if ($rc !== 0) {
            echo "Command failed (rc={$rc}): {$c}\n";
        }
    }
}

exit(0);
