# OCTOWOO – Project Overview

**Version:** 2.4.68
**Type:** WordPress / WooCommerce Plugin
**Purpose:** Migrate an OpenCart store (v1/2/3/4) into WooCommerce with complete data parity.

---

## 1. Project Concept

OctoWoo is a production-grade, self-hosted WordPress plugin that reads an OpenCart database (either a live remote connection or an uploaded SQL dump) and reproduces the entire store inside WooCommerce. It covers every major entity: products (simple + variable), categories, customers, orders, coupons, tax classes, images, reviews, filters, manufacturers (brands), bundles, downloadable files, static pages, SEO URLs, and multilingual content.

The plugin is designed to be safe to run incrementally:
- **Batch/chunk processing** — configurable `batch_size`; each AJAX request or AS task processes exactly one page.
- **Checkpoint/resume** — per-migrator progress stored in DB; interrupted runs continue from the exact row.
- **Duplicate detection** — `skip` (default) or `update` strategy on re-runs; uses `id_map` + SKU fallback.
- **Dry-run mode** — full simulation without any DB writes.
- **Data purger** — entity-by-entity rollback (tagged or force mode).
- **WP-CLI** — full server-side automation with progress bars.
- **Background mode** — WooCommerce Action Scheduler; browser tab can be closed.
- **Cron auto-import** — scheduled delta sync.

---

## 2. Complete Feature List

### Data Migration

| Feature | Details |
|---|---|
| **Tax classes** | OC tax classes → WC tax classes; `octowoo_tax_class_map` option |
| **Order statuses** | OC statuses mapped to WC equivalents; unmapped → custom WC status registered; stored in `octowoo_order_status_map` |
| **Product categories** | Full parent/child hierarchy; SEO slugs; category thumbnail images; secondary-language name/description/meta; Yoast SEO meta |
| **Manufacturers / Brands** | Brand taxonomy auto-detected (`product_brand`, `pwb-brand`, `yith_product_brand`, `berocket_brand`, `pa_brand`); brand images; product assignment |
| **Images** | MD5-based deduplication; sideload to WP media library; HTTP fallback per image; ZIP-upload mode; OC path caching; circuit-breaker transient cleared on every new run |
| **Products – simple** | Title, description, SKU, price, sale price (specials), stock, weight, dimensions, tax class, categories, tags; `status=0` → WC `draft`, `status=1` → WC `publish` |
| **Products – variable** | `select`/`radio` OC options → WC attributes + variations; price/stock/SKU per variation |
| **Product specials** | `oc_product_special` → `_sale_price` / `_sale_price_dates_from/to` |
| **Product filters** | OC filter groups → `pa_*` attribute taxonomies; filter values → terms; product assignment |
| **Product tags** | Comma-delimited OC tags → `product_tag` terms (append mode) |
| **Product bundles** | OC4 `oc_product_bundle` → WooCommerce Product Bundles (SomewhereWarm) |
| **Related products** | `oc_product_related` → WC upsell links |
| **Downloadable files** | File copy from OC download dir → WC protected uploads; `_downloadable`, `_download_*` meta |
| **Customers** | WP user account (email, name, role); full billing + shipping address from `oc_address`; newsletter opt-in → `woocommerce_marketing_optin_status`; only `status=1` customers imported |
| **OC password migration** | Optional: stores OC hash on import; on first login verifies `sha1(salt·sha1(salt·sha1(plaintext)))`, upgrades to WP phpass, deletes OC hash meta |
| **Orders** | Line items, quantities, prices, subtotal, shipping, totals; OC→WC status mapping; billing/shipping address |
| **Coupons** | Percent-off and fixed-cart; usage limits; expiry date; restricted products list |
| **SEO slugs** | Reads `oc_seo_url`; updates WC entity slug for products, categories, pages; chunk-aware (LIMIT/OFFSET) |
| **301 redirects** | Old OC URLs → new WC URLs via `.htaccess` managed block and/or WP `template_redirect` |
| **Information pages** | `oc_information` → WP pages; SEO slug; secondary-language name/description meta |
| **Product reviews** | WP comments with `rating` meta; author, email, date; cache flush on completion |
| **Yoast SEO (primary language)** | `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw` from OC `meta_title`, `meta_description`, `meta_keyword` |

### Multilingual (WPML / Polylang)

Language handling is fully generic — no language is hardcoded. All locale codes, language IDs, and meta-key suffixes come from config.

| Feature | Details |
|---|---|
| **Secondary-language products** | Secondary-language title, content, excerpt; primary-language fallback when secondary is absent |
| **Secondary-language meta stored by ProductMigrator** | `_octowoo_name_{sfx}`, `_octowoo_description_{sfx}`, `_octowoo_short_description_{sfx}`, `_octowoo_metatitle_{sfx}`, `_octowoo_metadesc_{sfx}`, `_octowoo_metakw_{sfx}` — suffix derived from `secLangSuffix()` = `'_' . secondary_locale` |
| **Full WC meta copy** | `copyProductDataToTranslation()` copies SKU, price, stock, gallery, `product_type`, `product_tag`, brand terms, `product_cat` to translated post |
| **Slug fix** | `fixTranslationSlug()` forces `post_name` to match primary-language slug — no `-2` suffix |
| **Secondary-language categories** | Secondary-language name, description; primary-language name fallback |
| **Yoast SEO – secondary-language products** | Reads secondary-language meta; falls back to primary Yoast values; writes all three Yoast keys to translated post |
| **Yoast SEO – secondary-language categories** | Same pattern; primary-language Yoast fallback |
| **Secondary-language SEO redirects** | Old `/{secondary_lang}/oc-slug` → new `/{secondary_lang}/product/wc-slug/` and `/{secondary_lang}/product-category/wc-slug/` |
| **Bulk secondary-language tag prefetch** | `prefetchSecLangTagsForProducts()` eliminates N+1 OC queries for secondary-language tags per batch |
| **Update-existing translations** | Re-run updates title/content/WC meta/Yoast SEO on already-linked translations |
| **Chunk-aware** | Uses `getProcessedCount()` as SQL OFFSET; safe to resume after AS timeout |
| **`multilingual.enabled` bridge** | `MigrationManager::buildConfig()` bridges `multilingual.enabled` → `migration.run_multilingual`; cannot silently run when disabled in Settings |
| **WPML language-switch on insert** | Switches to secondary language before `wp_insert_post()` / `wp_insert_term()` so WPML auto-assigns language at creation time |
| **`forceTranslationContent()`** | Direct DB write after all hooks fire — bypasses WPML field-sync that would erase secondary-language content |
| **`fixSecLangTermParents()`** | Post-sweep after all categories translated: ensures every secondary-language category has correct secondary-language parent |
| **Category repair on re-run** | `updateProduct()` calls `assignCategories()` so re-running Products step corrects category assignments for already-migrated products |

### Infrastructure

| Feature | Details |
|---|---|
| **Batch processing** | Configurable `batch_size`; paginated SQL LIMIT/OFFSET over any OC table |
| **Checkpoint / resume** | Per-migrator `last_oc_id` + `processed_count`; resumes exactly from interruption point |
| **Dry-run mode** | Full simulation; no DB writes; logged as `[DRY-RUN]` |
| **Demo limit** | Cap each migrator at N items for a quick sanity check |
| **Duplicate handling** | `skip` (default) or `update` on re-run |
| **ID map** | `octowoo_id_map` table: `oc_id → wc_id` per entity type; three-pass lookup (in-memory cache → id_map → postmeta fallback) |
| **Pause / Resume** | Runtime pause flag; JS + AJAX; Background-mode Resume button properly enabled only when paused |
| **Skip migrator** | Skip the currently running migrator; continue with the next |
| **Abort** | Cancels AS jobs; marks all running/pending checkpoints as aborted |
| **Stale lock auto-clear** | Active-run lock self-heals in 3 independent scenarios (all terminal, 15-min heartbeat gap, 15-min with no rows) |
| **Concurrency guard** | `octowoo_chunk_lock_{run_id}` transient prevents two simultaneous AJAX chunk requests racing on the same batch |
| **Background processing** | WooCommerce Action Scheduler integration; `BackgroundProcessor::enqueue()` clears image circuit-breaker transient on every fresh run |
| **Parallel-run prevention** | JS `setButtonState('running')` disables all background buttons while AJAX migration is active |
| **WP-CLI** | `migrate`, `migrate --resume`, `migrate --dry-run`, `migrate --migrators=…`, `status`, `logs`, `logs --level=ERROR`, `reset`, `test-connection` |
| **Cron auto-import** | Scheduled delta sync via WP-Cron (`on_duplicate=update`) |
| **SQL dump mode** | Upload `.sql` / `.gz`; importer rewrites `oc_*` prefixes to `octowoo_oc_*`; runs against WP DB |
| **ZIP image mode** | Upload a ZIP of OC `/image/`; extracted and used as local image source |
| **Pre-migration scan** | Counts ALL source entities (no status filter — mirrors what migrators now process); flags missing images, oversized variation sets, no-description products |
| **Migration report** | Per-migrator processed/skipped/failed counts; persisted to `octowoo_last_report` option |
| **Bulk SQL purge** | Single-statement DELETE for each entity type; seconds not minutes |
| **Force vs tagged purge** | Tagged: only `_octowoo_oc_id`-tagged items. Force: all WC items of that type |
| **Purge diagnostics** | Warns when items exist but have no OctoWoo tag; suggests Force Purge |
| **Meta repair** | `repairMetaFromIdMap()` backfills missing `_octowoo_oc_id` meta before purge |
| **AUTO_INCREMENT reset** | Resets MySQL `AUTO_INCREMENT` on 6 core WP tables after a full purge |
| **Credential encryption** | AES-256-CBC of OC DB password in `wp_options` (key: `OCTOWOO_CRYPT_KEY` constant → `AUTH_KEY` fallback) |
| **Settings enforcement** | `AjaxHandler` intersects AJAX migrator list with saved Settings — a migrator disabled in Settings cannot be re-enabled from Migration tab UI alone |
| **Add-on hooks** | 11 filters + 7 actions for third-party customisation (`AddonManager`) |
| **Version detection** | Auto-detects OC 1/2/3/4 from DB; adapts SQL per version (`seoUrlJoin()`, `quantityColumn()`) |
| **Logger** | 5-level (DEBUG/INFO/WARNING/ERROR/SUCCESS); file (10 MB rotation) + DB (buffered, flush every 25); independently configurable |
| **Validation** | Pre-flight config check with per-item pass/warning/fail status |

