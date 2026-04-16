# OCTOWOO – Project Overview

**Version:** 2.3.2  
**Type:** WordPress / WooCommerce Plugin  
**Purpose:** Migrate an OpenCart store (v1/2/3/4) into WooCommerce with full data parity.

---

## 1. Project Concept

OctoWoo is a production-grade, self-hosted WordPress plugin that reads an OpenCart database (either a live remote connection or an uploaded SQL dump) and reproduces the entire store inside WooCommerce. It covers every major entity: products, categories, customers, orders, coupons, tax classes, images, reviews, filters, manufacturers (brands), bundles, downloadable files, static pages, SEO URLs, and multi-language content.

The plugin is designed to be safe to run incrementally:
- **Batch processing** prevents PHP/MySQL timeouts.
- **Checkpoint/resume** lets an interrupted migration continue from the exact row it stopped at.
- **Dry-run mode** simulates the migration without writing anything to the database.
- **Duplicate detection** (skip or update strategy) prevents data duplication on re-runs.
- **Data purger** can roll back migrated data entity by entity.
- **WP-CLI support** enables server-side automation.
- **Cron auto-import** allows scheduled delta syncs.

---

## 2. Requirements

| Dependency | Minimum Version |
|---|---|
| WordPress | 5.8+ |
| PHP | 7.4+ |
| WooCommerce | 6.0+ |
| MySQL / MariaDB | (any modern version supporting utf8mb4) |

**Optional integrations:**
- WPML or Polylang (multilingual translation pass)
- Yoast SEO / Rank Math (SEO meta migration)
- WooCommerce Product Bundles (OC4 bundle migration)
- Any WooCommerce-compatible brand plugin (product_brand, pwb-brand, yith_product_brand, berocket_brand)
- WP-CLI (command-line migration)

---

## 3. Directory Structure

```
OCTOWOO/
├── octowoo.php                  ← Plugin bootstrap (constants, autoloader, hooks)
├── uninstall.php                ← Removes all plugin data on uninstall
│
├── config/
│   └── default-config.php       ← All default configuration values
│
├── includes/
│   ├── class-octowoo-activator.php   ← DB table creation, log dir setup (activation)
│   └── class-octowoo-deactivator.php ← Cron cleanup (deactivation)
│
├── admin/
│   ├── css/octowoo-admin.css    ← Admin dashboard styles
│   └── js/octowoo-admin.js      ← Admin dashboard scripts (AJAX, progress polling)
│
├── cli/
│   └── class-octowoo-cli.php    ← WP-CLI command class
│
├── templates/
│   └── admin-dashboard.php      ← Admin UI (Migration / Settings / Logs tabs)
│
└── src/                         ← PSR-4 namespace OctoWoo\
    ├── Admin/
    │   ├── AdminPage.php         ← Admin menu, settings save, asset enqueue
    │   └── AjaxHandler.php       ← All 12 wp_ajax_* action handlers
    │
    ├── Core/
    │   ├── MigrationManager.php  ← Orchestrator: runs migrators in order
    │   ├── BatchProcessor.php    ← Pagination engine (batch loops, resume)
    │   ├── CheckpointManager.php ← Per-run progress & ID-map storage
    │   ├── DatabaseConnector.php ← OpenCart PDO connection & query helpers
    │   ├── VersionDetector.php   ← Detects OC major version (1/2/3/4)
    │   ├── Logger.php            ← Structured logging (file + DB, buffered)
    │   ├── CronManager.php       ← Scheduled auto-import via WP-Cron
    │   ├── DataPurger.php        ← Reverse migration / cleanup
    │   └── SqlImporter.php       ← SQL dump parser & importer
    │
    ├── Integration/
    │   ├── AddonManager.php      ← Hook/filter extension points for add-ons
    │   └── WpmlIntegration.php   ← WPML / Polylang translation pass
    │
    └── Migrators/
        ├── AbstractMigrator.php         ← Base class with shared helpers
        ├── TaxMigrator.php              ← Tax classes
        ├── OrderStatusMigrator.php      ← Order status mapping
        ├── CategoryMigrator.php         ← Product categories (hierarchical)
        ├── ManufacturerMigrator.php     ← Brands
        ├── ImageMigrator.php            ← Media library import
        ├── ProductMigrator.php          ← Products (simple + variable)
        ├── RelatedProductsMigrator.php  ← Upsells
        ├── BundleMigrator.php           ← OC4 product bundles
        ├── CustomerMigrator.php         ← User accounts
        ├── OrderMigrator.php            ← Orders with line items
        ├── CouponMigrator.php           ← Discount coupons
        ├── SeoMigrator.php              ← SEO slugs + 301 redirects
        ├── InformationMigrator.php      ← Static pages
        ├── TagMigrator.php              ← Product tags
        ├── FilterMigrator.php           ← Product filters → WC attributes
        ├── DownloadMigrator.php         ← Downloadable files
        └── ReviewMigrator.php           ← Product reviews
```

