<?php
/**
 * Default plugin configuration.
 *
 * Values here are used as fallbacks when no settings are saved in the DB.
 * The admin UI writes to option key 'octowoo_config' which overrides these.
 */

defined( 'ABSPATH' ) || exit;

return [

    // ── Source mode ───────────────────────────────────────────────────────────
    // 'remote' = live DB connection | 'local' = uploaded SQL dump
    'source' => 'remote',

    // ── OpenCart Database Connection ──────────────────────────────────────────
    'db' => [
        'host'     => '',
        'port'     => 3306,
        'database' => '',
        'username' => '',
        'password' => '',
        'prefix'   => 'oc_',    // Table prefix in OpenCart DB
        // Unix socket path (leave blank to use TCP host/port above).
        // Required when MySQL root uses auth_socket authentication (error 1698).
        // Example: /var/run/mysqld/mysqld.sock  or  /tmp/mysql.sock
        'socket'   => '',
    ],

    // ── OpenCart Installation ─────────────────────────────────────────────────
    'opencart' => [
        // Absolute path to OpenCart's /image/ directory.
        // When image_source = 'local', this is ignored and the extracted
        // upload directory is used instead.
        'image_path' => '',

        // 'remote' = copy from image_path | 'local' = use uploaded ZIP extraction
        'image_source' => 'remote',

        // Absolute path to the /system/storage/download/ directory.
        // Leave blank to auto-detect (derived from image_path).
        'download_path' => '',

        // Public URL of the OpenCart shop (used to build redirect rules).
        // Example: https://old-shop.com
        'shop_url' => '',

        // Default OpenCart language ID to use when fetching descriptions (1 = English).
        'language_id' => 1,

        // Second language ID (e.g. Arabic = 2). Set 0 to disable.
        'language_id_secondary' => 2,

        // OC version override: 'auto' | '1' | '2' | '3' | '4'.
        // 'auto' = detected at runtime via VersionDetector.
        'version' => 'auto',
    ],

    // ── Migration Behaviour ───────────────────────────────────────────────────
    'migration' => [
        // Items processed per batch (also used as chunk size in chunked AJAX mode).
        // Lower = safer on shared/managed hosting (avoids PHP timeout).
        // Recommended: 10–30 for products, up to 100 for simple entities like categories.
        'batch_size' => 20,

        // Dry-run: simulate all steps without writing anything to the DB.
        'dry_run' => false,

        // Demo limit: when > 0, each migrator stops after this many items.
        // Useful for a quick sanity-check before committing to a full run.
        // 0 = unlimited (normal operation).
        'demo_limit' => 0,

        // What to do when a duplicate is detected: 'skip' | 'update'
        'on_duplicate' => 'skip',

        // Enable / disable individual migrators.
        'run_tax'           => true,
        'run_order_statuses'=> true,
        'run_categories'    => true,
        'run_products'      => true,
        'run_related'       => true,
        'run_bundles'       => false,  // Requires "WooCommerce Product Bundles" plugin (SomewhereWarm).
        'run_images'        => true,
        'run_customers'     => true,
        'run_orders'        => true,
        'run_coupons'       => true,
        'run_seo'           => true,
        'run_information'   => true,
        'run_manufacturers' => true,
        'run_tags'          => true,
        'run_filters'       => true,
        'run_downloads'     => true,
        'run_reviews'       => true,
        // 'multilingual' is controlled by the multilingual section below.
    ],

    // ── SEO / Redirects ───────────────────────────────────────────────────────
    'seo' => [
        // Write 301 redirect rules into .htaccess managed block.
        'write_htaccess' => true,

        // Also register redirects as WordPress rewrite rules (fallback).
        'use_wp_redirects' => true,
    ],

    // ── Logging ───────────────────────────────────────────────────────────────
    'logging' => [
        // Write log entries to file in /logs/ directory.
        'file_enabled' => true,

        // Write log entries to the octowoo_logs DB table.
        'db_enabled' => true,

        // Minimum log level written: DEBUG | INFO | WARNING | ERROR
        'min_level' => 'INFO',

        // Max log file size in bytes before rotation (default 10 MB).
        'max_file_size' => 10 * 1024 * 1024,
    ],

    // ── WooCommerce Target ────────────────────────────────────────────────────
    'woocommerce' => [
        // Default order status when OC status cannot be mapped.
        'default_order_status' => 'pending',

        // Default customer role for imported customers.
        'customer_role' => 'customer',

        // Force password reset for all imported customers (recommended
        // because OC password hashes are incompatible with WP).
        'force_password_reset' => true,
        // Attempt to verify the OC sha1(md5(salt+password)) hash on the
        // customer’s first login, then transparently upgrade to WP phpass.
        'migrate_oc_passwords' => false,
    ],

    // ── Multilingual (WPML / Polylang) ──────────────────────────────────
    'multilingual' => [
        // Master switch. Requires WPML or Polylang to be installed.
        'enabled'          => false,

        // Prefer WPML when both plugins are active.
        'use_wpml'         => true,

        // Prefer Polylang when WPML is not active.
        'use_polylang'     => false,

        // WordPress language code for the primary language (e.g. ‘en’, ‘en_US’).
        'primary_locale'   => 'en',

        // WordPress language code for the secondary/translated language.
        'secondary_locale' => 'ar',
    ],

    // ── Cron / Dropshipping Auto-Import ────────────────────────────────
    'cron' => [
        // Enable WP-Cron based auto-import (delta migration).
        'enabled'   => false,

        // Schedule interval: 'hourly' | 'twicedaily' | 'daily' | 'every30min' | 'every6hours'
        'interval'  => 'daily',

        // Comma-separated migrators to run on each cron tick.
        'migrators' => 'products,images,orders',    ],
];