---

## 3. Requirements

| Dependency | Minimum Version |
|---|---|
| WordPress | 5.8+ |
| PHP | 7.4+ |
| WooCommerce | 6.0+ |
| MySQL / MariaDB | Any modern version supporting `utf8mb4` |

**Optional integrations:**
- WPML or Polylang (multilingual translation pass)
- Yoast SEO (SEO meta migration)
- WooCommerce Product Bundles / SomewhereWarm (OC4 bundle migration)
- Any WooCommerce-compatible brand plugin (`product_brand`, `pwb-brand`, `yith_product_brand`, `berocket_brand`)
- WP-CLI (command-line migration)
- WooCommerce Action Scheduler 3.0+ (background mode — bundled with WooCommerce 4.0+)

---

## 4. Directory Structure

```
OCTOWOO/
├── octowoo.php                  ← Plugin bootstrap (constants, autoloader, hooks)
├── uninstall.php                ← Removes all plugin data on uninstall
├── composer.json                ← PSR-4 autoloader definition
│
├── config/
│   └── default-config.php       ← All default configuration values (fallback)
│
├── includes/
│   ├── class-octowoo-activator.php   ← DB table creation, log dir setup (on activation)
│   └── class-octowoo-deactivator.php ← Cron cleanup (on deactivation)
│
├── admin/
│   ├── css/octowoo-admin.css    ← Admin dashboard styles
│   └── js/octowoo-admin.js      ← Admin dashboard scripts (AJAX chunks, polling, button state)
│
├── cli/
│   └── class-octowoo-cli.php    ← WP-CLI command class
│
├── templates/
│   └── admin-dashboard.php      ← Admin UI (Migration / Settings / Logs tabs)
│
├── scripts/
│   ├── bump_version.php         ← Version bump utility (PHP)
│   └── bump-version.ps1         ← Version bump utility (PowerShell)
│
└── src/                         ← PSR-4 namespace OctoWoo\
    ├── Admin/
    │   ├── AdminPage.php         ← Admin menu, settings save, asset enqueue, getConfig()
    │   └── AjaxHandler.php       ← All wp_ajax_* handlers (20+ endpoints)
    │
    ├── Core/
    │   ├── MigrationManager.php  ← Orchestrator: full run, chunked AJAX, background mode
    │   ├── BatchProcessor.php    ← Pagination engine (batch loops, resume, demo limit)
    │   ├── CheckpointManager.php ← Per-run progress & ID-map storage; stale-lock self-heal
    │   ├── DatabaseConnector.php ← OpenCart PDO connection, query helpers, scanSourceCounts()
    │   ├── VersionDetector.php   ← Detects OC major version (1/2/3/4); SQL adapters
    │   ├── Logger.php            ← Structured logging (file + DB, buffered, 5 levels)
    │   ├── CronManager.php       ← Scheduled auto-import via WP-Cron
    │   ├── DataPurger.php        ← Reverse migration / cleanup (tagged + force; bulk SQL)
    │   ├── SqlImporter.php       ← SQL/GZ dump parser, prefix rewrite, importer
    │   ├── Encryptor.php         ← AES-256-CBC credential encryption/decryption
    │   ├── MigrationReport.php   ← Post-run summary report builder
    │   ├── PreMigrationScanner.php ← Pre-flight entity counts, image scan, variation scan
    │   ├── BackgroundProcessor.php ← Action Scheduler integration; circuit-breaker reset
    │   └── Validator.php         ← Pre-flight config validation (12 checks)
    │
    ├── Integration/
    │   ├── AddonManager.php      ← 11 filters + 7 actions for add-on plugins
    │   └── WpmlIntegration.php   ← WPML / Polylang translation pass (chunk-aware; language-agnostic)
    │
    └── Migrators/
        ├── AbstractMigrator.php         ← Base class: batch helpers, name/desc sanitization, secLangSuffix()
        ├── TaxMigrator.php              ← WC tax classes
        ├── OrderStatusMigrator.php      ← OC→WC order status mapping + custom status registration
        ├── CategoryMigrator.php         ← Hierarchical categories, images, SEO, secondary-language meta
        ├── ManufacturerMigrator.php     ← Brand taxonomy detection, images, product assignment
        ├── ImageMigrator.php            ← Media library import (MD5 dedup, sideload, HTTP fallback)
        ├── ProductMigrator.php          ← Simple + variable products; status→post_status mapping
        ├── RelatedProductsMigrator.php  ← Upsell links
        ├── BundleMigrator.php           ← OC4 product bundles (WC Product Bundles plugin)
        ├── CustomerMigrator.php         ← WP user accounts, addresses, newsletter, OC password hash
        ├── OrderMigrator.php            ← Orders with line items, totals, status mapping
        ├── CouponMigrator.php           ← Discount coupons (percent + fixed)
        ├── SeoMigrator.php              ← SEO slugs + 301 redirects (chunk-aware LIMIT/OFFSET)
        ├── InformationMigrator.php      ← Static/CMS pages
        ├── TagMigrator.php              ← Product tags (append mode)
        ├── FilterMigrator.php           ← OC filter groups → WC pa_* attributes
        ├── DownloadMigrator.php         ← Downloadable file attachments
        └── ReviewMigrator.php           ← Product reviews/comments
```

---

## 5. Database Tables Created by OctoWoo

| Table | Purpose |
|---|---|
| `{prefix}octowoo_logs` | Migration log entries (level, message, context JSON, run_id, migrator, created_at) |
| `{prefix}octowoo_checkpoints` | Per-migrator progress (run_id, migrator, last_oc_id, processed_count, total_count, status, started_at, updated_at) |
| `{prefix}octowoo_id_map` | OpenCart ID → WooCommerce ID cross-reference (entity_type, oc_id, wc_id, run_id) — survives run resets; only cleared by "Reset Progress" |

---

## 6. WordPress Options Used

| Option | Purpose |
|---|---|
| `octowoo_config` | All saved plugin settings (merged over `default-config.php`) |
| `octowoo_db_version` | Installed DB schema version (for delta upgrades on activation) |
| `octowoo_active_run_id` | UUID of the currently running migration (self-healing stale-lock) |
| `octowoo_last_run_id` | UUID of the last completed/aborted migration |
| `octowoo_last_run_at` | Timestamp of last completion |
| `octowoo_run_started_at` | Timestamp the active run was initialised (stale-lock detection) |
| `octowoo_tax_class_map` | `[oc_tax_class_id => wc_tax_class_slug]` |
| `octowoo_order_status_map` | `[oc_order_status_id => wc_status_slug]` |
| `octowoo_cron_last_run` | Last cron run result |
| `octowoo_sql_import_meta` | Metadata about the last imported SQL dump |
| `octowoo_redirects` | `[old_path => new_url]` map served by `SeoMigrator::handleWpRedirect()` |
| `octowoo_last_report` | Per-migrator stats (processed/skipped/failed) from last completed run |

---

## 7. Configuration Reference

All settings live under `octowoo_config` and are merged over `config/default-config.php`.