---

## 4. Database Tables Created

| Table | Purpose |
|---|---|
| `{prefix}octowoo_logs` | Migration log entries (level, message, context, run_id) |
| `{prefix}octowoo_checkpoints` | Per-migrator progress (last_oc_id, processed_count, status) |
| `{prefix}octowoo_id_map` | OpenCart ID → WooCommerce ID cross-reference (per entity type) |

---

## 5. WordPress Options Used

| Option | Purpose |
|---|---|
| `octowoo_config` | All saved plugin settings |
| `octowoo_db_version` | Installed DB schema version (for delta upgrades) |
| `octowoo_active_run_id` | UUID of the currently running migration |
| `octowoo_last_run_id` | UUID of the last completed migration |
| `octowoo_last_run_at` | Timestamp of last completion |
| `octowoo_tax_class_map` | `[oc_tax_class_id => wc_tax_class_slug]` |
| `octowoo_order_status_map` | `[oc_order_status_id => wc_status_slug]` |
| `octowoo_cron_last_run` | Last cron run result |
| `octowoo_sql_import_meta` | Metadata about the last imported SQL dump |

---

## 6. Configuration Reference

All settings live under `octowoo_config` and are merged over `config/default-config.php`.

| Section | Key | Default | Description |
|---|---|---|---|
| source | `source` | `remote` | `remote` (live DB) or `local` (SQL dump) |
| db | `host`, `port`, `database`, `username`, `password`, `prefix` | — | OpenCart DB credentials |
| opencart | `image_path`, `download_path`, `shop_url`, `language_ids`, `version` | — | OC install paths & language config |
| migration | `batch_size` | `20` | Items per batch |
| migration | `dry_run` | `false` | Simulate without writing |
| migration | `on_duplicate` | `skip` | `skip` or `update` on re-run |
| migration | `run_*` flags | `true` per entity | Toggle individual migrators on/off |
| seo | `write_htaccess`, `use_wp_redirects` | — | Redirect strategy |
| multilingual | `enabled`, `use_wpml`, `use_polylang`, `locales` | — | Translation settings |
| cron | `enabled`, `interval`, `migrators` | — | Scheduled auto-import |
| woocommerce | `force_password_reset`, `migrate_oc_passwords` | — | Password migration options |

---

## 7. Migration Execution Order

MigrationManager runs migrators in this fixed sequence (dependencies first):