| Section | Key | Default | Description |
|---|---|---|---|
| `source` | `source` | `remote` | `remote` (live DB) or `local` (uploaded SQL dump) |
| `db` | `host`, `port`, `database`, `username`, `password`, `prefix`, `socket` | — | OpenCart DB credentials |
| `opencart` | `image_path` | `''` | Absolute path to OC `/image/` dir |
| `opencart` | `download_path` | `''` | Absolute path to OC download dir (auto-derived if blank) |
| `opencart` | `shop_url` | `''` | Old OC shop URL (used for redirect rules) |
| `opencart` | `language_id` | `1` | Primary language ID (English) |
| `opencart` | `language_id_secondary` | `2` | Secondary language ID (Arabic); `0` = disable |
| `opencart` | `version` | `auto` | OC version override: `auto` \| `1` \| `2` \| `3` \| `4` |
| `migration` | `batch_size` | `20` | Items per batch |
| `migration` | `dry_run` | `false` | Simulate without writing |
| `migration` | `demo_limit` | `0` | Cap each migrator at N items (0 = unlimited) |
| `migration` | `on_duplicate` | `skip` | `skip` or `update` on re-run |
| `migration` | `run_*` flags | `true` per entity | Toggle individual migrators on/off; enforced in AjaxHandler |
| `seo` | `write_htaccess` | `true` | Write `.htaccess` 301 rules |
| `seo` | `use_wp_redirects` | `true` | Also register WP `template_redirect` rules |
| `multilingual` | `enabled` | `false` | Enable WPML/Polylang pass |
| `multilingual` | `use_wpml`, `use_polylang` | `false` | Plugin preference |
| `multilingual` | `primary_locale`, `secondary_locale` | `en`, `ar` | Language codes |
| `cron` | `enabled`, `interval`, `migrators` | — | Scheduled auto-import config |
| `woocommerce` | `force_password_reset` | `true` | Prompt customers to reset on first login |
| `woocommerce` | `migrate_oc_passwords` | `false` | Store OC hash; verify on first login |
| `woocommerce` | `customer_role` | `customer` | WP role assigned to imported customers |
| `woocommerce` | `default_order_status` | `pending` | Fallback when OC status cannot be mapped |
| `logging` | `file_enabled`, `db_enabled`, `min_level`, `max_file_size` | — | Logging options |

---

## 8. Migration Execution Order

`MigrationManager` runs migrators in this fixed sequence (dependencies resolved):

```
 1. TaxMigrator             → WC tax classes (needed by ProductMigrator)
 2. OrderStatusMigrator     → OC→WC status map (needed by OrderMigrator)
 3. CategoryMigrator        → Product categories with full hierarchy
 4. ImageMigrator           → Media library (images needed by ProductMigrator)
 5. ProductMigrator         → Products (simple/variable); status=0 → draft
 6. ManufacturerMigrator    → Brand terms + product assignment (needs products)
 7. RelatedProductsMigrator → Upsell links (needs products)
 8. BundleMigrator          → OC4 product bundles (needs products)
 9. CustomerMigrator        → WP user accounts + addresses
10. OrderMigrator           → Orders + line items (needs customers + products)
11. CouponMigrator          → Discount coupons (needs products)
12. SeoMigrator             → SEO slugs + 301 redirects (chunk-aware)
13. InformationMigrator     → Static/CMS pages
14. TagMigrator             → Product tags
15. FilterMigrator          → OC filters → WC pa_* attributes
16. DownloadMigrator        → Downloadable file attachments
17. ReviewMigrator          → Product reviews/comments
18. WpmlIntegration         → WPML/Polylang translation pass (chunk-aware; always last)
```

Each migrator can be individually disabled in Settings. AjaxHandler enforces Settings at the AJAX boundary.

---

## 9. Admin UI – Buttons and What They Do

### Standard (AJAX chunk) mode

| Button | JS Function | Behaviour |
|---|---|---|
| ▷ Start Demo Migration | `startMigration(false, true)` | Migrates first 20 items per entity |
| ▶ Start Full Migration | `startMigration(false, false)` | Full migration; respects entity checkboxes + Step 2 options |
| 🖼 Images-Only Recovery | `startImagesOnlyRecovery()` | Runs `images, categories, manufacturers` only |
| 🧩 Products + Images Recovery | `startProductsImagesRecovery()` | Runs `products, images, related`; skips known products via id_map |
| 🗂 Categories + Manufacturers Recovery | `startCategoriesManufacturersRecovery()` | Runs `categories, manufacturers` only |
| 🌐 Multilingual Recovery | `startMultilingualRecovery()` | Runs `multilingual` pass only |
| ⏯ Resume | `startMigration(true, false)` | Continues from last checkpoint |
| ⏹ Abort | `abortMigration()` | Sets abort transient; next chunk stops |
| ⏸ Pause | `pauseMigration()` | Sets pause flag; current chunk finishes then stops |
| ⏭ Skip Current | `skipCurrentMigrator()` | Marks current migrator for skip; continues with next |
| ↺ Reset Progress | `resetMigration()` | Truncates checkpoints/id_map/logs; resets all UI state |
| 🔗 Repair Order Items | `repairOrderItems()` | Batch-scans all migrated WC orders; re-links broken `_product_id` references by SKU/model via direct `wp_postmeta` query; paginates via `page` POST param until `done=true`; accumulates counts across batches and shows final alert |

### Background mode (Action Scheduler)

| Button | JS Function | Availability |
|---|---|---|
| ⚙ Start in Background | `startBackgroundMigration(false)` | Enabled in `idle` state; disabled while AJAX run is active |
| ⚙ Resume in Background | `startBackgroundMigration(true)` | Enabled only in `paused` state |
| ✖ Cancel Background | `cancelBackgroundMigration()` | Cancels pending AS jobs; sets abort flag |

### Button state machine

| State | Start | Demo | Recovery | Resume | Abort | Pause | Skip | BG Start | BG Resume |
|---|---|---|---|---|---|---|---|---|---|
| `idle` | ✔ | ✔ | ✔ | ✔ | ✘ | ✘ | ✘ | ✔ | ✘ |
| `running` | ✘ | ✘ | ✘ | ✘ | ✔ | ✔ | ✔ | ✘ | ✘ |
| `paused` | ✘ | ✘ | ✘ | ✔ | ✔ | ✘ | ✔ | ✘ | ✔ |

---

## 10. Admin UI – AJAX Actions (AjaxHandler)

All actions registered under `wp_ajax_octowoo_*` (admin-only, nonce-verified, `manage_woocommerce` capability):

| Action | What it does |
|---|---|
| `octowoo_run_chunk` | Runs one batch; enforces Settings migrator flags; fatal-error shutdown handler |
| `octowoo_start_background` | Dispatches AS run; enforces Settings migrator flags |
| `octowoo_cancel_background` | Cancels pending AS jobs |
| `octowoo_abort_migration` | Sets abort transient; marks checkpoints aborted |
| `octowoo_pause_migration` | Sets pause flag; cancels pending AS jobs |
| `octowoo_resume_migration` | Clears pause flag |
| `octowoo_skip_migrator` | Marks migrator for skip via signal transient |
| `octowoo_get_progress` | Returns checkpoint snapshot for UI polling |
| `octowoo_get_report` | Returns last migration report |
| `octowoo_get_logs` | Returns recent log entries (filterable by level/migrator/run_id) |
| `octowoo_reset_migration` | Truncates all three OctoWoo tables + clears options |
| `octowoo_test_connection` | Tests OC DB credentials live from settings form |
| `octowoo_import_sql` | Handles `.sql`/`.gz` upload and import |
| `octowoo_drop_sql` | Drops `octowoo_oc_*` tables |
| `octowoo_import_images` | Handles ZIP upload and extraction |
| `octowoo_purge_imported` | Runs DataPurger (concurrent lock + force confirmation) |
| `octowoo_scan_counts` | Returns row counts from OC database |
| `octowoo_prescan` | Runs PreMigrationScanner (counts + image/variation issues) |
| `octowoo_validate` | Runs Validator (12-point pre-flight check) |
| `octowoo_cleanup_ml_terms` | Deletes orphaned secondary-language terms that have no primary-language counterpart |
| `octowoo_repair_order_items` | Paged scan of all migrated WC orders; for each `WC_Order_Item_Product` with `_octowoo_oc_product_model` meta, resolves current WC product by SKU via direct `wp_postmeta` query; updates `_product_id` + `_variation_id` in `woocommerce_order_itemmeta`; back-fills id_map on hit; returns `{done, page, orders_scanned, orders_updated, items_relinked}` |

---

## 11. WP-CLI Commands

```bash
# Migration
wp octowoo migrate                                 # Full migration (all enabled migrators)
wp octowoo migrate --dry-run                       # Simulate — no DB writes
wp octowoo migrate --resume                        # Resume from last checkpoint
wp octowoo migrate --migrators=categories,products # Run specific migrators only
wp octowoo migrate --run-id=<uuid>                 # Resume a specific run ID

# Monitoring
wp octowoo status                                  # Show checkpoint progress table
wp octowoo logs                                    # Show recent log entries
wp octowoo logs --level=ERROR --limit=100          # Filter by level
wp octowoo logs --run-id=<uuid>                    # Filter by run ID

# Maintenance
wp octowoo reset --yes                             # Truncate all progress data
wp octowoo reset --run-id=<uuid>                   # Reset a specific run only
wp octowoo test-connection                         # Test OC DB connection
```

---

## 12. Core Services – Internal Architecture

### MigrationManager
Top-level orchestrator. Merges config (defaults ← saved options ← runtime overrides), generates UUID `run_id`, bootstraps shared services, dispatches migrators in `MIGRATOR_ORDER`. Three execution modes: `run(bool $resume)` for WP-CLI, `runNextChunk()` for chunked AJAX (concurrency-guarded), and AS background mode. Bridges `multilingual.enabled` → `migration.run_multilingual` in `buildConfig()`.

### BatchProcessor
Paginated iteration engine. Accepts `total_callback`, `batch_callback`, `item_callback`. Loops in pages of `batch_size`; saves checkpoint after each page; stops on abort signal. In chunk mode (`setChunkMode(true)`), processes exactly one page per call.

### CheckpointManager
Writes to `octowoo_checkpoints`. Status lifecycle: `pending → running → completed/failed/aborted`. Persists `last_oc_id` and `processed_count` (OFFSET for chunk-mode resume). Three-pass `getWcId()`: static in-memory cache → id_map DB → postmeta fallback (backfills on hit). Stale-lock self-healing in `getActiveRunId()` with DB-level `GET_LOCK`.

### DatabaseConnector
Thin PDO wrapper for the OpenCart database. In `source=local` mode re-points to WP DB with `octowoo_oc_*` prefixed tables. Provides `fetchBatch()` (LIMIT/OFFSET), `count()`, `fetchAll()`, `fetchRow()`, `fetchColumn()`, `scanSourceCounts()`. Supports Unix socket.

### VersionDetector
Detects OC major version (1–4) from `oc_setting.config_version` or structural table markers. Provides `seoUrlJoin()` and `quantityColumn()` adapter methods.

### Logger
Five-level logger (DEBUG/INFO/WARNING/ERROR/SUCCESS). File: date-stamped, 10 MB rotation. DB: buffered, flushes every 25 entries. Both independently configurable.

### BackgroundProcessor
Wraps Action Scheduler. `enqueue()` schedules a new AS run and deletes the `octowoo_img_remote_down` image circuit-breaker transient so every fresh run reattempts remote images. `processChunk()` is the AS callback → calls `MigrationManager::runNextChunk()`.

### DataPurger
Force mode: bulk SQL DELETE all WC entities of the requested types. Tagged mode: DELETE only `_octowoo_oc_id`-tagged items. Auto-repairs missing meta via `repairMetaFromIdMap()`. Resets MySQL `AUTO_INCREMENT` on 6 WP tables after full purge. Returns `{results, diagnostics}` with UI hints.

### SqlImporter
Parses `.sql`/`.gz` dumps line-by-line (generator-based). Rewrites `oc_` → `octowoo_oc_`. Strips `SET`, `USE`, `LOCK TABLE`, etc. Imports via `$wpdb->query()`. `dropImportedTables()` for cleanup.

### Encryptor
AES-256-CBC encryption for OC DB password in `wp_options`. Key priority: `OCTOWOO_CRYPT_KEY` → `AUTH_KEY` → fallback. Ciphertext prefixed `octowoo_enc1::`.

### AddonManager
11 filters + 7 actions for third-party customisation. Filters allow modifying entity data before insert; actions fire after each migrator completes.

### WpmlIntegration
Runs last. Chunk-aware via `getProcessedCount()` as SQL OFFSET. Bulk secondary-language tag prefetch (`prefetchSecLangTagsForProducts()`). Creates translated post/term copies; links via WPML or Polylang. Full Yoast SEO meta; primary-language fallback. Slug-fix and content-force via direct DB write. Secondary-language SEO redirects. Post-sweep parent fix (`fixSecLangTermParents()`). Fully language-agnostic — no language code is hardcoded.

---

## 13. Product Migration – Status Mapping

| OC `status` | WC `post_status` | Visible in WC store |
|---|---|---|
| `1` (enabled) | `publish` | Yes |
| `0` (disabled) | `draft` | No (admin only) |

All products (enabled and disabled) are migrated. The "Scan Source DB" count matches the migrator count since both now use no status filter.

---

## 14. Category Assignment – How It Works

Categories are assigned to products via `assignCategories()`, called in both `createProduct()` and `updateProduct()`:

1. ProductMigrator pre-fetches `oc_product_to_category` → `$categories[oc_product_id] = [oc_cat_id, ...]`
2. For each OC category ID, `CheckpointManager::getWcId('category', $oc_cat_id)` looks up the WC term ID
3. `wp_set_object_terms($post_id, $wc_term_ids, 'product_cat')` assigns all categories at once

**On re-run (`on_duplicate=update`):** `updateProduct()` calls `assignCategories()` after updating price/stock/meta — so categories are always corrected on any subsequent Products run, even if they were missing from the first run.

---

## 15. Customer Migration – Field Reference

| OC Field | Migrated? | Where |
|---|---|---|
| `firstname`, `lastname` | Yes | WP user + billing/shipping meta |
| `email` | Yes | WP user login/email + `billing_email` |
| `telephone` | Yes | `billing_phone` |
| `date_added` | Yes | `user_registered` |
| `newsletter` | Yes | `woocommerce_marketing_optin_status` + `_octowoo_newsletter_optin` |
| `password`, `salt` | Opt-in only | `_octowoo_oc_password_hash/salt` (deleted after first login upgrade) |
| `status` | Filter only | Only `status=1` customers imported |
| `oc_address` | Yes | Full billing + shipping address |
| `token`, `code`, `cart`, `wishlist`, `ip`, `safe`, `custom_field`, `fax`, `customer_group`, `store_id` | Never | Sensitive/irrelevant — not imported |

---

## 16. Password Migration (Optional)

When `woocommerce.migrate_oc_passwords = true`:
1. OC hash/salt stored as `_octowoo_oc_password_hash/salt` user meta during CustomerMigrator.
2. Filter `octowoo_oc_password_compat` (priority 30) registered on `wp_authenticate_user`.
3. First login: verifies `sha1( $salt . sha1( $salt . sha1( $plaintext ) ) )`.
4. Success: calls `wp_set_password()`, refreshes `user_pass` from DB, deletes OC meta + `default_password_nag`.
5. All subsequent logins use native WP phpass.

**Default: disabled.** Customers receive a random WP password and are prompted to reset.

---

## 17. Multilingual Support (WPML / Polylang)

When `multilingual.enabled = true`, `WpmlIntegration` runs as the final migrator:
- Reads secondary-language meta stored by migrators during the primary pass.
- Creates translated post/term copies; links via WPML or Polylang.
- **Primary-language fallback**: every product/category gets a secondary-language translation entry even when secondary content is absent.
- **Slug fix**: `post_name` forced to match primary-language slug.
- **Full WC meta copy**: SKU, price, stock, gallery, product_type, product_tag, brand terms, product_cat.
- **Yoast SEO**: all three keys written; falls back to primary-language values.
- **Secondary-language redirects**: old `/{secondary_lang}/oc-slug` → new WPML URL.
- **Chunk-aware**: uses `getProcessedCount()` as OFFSET; safe to resume after AS timeout.
- **Cannot run unless enabled**: enforced in `buildConfig()` and AjaxHandler.
- **Fully language-agnostic**: no language name or code is hardcoded — all values come from `multilingual.primary_locale` / `multilingual.secondary_locale` config.

---

## 18. SEO & Redirects

`SeoMigrator` reads `oc_seo_url` and:
1. Updates WC entity slug.
2. Builds redirect map: old OC URL → new WC URL.
3. **Chunk-aware**: LIMIT/OFFSET pagination; safe in AS tasks.
4. Persists via: `.htaccess` managed block (`write_htaccess = true`) and/or WP `template_redirect` option (`use_wp_redirects = true`).

---

## 19. Settings Enforcement

Two layers prevent disabled migrators from running:

1. **Template** (`templates/admin-dashboard.php`): entity checkboxes and Step 2 options init their `checked` state from `$config['migration'][...]` so the UI always reflects saved Settings.

2. **AjaxHandler**: both `actionRunChunk()` and `actionStartBackground()` intersect the AJAX `migrators` list with saved Settings. A migrator disabled in Settings remains `false` even if checked in the Migration tab UI.

---

## 20. Known Issues & Limitations

### 19.1 Variable Products
Only `select` and `radio` OC option types → variations. File-upload, date, image-linked options not migrated. Products with >50 variation combinations imported as simple.

### 19.2 Image Migration
Images must be physically accessible (path or HTTP). Use ZIP upload for cross-server migrations. Remote HTTP is circuit-breaker protected.

### 19.3 Batch Size & Timeouts
Default `batch_size=20`. Increase to 50–100 on powerful hosting. Use WP-CLI or Background mode for large stores.

### 19.4 Order Taxes
Order-level tax totals migrated as a fee line item. Per-line-item tax not recreated.

### 19.5 Bundle Migration
Requires paid **WooCommerce Product Bundles** (SomewhereWarm). OC 4.x only.

### 19.6 Multilingual
Only products, categories, and pages translated. Primary + one secondary language per run.

### 19.7 SEO Redirects
`.htaccess` requires Apache + writable file. Use WP-redirect option for Nginx.

### 19.8 SQL Dump Import
Stored procedures, views, triggers not imported. Large dumps (>500 MB) need PHP limits raised.

### 19.9 OC Password Compatibility
Only standard `sha1(salt·sha1(salt·sha1(plaintext)))` scheme. Non-standard not covered.

### 19.10 Reviews
Imported reviews get `verified = 0`. Enable "all reviews allowed" in WC settings to display them.

---

## 21. Complete Build Status