```
1.  TaxMigrator             → Creates WC tax classes (needed by ProductMigrator)
2.  OrderStatusMigrator     → Builds OC→WC status map (needed by OrderMigrator)
3.  CategoryMigrator        → Product categories with hierarchy
4.  ManufacturerMigrator    → Brand terms + product assignment
5.  ImageMigrator           → Media library import (needed by ProductMigrator)
6.  ProductMigrator         → Products (simple/variable), attributes, variations
7.  RelatedProductsMigrator → Upsell links (needs products)
8.  BundleMigrator          → OC4 product bundles (needs products)
9.  CustomerMigrator        → WP user accounts
10. OrderMigrator           → Orders with line items (needs customers + products)
11. CouponMigrator          → Discount coupons (needs products)
12. SeoMigrator             → SEO slugs + 301 redirects
13. InformationMigrator     → Static/CMS pages
14. TagMigrator             → Product tags
15. FilterMigrator          → OC filters → WC attributes
16. DownloadMigrator        → Downloadable file attachments
17. ReviewMigrator          → Product reviews/comments
18. WpmlIntegration         → Translation linking pass (WPML/Polylang)
```

---

## 8. Core Services – What They Do

### MigrationManager
The top-level orchestrator. Receives a merged config, generates a `run_id` (UUID), bootstraps all shared services, and dispatches to each migrator. Supports two modes:
- **`run()`** — Full synchronous migration (CLI / direct PHP).
- **`runNextChunk()`** — Single-batch mode for AJAX chunked calls from the browser.

### BatchProcessor
Handles paginated iteration over any data source. Given a `total_callback`, `batch_callback`, and `item_callback`, it loops in pages of `batch_size`, calls the checkpoint after each page, and stops early if the abort signal is set. Supports resume via `resume_after_id` (last processed OC ID) and `demo_limit` (cap total for demos).

### CheckpointManager
Writes to `octowoo_checkpoints`. Each migrator has one checkpoint row per run. The manager:
- Records status (`pending` → `running` → `completed` / `failed`).
- Persists `last_oc_id` so a resumed migration can start exactly where it left off.
- Writes to `octowoo_id_map` to link every OC entity ID to its WC counterpart.
- Provides `getWcId()` with a two-pass lookup (id_map first, then postmeta fallback).

### DatabaseConnector
A thin PDO wrapper that connects to the OpenCart database. When `source=local`, it re-points to the WordPress database using the `octowoo_oc_` prefixed tables that SqlImporter created. Provides `fetchBatch()` for paginated reads and `scanSourceCounts()` for the pre-migration entity count scan.

### VersionDetector
Detects OC major version (1–4) from `oc_setting.config_version` or from structural markers (table/column existence). Provides adapter methods like `seoUrlJoin()`, `quantityColumn()` so migrators write a single SQL query that works across all OC versions.

### Logger
PSR-inspired five-level logger (DEBUG/INFO/WARNING/ERROR/SUCCESS). Writes to a date-stamped log file (with rotation at 10 MB) and buffers DB inserts (flushed every 25 entries or at migrator end). File and DB logging can each be independently disabled.

### SqlImporter
Parses a `.sql` or `.gz` SQL dump line by line, rewriting all table names from `oc_*` → `octowoo_oc_*` and stripping incompatible clauses. Imports into the WordPress database so a "local" migration reads from the same MySQL server as WordPress. Also handles `dropImportedTables()` for cleanup.

### DataPurger
Allows selective rollback. For each entity type it either:
- **Force mode**: deletes all WC entities of that type.
- **Tagged mode**: deletes only items that carry `_octowoo_oc_id` postmeta (safe — only removes plugin-created data).

### CronManager
Registers WP-Cron schedules (`every30min`, `every6hours` plus standard intervals). When enabled, fires a full migration on schedule using `on_duplicate=update`, so recurring cron runs act as delta syncs updating existing records.

### AddonManager
Provides eight filters and six actions that third-party add-on plugins can hook into to modify migrated data or skip specific records. Data filters: `octowoo_product_data`, `octowoo_category_data`, `octowoo_customer_data`, `octowoo_order_data`, etc. Skip filters: `octowoo_should_skip_product`, `octowoo_should_skip_customer`, `octowoo_should_skip_order`.

---