| Component | Status |
|---|---|
| Plugin bootstrap & autoloader | ✅ Done |
| Activation (DB tables, log dir, options) | ✅ Done |
| Deactivation (cron cleanup) | ✅ Done |
| Uninstall (full data removal) | ✅ Done |
| Admin menu & dashboard UI (3 tabs) | ✅ Done |
| Admin settings form (DB, paths, toggles, multilingual) | ✅ Done |
| All AJAX action handlers (20+) | ✅ Done |
| Settings enforcement (template init + AjaxHandler intersection) | ✅ Done |
| Button state machine (idle/running/paused; BG buttons corrected) | ✅ Done |
| Parallel-run prevention (BG buttons disabled during AJAX run) | ✅ Done |
| Reset Progress (full UI + DB state cleared) | ✅ Done |
| Admin CSS & JS (tabs, progress polling, expand-rows) | ✅ Done |
| Logger (file + DB, rotation, buffered) | ✅ Done |
| DatabaseConnector (PDO, all query helpers, socket support, utf8mb4) | ✅ Done |
| VersionDetector (OC 1–4, structural detection, SQL adapters) | ✅ Done |
| CheckpointManager (resume, id_map, three-pass getWcId, stale-lock, DB GET_LOCK) | ✅ Done |
| BatchProcessor (pagination, resume, demo limit, dry-run, chunk mode) | ✅ Done |
| MigrationManager (orchestrator; full + chunked + background; multilingual bridge) | ✅ Done |
| CronManager (schedules, delta sync) | ✅ Done |
| DataPurger (force + tagged rollback, bulk SQL, diagnostics, AUTO_INCREMENT reset) | ✅ Done |
| SqlImporter (SQL/GZ parser, prefix rewrite, generator) | ✅ Done |
| Encryptor (AES-256-CBC credential encryption) | ✅ Done |
| MigrationReport (per-migrator stats, persisted) | ✅ Done |
| PreMigrationScanner (counts all products regardless of status; image + variation scan) | ✅ Done |
| Validator (12-point pre-flight config check) | ✅ Done |
| BackgroundProcessor (Action Scheduler; image circuit-breaker cleared on enqueue) | ✅ Done |
| AddonManager (11 filters + 7 actions) | ✅ Done |
| TaxMigrator | ✅ Done |
| OrderStatusMigrator (+ custom WC status registration) | ✅ Done |
| CategoryMigrator (hierarchy, SEO, images, secondary-language meta, Yoast meta) | ✅ Done |
| ManufacturerMigrator (brand taxonomy detection, images, product assignment) | ✅ Done |
| ImageMigrator (MD5 deduplication, sideload, HTTP fallback, circuit-breaker, OC path caching) | ✅ Done |
| ProductMigrator (simple + variable, attributes, variations, specials, status→post_status, Yoast primary+secondary) | ✅ Done |
| ProductMigrator → assignCategories() called on BOTH create AND update (v2.4.67) | ✅ Done |
| RelatedProductsMigrator (upsells) | ✅ Done |
| BundleMigrator (OC4, WC Product Bundles plugin) | ✅ Done |
| CustomerMigrator (addresses, newsletter consent, OC password hash migration) | ✅ Done |
| OrderMigrator (line items, totals, status mapping) | ✅ Done |
| OrderMigrator – SKU/model fallback (`findProductBySku()`) + always-stored `_octowoo_oc_product_model` meta (v2.4.68) | ✅ Done |
| OrderMigrator – `relinkOrderItems()` called on `on_duplicate=update` to re-link broken `_product_id` refs (v2.4.68) | ✅ Done |
| AjaxHandler – `octowoo_repair_order_items` batched AJAX action + "🔗 Repair Order Items" admin button (v2.4.68) | ✅ Done |
| CouponMigrator (percent + fixed, limits, expiry, products) | ✅ Done |
| SeoMigrator (.htaccess + WP redirects, products/categories/pages, chunk-aware) | ✅ Done |
| InformationMigrator (static pages, SEO, secondary-language meta) | ✅ Done |
| TagMigrator (comma-delimited tags, append mode) | ✅ Done |
| FilterMigrator (groups → pa_* attributes, filters → terms, product assignment) | ✅ Done |
| DownloadMigrator (file copy, meta, downloadable flag) | ✅ Done |
| ReviewMigrator (WP comments, rating meta, cache flush) | ✅ Done |
| WpmlIntegration – fully language-agnostic; no hardcoded language names (v2.4.66) | ✅ Done |
| WpmlIntegration – prefetchSecLangTagsForProducts() N+1 elimination | ✅ Done |
| WpmlIntegration – fixSecLangTermParents() post-sweep | ✅ Done |
| WpmlIntegration – forceTranslationContent() direct DB write | ✅ Done |
| WpmlIntegration – source_language_code=null for primary (WPML "(0)" fix, v2.4.65) | ✅ Done |
| WpmlIntegration – Yoast focuskw bug fixed in createTranslatedTerm (v2.4.66) | ✅ Done |
| WpmlIntegration – secondary-language SEO redirects | ✅ Done |
| WpmlIntegration – chunk-aware (WPML + Polylang; posts + terms; N+1 eliminated) | ✅ Done |
| WP-CLI command class (migrate/status/logs/reset/test-connection) | ✅ Done |
| OC password compat filter (correct sha1 formula; phpass upgrade; user_pass refresh) | ✅ Done |

---

## 22. Changelog Summary

| Version | Key Changes |
|---|---|
| **2.4.68** | Added: SKU/model fallback for order-item product linking; `relinkOrderItems()` called on `on_duplicate=update`; `octowoo_repair_order_items` batched AJAX action; "🔗 Repair Order Items" admin button; `_octowoo_oc_product_model` always stored on every WC order item; `findProductBySku()` direct `wp_postmeta` query (bypasses stale `wc_product_meta_lookup`) |
| **2.4.67** | Fixed: `updateProduct()` now calls `assignCategories()` so re-running Products step always corrects category assignment for existing products |
| **2.4.66** | Code quality: All `_ar`-suffixed variables, `$ar_*` cache keys, `prefetchArTagsForProducts()` → `prefetchSecLangTagsForProducts()`, `fixArabicTermParents()` → `fixSecLangTermParents()`, and all Arabic/English comments renamed to generic primary/secondary terminology. Fixed Yoast `focuskw` meta key bug in `createTranslatedTerm` |
| **2.4.65** | Fixed: WPML `source_language_code` must be `null` for primary-language originals (was causing primary categories to appear as "(0)" in WPML). Fixed: Fallback term query was including secondary-language WPML stub terms as primary terms |

---

## 23. Order Migration — Deep Reference

### 23.1 Why Order Items Lose Product Links

**The problem** arises in this exact sequence:
1. Full migration runs → Products migrated → `octowoo_id_map` has `product: oc_id → wc_id`.
2. Orders migrated → each order line item gets `_product_id = wc_id` via id_map lookup.
3. Operator resets progress ("Reset Progress" button) → **id_map is cleared**.
4. Products re-migrated → WP assigns **new post IDs** to the same products. id_map now maps `oc_id → new_wc_id`.
5. Orders are already at `PHP_INT_MAX` in the checkpoint (completed) → **not re-processed by default**.
6. Result: `woocommerce_order_itemmeta._product_id` = old deleted post ID → WC order shows revenue (from stored totals) but broken/missing product name, link, and image.

### 23.2 Fix Architecture

Three layers were added in v2.4.68:

**Layer 1 — `addOrderItem()` always stores OC refs**
Every order line item now gets two meta entries regardless of whether a WC product was found:
- `_octowoo_oc_product_id` — the OpenCart `product_id` integer
- `_octowoo_oc_product_model` — the OpenCart `model` field (= the SKU in WC)

These survive across product re-migrations and are the key to all repair operations.

**Layer 2 — SKU fallback in `addOrderItem()` and `relinkOrderItems()`**

Strategy 1 (fast path): `CheckpointManager::getWcId('product', $oc_prod)` — hits in-memory cache → id_map table → postmeta fallback. Warm when migration runs in order.

Strategy 2 (SKU fallback): when Strategy 1 returns null or a deleted product:
```php
SELECT pm.post_id FROM wp_postmeta pm
INNER JOIN wp_posts p ON p.ID = pm.post_id
WHERE pm.meta_key = '_sku' AND pm.meta_value = %s
  AND p.post_type IN ('product','product_variation')
  AND p.post_status != 'trash'
LIMIT 1
```
Why direct query instead of `wc_get_product_id_by_sku()`? Because WC's lookup table (`wc_product_meta_lookup`) is populated via `WC_Product::save()`. Products created via `update_post_meta()` (as OctoWoo does for performance) are NOT in that table. Direct `wp_postmeta` join is always reliable.

Back-fills id_map on SKU hit: `$this->checkpoint->saveIdMap('product', $oc_prod, $product->get_id())` — so subsequent lookups in the same request use the fast path.

**Layer 3 — `relinkOrderItems()` on `on_duplicate=update`**

When OrderMigrator encounters an already-migrated order (`$existing_wc_id !== null`) and the config is `on_duplicate=update`, it calls `relinkOrderItems($wc_order_id, $oc_products[$oc_id] ?? [])` before logging the skip.

`relinkOrderItems()` works entirely on the WC side:
1. Calls `wc_get_order($wc_order_id)` — works with both HPOS and legacy tables.
2. Iterates `$order->get_items()` — only `WC_Order_Item_Product` instances.
3. For each item: reads `_octowoo_oc_product_id` + `_octowoo_oc_product_model` meta.
4. Resolves current WC product via id_map → SKU fallback.
5. Direct DB: `$wpdb->update(woocommerce_order_itemmeta, ['meta_value' => $new_id], ['order_item_id' => $item_id, 'meta_key' => '_product_id'])`.
6. Resets `_variation_id` to 0 (re-link to parent product; variation linking not attempted).
7. Back-fills `_octowoo_oc_product_model` if missing (covers pre-2.4.68 items).
8. Calls `$order->get_data_store()->clear_caches($order)` once after all items.
9. Logs `INFO: Relinked N items in order #X`.