## 9. Admin Interface (AJAX Actions)

All 12 actions are registered under `wp_ajax_octowoo_*` (admin-only, nonce-verified, capability `manage_woocommerce`):

| Action | What it does |
|---|---|
| `octowoo_start_migration` | Starts a full synchronous migration |
| `octowoo_run_chunk` | Runs a single batch (chunked AJAX mode) |
| `octowoo_abort_migration` | Sets abort transient + marks checkpoints aborted |
| `octowoo_get_progress` | Returns snapshot of all checkpoint rows for polling |
| `octowoo_get_logs` | Returns recent log entries (filterable by level/migrator) |
| `octowoo_reset_migration` | Truncates checkpoints/id_map/logs tables |
| `octowoo_test_connection` | Tests OC DB credentials |
| `octowoo_import_sql` | Handles SQL/GZ file upload and import |
| `octowoo_import_images` | Handles ZIP archive upload and extracts images |
| `octowoo_purge_imported` | Runs DataPurger for selected entities |
| `octowoo_scan_counts` | Returns row counts from OC database |
| `octowoo_drop_sql` | Drops imported `octowoo_oc_*` tables |

---

## 10. WP-CLI Commands

```bash
wp octowoo migrate                        # Full migration
wp octowoo migrate --dry-run              # Simulate (no DB writes)
wp octowoo migrate --resume               # Resume from last checkpoint
wp octowoo migrate --migrators=products,orders  # Run specific migrators only

wp octowoo status                         # Show checkpoint progress
wp octowoo logs --level=error --limit=50  # View logs
wp octowoo reset --yes                    # Clear all checkpoints/logs
wp octowoo test_connection                # Test OC DB connection
```

---

## 11. Password Migration

When `woocommerce.migrate_oc_passwords = true`:
1. OC password hash (`sha1(md5(salt + password))`) and salt are stored in user meta during `CustomerMigrator`.
2. A `authenticate` filter (`octowoo_oc_password_compat`, priority 30) is registered.
3. On the customer's first WP login, the filter validates the OC hash, accepts the login, and immediately upgrades the stored hash to WP's phpass format.
4. After upgrade, subsequent logins use the native WP authentication path — OC hash is no longer used.

---

## 12. Multilingual Support

When `multilingual.enabled = true`, after all entity migrators complete, `WpmlIntegration` runs a translation pass:
- Reads secondary-language meta stored by migrators (e.g. `_octowoo_name_ar`, `_octowoo_description_ar`).
- Creates translated post/term copies via `wp_insert_post()` / `wp_insert_term()`.
- Links them to the primary entity using **WPML** actions (`wpml_set_element_language_details`) or **Polylang** functions (`pll_set_post_language`, `pll_link_post_translations`).

---

## 13. SEO & Redirects

`SeoMigrator` reads the `oc_seo_url` table and:
1. Updates the WC entity's slug to match the OC SEO keyword.
2. Builds a redirect map from old OC URL → new WC URL.
3. Persists redirects via either:
   - **Apache `.htaccess`**: writes a managed block of `RedirectPermanent` lines.
   - **WordPress option**: stores rules; an early `template_redirect` hook fires 301 responses.

---

## 14. What Has Been Completed