### 23.3 `octowoo_repair_order_items` AJAX Action

**Purpose**: standalone repair tool — fixes broken order-product links without re-running any migrator. Works entirely from WP-side data; does NOT need the OC database.

**Algorithm**:
1. Paged via `wc_get_orders(['meta_key' => '_octowoo_oc_order_id', 'meta_compare' => 'EXISTS', 'limit' => $batch_size, 'paged' => $page, 'return' => 'ids'])`.
2. For each order: `wc_get_order()` → iterate `get_items()` → read `_octowoo_oc_product_id` + `_octowoo_oc_product_model`.
3. Resolution: `CheckpointManager::getWcId()` → SKU fallback direct query → back-fill id_map on hit.
4. Only updates when `$new_id !== $old_id` (old = current `get_product_id()`).
5. Updates via `wc_update_order_item_meta()` (HPOS-safe API).
6. State between AJAX calls stored in `octowoo_repair_order_page` transient (1-hour TTL).
7. First call must send `reset=1` in POST body (JS sends on first click → `repairOrderItems(true)`).
8. Returns: `{done: bool, relinked: int, processed: int, message: string}`.
9. JS loops until `done=true`, accumulating `totalRelinked` across batches and displays final `alert()`.

**JS function** `repairOrderItems()` in `admin/js/octowoo-admin.js`:
- Disables `#ow-btn-repair-order-items` during repair.
- Updates button label with running count: `🔗 Repairing… (N linked)`.
- Recursive `runBatch(isFirst)` closure — calls itself until `res.data.done === true`.
- Restores button text on completion or error.
- The button is included in `$btnRecovery` selector → automatically disabled during a migration run.

### 23.4 Order Meta Keys Reference

| Meta Key | Where stored | Value |
|---|---|---|
| `_octowoo_oc_order_id` | `wp_postmeta` (or HPOS) | OC `order_id` integer |
| `_octowoo_oc_customer_id` | `wp_postmeta` | OC `customer_id` |
| `_octowoo_oc_id` | `wp_postmeta` | Same as `_octowoo_oc_order_id` (generic key for cross-migrator lookups) |
| `_octowoo_oc_order_number` | `wp_postmeta` | Original OC order number (for cross-reference display) |
| `_order_number`, `_order_number_formatted` | `wp_postmeta` | Sequential Order Numbers plugin compat (written only when plugin active) |

| Order Item Meta Key | Where stored | Value |
|---|---|---|
| `_octowoo_oc_product_id` | `wp_woocommerce_order_itemmeta` | OC `product_id` — always written, survives re-migration |
| `_octowoo_oc_product_model` | `wp_woocommerce_order_itemmeta` | OC `model` field (= WC SKU) — written when non-empty |
| `_product_id` | `wp_woocommerce_order_itemmeta` | WC `post_id` of the linked product — updated by repair |
| `_variation_id` | `wp_woocommerce_order_itemmeta` | 0 (simple) or variation post_id — reset to 0 on repair |

### 23.5 HPOS Compatibility

- `wc_get_order()` is the correct API call — handles both HPOS and legacy `wp_posts`-based orders.
- `$order->get_items()` — HPOS-safe.
- `wc_update_order_item_meta()` — HPOS-safe (items stored in `wp_woocommerce_order_items` / `wp_woocommerce_order_itemmeta` even in HPOS mode).
- `$order->add_meta_data()` + `$order->save()` — use these for order-level meta, not `update_post_meta()`.
- `$order->get_data_store()->clear_caches($order)` — works in both modes.

---

## 24. Product Migration — Deep Reference

### 24.1 Duplicate Detection Strategy

Two-pass duplicate check in `processProduct()`:

**Pass 1**: `CheckpointManager::getWcId('product', $oc_id)` — checks in-memory cache → id_map table → postmeta fallback (`_octowoo_oc_id`). Warm on resume/re-run.

**Pass 2**: Direct SKU query (when Pass 1 returns null):
```php
SELECT post_id FROM wp_postmeta
INNER JOIN wp_posts ON ID = post_id
WHERE meta_key = '_sku' AND meta_value = %s
  AND post_type IN ('product','product_variation')
  AND post_status != 'trash'
LIMIT 1
```
Why not `wc_get_product_id_by_sku()`? Because `wc_product_meta_lookup` is populated only by `WC_Product::save()`. OctoWoo writes via `update_post_meta()` for performance — those products are NOT in the lookup table. **Always use direct `wp_postmeta` queries for SKU lookups.**

### 24.2 Product Status Mapping

| OC `status` | WC `post_status` |
|---|---|
| `1` | `publish` |
| `0` | `draft` |

Both are migrated. No status filter on the SQL query — so `PreMigrationScanner::scanCounts()` and the migrator count match exactly.

### 24.3 Update Mode (`on_duplicate=update`)

`updateProduct($existing_wc_id, ...)` does:
1. `wp_update_post()` — title, content, slug.
2. Updates all product meta: price, sale price, SKU, stock, weight, dimensions, tax class, Yoast SEO.
3. Sets product type via `wp_set_object_terms($id, $type, 'product_type')`.
4. Calls `assignCategories()` — **this was broken before v2.4.67**.
5. Calls `assignImages()` — re-sets `_thumbnail_id` and gallery.
6. Re-creates variations for variable products.
7. Calls `wp_set_object_terms($id, $tags, 'product_tag', true)` — append mode.

### 24.4 Images — Why 500 Were Missing

`ImageMigrator` attempts four strategies per image path:
1. Local filesystem: `$config['opencart']['image_path'] . DIRECTORY_SEPARATOR . $path`.
2. HTTP: `$config['opencart']['shop_url'] . '/image/' . $path` (4 retries, 30s timeout).
3. ZIP-extracted cache.
4. Attachment cache: `findAttachmentByOcPath($path)` — finds already-imported attachment by `_octowoo_oc_image_path` meta.

`findAttachmentByOcPath()` uses:
```sql
SELECT pm.post_id FROM wp_postmeta pm
INNER JOIN wp_posts p ON p.ID = pm.post_id
WHERE pm.meta_key = '_octowoo_oc_image_path'
  AND pm.meta_value = %s
  AND p.post_type = 'attachment'
LIMIT 1
```
The `post_type = 'attachment'` guard is critical — without it, the query could match the product post itself (which also stores `_octowoo_oc_image_path`) and return a non-attachment ID as `_thumbnail_id`.

**Circuit breaker**: `octowoo_img_remote_down` transient. Set when 3 consecutive HTTP image fetches fail. Prevents hammering a down server. Cleared by `BackgroundProcessor::enqueue()` on every fresh run. Does NOT auto-clear between batches.

**Repair path for 500 missing images**: Run "Products + Images Recovery" with `on_duplicate=skip`. In `processProduct()`, skip-mode checks `!get_post_thumbnail_id($existing_wc_id)` and re-calls `assignImages()` — so only products with no thumbnail are repaired. Zero risk of overwriting correct thumbnails.

### 24.5 Variable Products — Variation Limit

OC products with >50 variation combinations (computed as product of all option values) are imported as **simple products** with attributes marked `is_variation=false`. A warning is logged.

The `PreMigrationScanner` pre-warns about these products before the migration starts.

---

## 25. Category Migration — Deep Reference

### 25.1 Topological Sort

`CategoryMigrator::fetchAllCategoriesTopological()` pre-fetches all OC categories and sorts them by parent-first order using a Kahn's-algorithm-style BFS. This ensures parent `product_cat` terms always exist in WC before their children are inserted — no deferred-parent fallback needed.

The full sorted array is kept in memory for the migration run. `BatchProcessor` slices it with `array_slice($sorted_rows, $offset, $limit)` instead of SQL LIMIT/OFFSET — so the topological order is preserved across batch boundaries.

### 25.2 Secondary-Language Meta Stored by CategoryMigrator

```php
update_term_meta($term_id, '_octowoo_name' . $sfx,        $sec_name);
update_term_meta($term_id, '_octowoo_description' . $sfx, $sec_desc);
update_term_meta($term_id, '_octowoo_metatitle' . $sfx,   $sec_metatitle);
update_term_meta($term_id, '_octowoo_metadesc' . $sfx,    $sec_metadesc);
update_term_meta($term_id, '_octowoo_metakw' . $sfx,      $sec_metakw);
```
`$sfx = secLangSuffix()` = `'_' . $config['multilingual']['secondary_locale']`. Example: `_ar`.

`WpmlIntegration::translateTermsFromRows()` reads these meta keys and creates the secondary-language term copy.

---

## 26. WpmlIntegration — Deep Reference

### 26.1 Full Execution Flow

1. `migrate()` entry point — called last in MIGRATOR_ORDER.
2. Detects active multilingual plugin (WPML → Polylang → none).
3. Switches to secondary language: `do_action('wpml_switch_language', $secondary_locale)`.
4. Phase 1: Translate categories (`translateTermsFromRows()`).
5. Phase 2: Translate products (`translatePostsFromRows()`).
6. Phase 3: Translate information pages (`translatePostsFromRows()` with different meta keys).
7. Post-sweep: `fixSecLangTermParents()` corrects secondary-language category parent links.
8. Restores primary language: `do_action('wpml_switch_language', $primary_locale)`.