| Area | Status |
|---|---|
| Plugin bootstrap & autoloader | Done |
| Activation (DB tables, log dir, options) | Done |
| Deactivation (cron cleanup) | Done |
| Uninstall (full data removal) | Done |
| Admin menu & dashboard UI | Done |
| Admin settings form (DB credentials, paths, toggles) | Done |
| All 12 AJAX action handlers | Done |
| Admin CSS & JS (tabs, progress, polling) | Done |
| Logger (file + DB, rotation, buffered) | Done |
| DatabaseConnector (PDO, all query helpers, socket support) | Done |
| VersionDetector (OC 1–4, structural detection) | Done |
| CheckpointManager (resume, id_map, two-pass getWcId) | Done |
| BatchProcessor (pagination, resume, demo limit, dry-run) | Done |
| MigrationManager (orchestrator, full + chunked modes) | Done |
| CronManager (schedules, delta sync) | Done |
| DataPurger (force + tagged rollback for all entities) | Done |
| SqlImporter (SQL/GZ parser, prefix rewrite, generator) | Done |
| AddonManager (8 filters + 6 actions) | Done |
| TaxMigrator | Done |
| OrderStatusMigrator (+ custom WC status registration) | Done |
| CategoryMigrator (hierarchy, SEO, images, Arabic meta) | Done |
| ManufacturerMigrator (brand taxonomy detection, images, product assignment) | Done |
| ImageMigrator (MD5 deduplication, sideload, OC path caching) | Done |
| ProductMigrator (simple + variable, attributes, variations, specials) | Done |
| RelatedProductsMigrator (upsells) | Done |
| BundleMigrator (OC4, WC Product Bundles plugin) | Done |
| CustomerMigrator (addresses, OC password hash migration) | Done |
| OrderMigrator (line items, totals, status mapping) | Done |
| CouponMigrator (percent + fixed, limits, expiry, products) | Done |
| SeoMigrator (.htaccess + WP redirects, products/categories/pages) | Done |
| InformationMigrator (static pages, SEO, Arabic meta) | Done |
| TagMigrator (comma-delimited tags, append mode) | Done |
| FilterMigrator (groups → attributes, filters → terms, product assignment) | Done |
| DownloadMigrator (file copy, meta, downloadable flag) | Done |
| ReviewMigrator (WP comments, rating meta, cache flush) | Done |
| WpmlIntegration (WPML + Polylang, posts + terms) | Done |
| WP-CLI command class | Done |
| OC password compat filter (sha1/md5 → phpass upgrade) | Done |

---

## 15. Known Issues & Limitations

### 15.1 Variable Products / Variations
- OC "options" map to WC attribute/variation combinations. Complex OC option configurations (e.g. options linked to images, file-upload options, date options) are **not migrated** — only `select` and `radio` type options are converted to variations.
- If an OC product has many option combinations (e.g. 10 colours × 10 sizes = 100 variations), WooCommerce's default 50-variation limit can block saving unless the user increases the WC limit.

### 15.2 Image Migration
- Images are resolved via the OpenCart `image_path` configured in settings. If that path is wrong or the OC files are not accessible, all image migrations will silently fail (logged as warnings but migration continues).
- Remote image copy (OC shop on different server) requires the OC `image_path` to be an accessible URL or mounted path.
- No lazy image download from the OC shop_url — images must be physically accessible.

### 15.3 Batch Size & Timeouts
- `batch_size` defaults to 20. Stores with very large product catalogues (10,000+) will benefit from increasing to 50–100, but too large a batch can exhaust PHP memory.
- In chunked AJAX mode, the browser must keep the tab open throughout; if it times out or the browser closes, the run stops at the last checkpoint and must be manually resumed.

### 15.4 Order Taxes
- OC stores order-level tax totals in `oc_order_total`. The migrator creates a fee/tax line item from those totals, but does **not** recreate per-line-item tax data — WC's own recalculate  feature would produce different numbers on edit.

### 15.5 Bundle Migration
- Requires the paid **WooCommerce Product Bundles** plugin to be active. Without it, bundles are skipped with a warning.
- Only OC 4.x bundles are supported (requires `oc_product_bundle` table).

### 15.6 Multilingual / WPML
- A secondary language must be configured in `multilingual.locales` and WC product descriptions for the secondary language must already exist in OC (stored under a second `language_id`).
- The WPML/Polylang pass only handles products, categories, and pages — other entities (orders, customers, coupons) are not translated.

### 15.7 SEO Redirects (.htaccess)
- Writing to `.htaccess` requires the file to be writable by the web server. On hardened servers this will silently fail (error is logged).
- Only Apache is supported for `.htaccess` redirects; Nginx users must use the WP-redirect option.

### 15.8 SQL Dump Import (Local Mode)
- The SQL importer re-prefixes tables server-side. If the dump contains stored procedures, views, or triggers, those are **not** migrated.
- Very large dumps (>500 MB) can exhaust PHP upload limits and execution time. The generator-based importer helps but is still subject to PHP configuration.

### 15.9 OC Password Compatibility
- Password hashing support is for OC's legacy `sha1(md5(salt + password))` scheme only. OC installations using bcrypt or other non-standard hashing are **not** covered.
- The compat filter runs at priority 30 so it fires after WP's native authenticator. If a customer hasn't logged in since migration, their WP password is the random one set during migration — they must use the OC password or request a reset.

### 15.10 Reviews
- All imported reviews get `verified = 0` (unverified purchase). WooCommerce treats unverified reviews differently if "only allow verified reviews" is enabled in WC settings — those reviews will not display.

### 15.11 Duplicate OCTOWOO Folder
- The workspace contains a nested `OCTOWOO/` folder that duplicates all source files. This is a workspace/copy artifact. Only one copy (the root-level files) should be deployed.

### 15.12 Cron Delta Sync Gaps
- Cron auto-import uses `on_duplicate=update`, which keeps existing WC products/orders current. However, records that were **deleted** in OC after the initial migration are **not deleted** from WC — there is no deletion delta.

### 15.13 No Rollback Transaction
- Each migrator processes items individually without a wrapping database transaction. A failure mid-migration leaves partially migrated data in WC. The DataPurger must be used to clean up before a retry.

---

## 16. Security Measures Already Implemented

- All AJAX handlers verify a nonce (`octowoo_ajax`) before executing.
- All AJAX handlers check capability (`manage_woocommerce`).
- File uploads (SQL, images ZIP) validate file extension and MIME type.
- ZIP extraction rejects path-traversal filenames.
- SQL dump importer strips `SET`, `USE`, `LOCK TABLE` statements.
- Log directory is protected by `.htaccess` (deny all) and `index.html`.
- OC DB passwords are never logged.
- OC password hashes are stored only in user meta, never logged or displayed.
- `uninstall.php` verifies `WP_UNINSTALL_PLUGIN` constant.
- `DataPurger` in tagged mode only touches items with `_octowoo_oc_*` meta — cannot accidentally delete unrelated WC content.

---

## 17. Extension Points for Add-on Developers

```php
// Modify product data before insert/update
add_filter('octowoo_product_data', function($data, $oc_product) { ... }, 10, 2);

// Skip specific products
add_filter('octowoo_should_skip_product', function($skip, $oc_product) { ... }, 10, 2);

// Use a custom brand taxonomy
add_filter('octowoo_brand_taxonomy', function($taxonomy) { return 'my_brand'; });

// React after each product is created
add_action('octowoo_after_migrate_product', function($wc_id, $oc_id) { ... }, 10, 2);

// React when the full migration completes
add_action('octowoo_migration_finished', function($report) { ... });

// Register your add-on when OctoWoo initialises
add_action('octowoo_register_addons', function() {
    // register your hooks here
});
```

Available filters: `octowoo_product_data`, `octowoo_category_data`, `octowoo_customer_data`, `octowoo_order_data`, `octowoo_coupon_data`, `octowoo_information_data`, `octowoo_manufacturer_data`, `octowoo_should_skip_product`, `octowoo_should_skip_customer`, `octowoo_should_skip_order`, `octowoo_brand_taxonomy`.

Available actions: `octowoo_migration_started`, `octowoo_migration_finished`, `octowoo_after_migrate_product`, `octowoo_after_migrate_category`, `octowoo_after_migrate_manufacturer`, `octowoo_after_migrate_order`, `octowoo_register_addons`.