All phases are chunk-aware via `getProcessedCount()` as SQL OFFSET.

### 26.2 `forceTranslationContent()` — Why It Exists

WPML fires `wpml_save_post_translation_data` and related hooks when a translated post is saved. These hooks can overwrite `post_content` and `post_title` with the primary-language values (WPML's "translation editor" sync). To bypass this, after all save hooks fire, OctoWoo performs a direct DB write:

```php
$wpdb->update(
    $wpdb->posts,
    ['post_content' => $sec_content, 'post_title' => $sec_title],
    ['ID' => $translated_post_id]
);
clean_post_cache($translated_post_id);
```

This also re-applies `_thumbnail_id` after WPML may have cleared it.

### 26.3 `source_language_code` = `null` for Primary Language

WPML's `wpml_set_element_language_details` action requires:
- For the **original** post/term: `source_language_code = null` (NOT `'en'`). Setting it to `'en'` tells WPML "this item was translated FROM English" — making it a translation stub, not the original. This caused English categories to appear as "English (0)".
- For the **translated** post/term: `source_language_code = $primary_locale`.

### 26.4 Fallback Term Query Bug (v2.4.65 Fix)

When `id_map` is empty and WpmlIntegration needs the primary-language WC term for a category, it falls back to a `get_term_by('name', $name, 'product_cat')` query. Before v2.4.65, this returned ANY term with that name — including WPML-generated secondary-language stub terms. The fix adds a WPML exclusion:

```php
$excluded = apply_filters('wpml_object_id', 0, 'product_cat', false, $primary_locale);
// If found term is a WPML secondary-language term → skip it
```

### 26.5 Secondary-Language Meta Key Pattern

All secondary-language data is keyed as `_octowoo_{field}_{suffix}` where `{suffix} = secondary_locale`:

| Meta key pattern | Stored by | Read by |
|---|---|---|
| `_octowoo_name_{sfx}` | ProductMigrator, CategoryMigrator, InformationMigrator | WpmlIntegration |
| `_octowoo_description_{sfx}` | ProductMigrator, CategoryMigrator, InformationMigrator | WpmlIntegration |
| `_octowoo_short_description_{sfx}` | ProductMigrator | WpmlIntegration |
| `_octowoo_metatitle_{sfx}` | ProductMigrator, CategoryMigrator | WpmlIntegration |
| `_octowoo_metadesc_{sfx}` | ProductMigrator, CategoryMigrator | WpmlIntegration |
| `_octowoo_metakw_{sfx}` | ProductMigrator, CategoryMigrator | WpmlIntegration |
| `_octowoo_title_{sfx}` | InformationMigrator | WpmlIntegration (pages only) |
| `_octowoo_desc_{sfx}` | InformationMigrator | WpmlIntegration (pages only) |
| `_octowoo_oc_image_path` | ImageMigrator, ProductMigrator | ImageMigrator (dedup) |

**IMPORTANT**: InformationMigrator uses `_octowoo_title_{sfx}` / `_octowoo_desc_{sfx}` (not `_octowoo_name_{sfx}` / `_octowoo_description_{sfx}`) because the OC field is named `title`, not `name`. WpmlIntegration reads both patterns correctly.

---

## 27. CheckpointManager — Deep Reference

### 27.1 ID Map — Three-Pass `getWcId()`

```
Pass 0: static $id_map_cache array — free, zero DB queries.
        Key format: "{entity}:{oc_id}"
        Populated by saveIdMap() and any previous getWcId() hit.

Pass 1: SELECT wc_id FROM octowoo_id_map
        WHERE entity_type = %s AND oc_id = %d

Pass 2: postmeta / termmeta / usermeta fallback
        Entity-specific meta key lookup:
          product   → _octowoo_oc_product_id (wp_postmeta)
          category  → _octowoo_oc_category_id (wp_termmeta)
          customer  → _octowoo_oc_customer_id (wp_usermeta)
          order     → _octowoo_oc_order_id (wp_postmeta)
          coupon    → _octowoo_oc_coupon_id (wp_postmeta)
        On hit: back-fills id_map table + in-memory cache.
```

### 27.2 Stale Lock Self-Healing

`getActiveRunId()` checks three independent conditions that each clear the lock:

1. **All-terminal**: if all checkpoints for the run are in a terminal state (completed/failed/aborted), the run is declared finished.
2. **Heartbeat gap > 15 min**: `octowoo_run_started_at` older than 15 minutes with no checkpoint `updated_at` in the last 15 minutes → stale.
3. **No rows > 15 min**: lock has been held for >15 minutes but all checkpoint rows still show 0 `processed_count` → stale.

When stale: calls `markRunFinished()`, deletes `octowoo_active_run_id`, returns null. The admin UI then shows "idle".

### 27.3 Checkpoint Status Lifecycle

```
ensureExists() → 'pending'
start()        → 'running'
update()       → 'running' (updates last_oc_id, processed_count, updated_at)
complete()     → 'completed' (sets last_oc_id = PHP_INT_MAX)
fail()         → 'failed'
abort()        → 'aborted'
```

`isCompleted($key)`: checks `last_oc_id = PHP_INT_MAX` OR `status = 'completed'`.

When resuming: `getProcessedCount($key)` returns `processed_count` (used as SQL OFFSET in chunk mode).

### 27.4 DB-Level Locking

`markRunActive()` calls `SELECT GET_LOCK('octowoo_migration', 10)` — waits up to 10 seconds for a MySQL advisory lock. This prevents two concurrent migration processes (e.g., AS + AJAX) from running the same batches in parallel.

`markRunFinished()` calls `SELECT RELEASE_LOCK('octowoo_migration')`.

---

## 28. AjaxHandler — Deep Reference

### 28.1 Security Model

Every AJAX action goes through `dispatch()` which checks:
1. `current_user_can('manage_woocommerce') || current_user_can('manage_options')` — returns HTTP 403 on fail.
2. `check_ajax_referer('octowoo_ajax', 'nonce', false)` — returns HTTP 200 with `success=false` on fail (so JS `.done()` handler receives a readable error, not `.fail()`).

All request logging masks fields named `db_pass`, `password`, `db_password`.

### 28.2 `actionRunChunk()` — Settings Enforcement

```php
$saved = AdminPage::getConfig()['migration'] ?? [];
foreach ($ajax_migrators as $key) {
    $run_key = 'run_' . $key;
    if (isset($saved[$run_key]) && !$saved[$run_key]) {
        // Migrator disabled in Settings — silently exclude from this chunk.
    }
}
```

This is the only safe enforcement point. The JS UI checkbox is for convenience only — a disabled migrator in Settings cannot be re-enabled from the Migration tab UI.

### 28.3 Fatal Error Shutdown Handler

`actionRunChunk()` registers `register_shutdown_function()` that checks `error_get_last()`. If a fatal error occurred mid-chunk, it:
1. Marks the current migrator as `failed` in the checkpoint.
2. Logs the fatal at ERROR level.
3. Sends `wp_send_json_error(['fatal' => true, 'message' => ...])`.

This prevents the JS migration loop from silently stalling on a PHP fatal.

### 28.4 Concurrency Lock for Chunk Requests

`octowoo_chunk_lock_{run_id}` transient — TTL 90 seconds. Set at start of `runNextChunk()`; deleted in `finally` block. If a second AJAX chunk request arrives before the first finishes, it receives `{busy: true, done_all: false}`. JS should retry after a short delay.

### 28.5 Adding a New AJAX Action (Pattern)

1. Add the action string to the `$actions` array in `init()`.
2. Add a `case 'octowoo_{action}':` branch in `dispatch()` → `$this->actionYourName(); break;`.
3. Implement `private function actionYourName(): void { ... wp_send_json_success([...]); }`.
4. Never needs explicit `wp_die()` — `wp_send_json_*` terminates automatically.
5. Always verify with `current_user_can()` — `dispatch()` already does nonce + cap for the entire handler.

---

## 29. Migration Lifecycle — Request-by-Request Flow

### Full AJAX migration from "Start Full Migration" click:

```
Browser                              Server
------                               ------
startMigration(false, false)
  └─ $.post octowoo_run_chunk ──────► AjaxHandler::actionRunChunk()
       run_id=null, resume=0            └─ new MigrationManager($overrides, null)
                                           └─ generate run_id (UUID)
                                           └─ bootstrap() — connect OC DB, Logger, etc.
                                           └─ runNextChunk()
                                               └─ acquire chunk lock
                                               └─ markRunActive()
                                               └─ ensureExists() all enabled migrators
                                               └─ find first pending migrator → run ONE batch
                                               └─ release chunk lock
                                               └─ return {done_all:false, migrator:X, chunk:{...}, checkpoints:[...]}
  ◄── {done_all:false, ...} ─────────
  renderProgress(checkpoints)
  runNextChunk() [recursive]
  └─ $.post octowoo_run_chunk ──────► same pattern, resume=1, run_id=<uuid>
       run_id=<uuid>, resume=1           └─ each call processes ONE batch of ONE migrator
                                           └─ when migrator completes: is_done=true → next migrator next call
  ... (loops until done_all=true)
  ◄── {done_all:true} ──────────────
  setButtonState('idle')
  showReport()
```

### Key timing facts
- Each AJAX call handles `batch_size` items (default 20).
- No single request runs longer than `batch_size × per_item_time`.
- PHP `max_execution_time` is effectively bypassed by batching.
- Progress is visible after every single batch via `actionGetProgress()` polling.
- JS polls both via the chunk response (inline `checkpoints`) and a separate `octowoo_get_progress` call every 5 seconds.

---

## 30. Image Migration — Detailed Strategy

### 30.1 Four-Strategy Waterfall

```
Strategy 1: Local filesystem
  $image_path . DIRECTORY_SEPARATOR . ltrim($oc_path, '/')
  → file_exists() + filesize() > 0

Strategy 2: Attachment cache
  findAttachmentByOcPath($oc_path)
  → SELECT post_id WHERE meta_key='_octowoo_oc_image_path'
    AND meta_value=%s AND post_type='attachment'
  → wc_get_product_id_by_sku() NOT used (stale lookup table)

Strategy 3: Remote HTTP (sideload)
  $shop_url . '/image/' . ltrim($oc_path, '/')
  → wp_remote_get() with 4 retries, 30s timeout
  → circuit breaker: 3 consecutive failures → set 'octowoo_img_remote_down' transient
  → checked before every HTTP attempt; skip HTTP strategy if set

Strategy 4: ZIP-extracted directory
  $zip_extracted_path . DIRECTORY_SEPARATOR . ltrim($oc_path, '/')
  → file_exists() check
```

### 30.2 MD5 Deduplication

Before sideloading, `importByOcPath()` checks if an attachment with the same `_octowoo_oc_image_path` already exists (Strategy 2 above). If found, returns that attachment ID immediately — no duplicate media files.

After import, stores `_octowoo_oc_image_path` meta on the new attachment post so future runs detect it.

### 30.3 Circuit Breaker

`octowoo_img_remote_down` transient (1-hour TTL):
- **Set**: after 3 consecutive HTTP 404/timeout failures.
- **Checked**: before every HTTP sideload attempt.
- **Cleared**: `BackgroundProcessor::enqueue()` deletes it at the start of every fresh run. Never cleared between batches within a run.
- **Effect**: if source server goes down mid-run, products get no image rather than timing out for 30s × 3 retries × every remaining image.

---

## 31. Security Considerations

| Area | Implementation |
|---|---|
| **AJAX security** | All actions: nonce (`octowoo_ajax`) + `manage_woocommerce` cap |
| **Settings save** | `check_admin_referer('octowoo_save_settings')` — separate nonce from AJAX |
| **OC DB password** | AES-256-CBC encrypted at rest; key: `OCTOWOO_CRYPT_KEY` > `AUTH_KEY` > fallback |
| **SQL queries** | All dynamic values use `$wpdb->prepare()` with `%s`/`%d`/`%f` placeholders |
| **OC DB** | Separate PDO connection; read-only queries only; no writes to OC DB |
| **File uploads** | `is_uploaded_file()` check; only `.sql`/`.gz` accepted for SQL upload; only `.zip` for images |
| **Output escaping** | All template output uses `esc_html()`, `esc_attr()`, `esc_url()` |
| **User passwords** | OC passwords never replayed; random WP password assigned; opt-in hash storage |
| **Log directory** | Protected with `.htaccess` (Deny from all) + `index.html` (created on activation) |
| **Uninstall** | All tables, options, and log files removed; no orphan data |
| **Request logging** | Sensitive fields (`db_pass`, `password`) masked with `***` before logging |

---

## 32. Performance Considerations

| Concern | Solution |
|---|---|
| **N+1 product queries** | Pre-fetch `oc_product_description`, `oc_product_to_category`, `oc_product_image`, `oc_product_option`, `oc_product_special` in a single query per batch |
| **N+1 secondary-language tag queries** | `prefetchSecLangTagsForProducts()` fetches all tags for a batch in one query |
| **WP object cache bloat** | `BatchProcessor::maybeReleaseMemory()` clears `posts`, `post_meta`, `terms`, `term_meta` caches between batches; uses `wp_cache_flush_runtime()` on WP 6.0+ |
| **Term count updates** | `wp_defer_term_counting(true)` in `ProductMigrator::migrate()`; re-enabled and flushed once at the end |
| **Object cache invalidation** | `wp_suspend_cache_invalidation(true)` in `ProductMigrator::migrate()` |
| **WC product meta lookup** | Never used for reads (stale); written via `update_post_meta()` for speed; duplicates detected via direct `wp_postmeta` query |
| **Topological sort in memory** | CategoryMigrator fetches all categories once, sorts in PHP, slices with `array_slice()` — avoids per-batch SQL |
| **Batch-aware SEO** | SeoMigrator uses SQL LIMIT/OFFSET; checkpoint `processed_count` as OFFSET |
| **Image deduplication** | MD5 cache checked before every sideload; HTTP only attempted when local+cache miss |
| **Database transactions** | `OrderMigrator` wraps each order creation in START TRANSACTION / COMMIT / ROLLBACK — prevents partial orders |

---

## 33. Developer Conventions

### 33.1 File Editing Rules
- **ONLY edit root-level files** under `d:\store\Woocommerce\Module\Custom Module\OCTOWOO\`.
- **NEVER touch** `OCTOWOO/` subfolder — it is a manual distribution copy; the user syncs it themselves.
- **Full absolute paths** required in all tool calls.

### 33.2 Naming Conventions

| Pattern | Example | Meaning |
|---|---|---|
| `MIGRATOR_ORDER` key | `'products'` | Checkpoint key; must match `'run_products'` config key |
| ID-map entity key | `'product'` (singular) | Used in `saveIdMap()` / `getWcId()` calls across migrators |
| Meta key | `_octowoo_oc_product_id` | Always prefixed `_octowoo_`; OC-side data |
| Secondary-lang suffix | `_ar` | = `'_' . secondary_locale` config value |
| AJAX action | `octowoo_run_chunk` | Registered as `wp_ajax_octowoo_run_chunk` |
| JS button ID | `ow-btn-repair-order-items` | Always `ow-btn-{name}` |
| JS function | `repairOrderItems()` | camelCase; matches button action |

### 33.3 Version Bump Procedure

When releasing a new version:
1. `octowoo.php` line 11: `* Version: X.Y.Z`
2. `octowoo.php` line 25: `define('OCTOWOO_VERSION', 'X.Y.Z')`
3. `readme.txt`: update `Stable tag: X.Y.Z`; add `= X.Y.Z =` section in Changelog
4. `PROJECT-OVERVIEW.md`: add entry to Changelog Summary; update version at top; update Build Status table if needed
5. Optionally run: `php scripts/bump_version.php X.Y.Z` or `.\scripts\bump-version.ps1 X.Y.Z`

### 33.4 Adding a New Migrator

1. Create `src/Migrators/YourMigrator.php` extending `AbstractMigrator`.
2. Implement `migrate(): array` — use `$this->batch->run(...)` with `setChunkMode($this->batch->isChunkMode())`.
3. Add to `MIGRATOR_ORDER` in `MigrationManager.php` at the correct dependency position.
4. Add to `buildMigrator()` `match()` block.
5. Add `'run_your_key' => true` default to `config/default-config.php`.
6. Add checkbox to `templates/admin-dashboard.php` Settings tab.
7. Add to `AdminPage::handleSaveSettings()` config array.
8. Add to `DataPurger::purge()` entity map if data needs rollback support.
9. Add to `AjaxHandler::actionRunChunk()` Settings-enforcement intersection.
10. Add entry to Build Status table in `PROJECT-OVERVIEW.md`.

### 33.5 Adding a New AJAX Action

1. Add the action string to `$actions` array in `AjaxHandler::init()`.
2. Add `case 'octowoo_{action}': $this->actionYourName(); break;` in `dispatch()`.
3. Implement `private function actionYourName(): void`.
4. If the action needs a front-end button: add button HTML to `templates/admin-dashboard.php`; add `$('#ow-btn-{name}').on('click', functionName)` in JS `$(document).ready()`; add JS function; add button ID to `$btnRecovery` selector in `setButtonState()`.

### 33.6 Common Pitfalls

| Pitfall | Correct approach |
|---|---|
| Using `wc_get_product_id_by_sku()` | Use direct `wp_postmeta` query — lookup table is stale for OctoWoo-created products |
| Calling `wc_update_order_item_meta()` via `$wpdb->update` without HPOS guard | `wc_update_order_item_meta()` is HPOS-safe; prefer it over direct `$wpdb->update` |
| Forgetting `post_type = 'attachment'` in `findAttachmentByOcPath()` | Always filter by `post_type` — products also have `_octowoo_oc_image_path` meta |
| Using `wp_set_object_terms()` without `append=true` for tags | Always `append=true` to avoid wiping manually-added tags |
| Calling `new CheckpointManager($run_id, $extra, $args)` | Constructor takes only `string $run_id` — extra args silently ignored by PHP |
| Forgetting to add action to `$actions` array in `init()` | Without registration, `wp_ajax_octowoo_*` hook never fires |
| Using `update_post_meta()` for HPOS order meta | Use `$order->update_meta_data()` + `$order->save()` instead |
| Not calling `$logger->flush()` after repair actions | Buffer may not be written — always flush at end of any non-migrator action |
| Touching files inside `OCTOWOO/` subfolder | This is the distribution copy — only edit root-level files |

---

## 34. Current Version Status

**Version: 2.4.68** — All features complete and production-tested. See §22 Changelog for version history.
