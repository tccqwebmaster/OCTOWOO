# OCTOWOO – Project Overview

**Version:** 2.4.41
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

## 2. Complete Feature List

### Data Migration
| Feature | Details |
|---|---|
| **Tax classes** | OC tax classes → WC tax classes |
| **Order statuses** | OC statuses mapped to WC equivalents; custom WC statuses registered when no match |
| **Product categories** | Full hierarchy (parent/child), SEO slugs, category images, Arabic meta |
| **Manufacturers / Brands** | Brand taxonomy auto-detected (product_brand, pwb-brand, yith_product_brand, berocket_brand, pa_brand); brand images; product assignment |
| **Images** | MD5-based deduplication; sideload to WP media library; fallback HTTP fetch; ZIP upload mode |
| **Products – simple** | Title, description, short description, SKU, price, sale price, stock, weight, dimensions, tax class, categories, tags |
| **Products – variable** | `select` / `radio` OC options → WC attributes + variations; price/stock per variation |
| **Product specials** | OC `product_special` sale prices mapped to WC `_sale_price` |
| **Product filters** | OC filter groups → WC `pa_*` attribute taxonomies; filter values → terms; assigned to products |
| **Product tags** | Comma-delimited OC tags → WC `product_tag` terms |
| **Product bundles** | OC4 `oc_product_bundle` → WooCommerce Product Bundles plugin items |
| **Related products** | OC `product_related` → WC upsell links |
| **Downloadable files** | File copy from OC download dir → WC protected uploads; `_downloadable`, `_download_*` meta |
| **Customers** | WP user account (email, name, role); billing + shipping address from `oc_address`; newsletter opt-in stored as `woocommerce_marketing_optin_status` |
| **OC password migration** | Optional: stores OC hash; on first login verifies `sha1(salt . sha1(salt . sha1(plaintext)))`, upgrades to WP phpass, deletes OC hash |
| **Orders** | Line items, quantities, prices, subtotal, shipping, totals; status mapping; billing/shipping name + address |
| **Coupons** | Percent-off and fixed-cart coupons; usage limits; expiry date; restricted products |
| **SEO slugs** | Reads `oc_seo_url`; updates WC slug for products, categories, pages |
| **301 redirects** | Old OC URLs → new WC URLs via `.htaccess` block and/or WP `template_redirect` option |
| **Information pages** | OC `oc_information` → WP pages; SEO slug; Arabic meta |
| **Product reviews** | WP comments with `rating` meta; comment author, email, date |

### Multilingual (WPML / Polylang)
| Feature | Details |
|---|---|
| **Secondary-language products** | Arabic title, content, excerpt; English fallback when Arabic absent |
| **Arabic short description** | `oc_product.tag` field (secondary lang) → `_octowoo_short_description_ar` |
| **Arabic SKU / price / stock** | `copyProductDataToTranslation()` copies all WC meta + product_type + product_tag + brand terms |
| **Correct Arabic slugs** | `fixTranslationSlug()` forces `post_name` to match English slug — no `-2` suffix |
| **Secondary-language categories** | Arabic name, description; English name fallback |
| **Yoast SEO – English products** | `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw` written from OC `meta_title`, `meta_description`, `meta_keyword` |
| **Yoast SEO – Arabic products** | Reads `_octowoo_metatitle_ar`, `_octowoo_metadesc_ar`, `_octowoo_metakw_ar`; falls back to English Yoast values; writes all three Yoast keys to translated post |
| **Yoast SEO – English categories** | Same three keys written from OC category `meta_title`, `meta_description`, `meta_keyword` |
| **Yoast SEO – Arabic categories** | Same pattern; English Yoast fallback |
| **Arabic product SEO redirects** | Old `/ar/oc-slug` → new `/ar/product/wc-slug/` |
| **Arabic category SEO redirects** | Old `/ar/oc-slug` → new `/ar/product-category/wc-slug/` |
| **Update-existing translations** | Re-runs update title/content/excerpt/SKU/meta/SEO on already-linked translations |

### Infrastructure
| Feature | Details |
|---|---|
| **Batch processing** | Configurable `batch_size`; paginated over any OC table |
| **Checkpoint / resume** | Per-migrator `last_oc_id`; resume after crash or timeout |
| **Dry-run mode** | Full simulation; no writes; logged as `[DRY-RUN]` |
| **Demo limit** | Cap each migrator at N items for a quick sanity check |
| **Duplicate handling** | `skip` or `update` on re-run |
| **ID map** | `octowoo_id_map` table: `oc_id → wc_id` per entity type; two-pass lookup (id_map + postmeta fallback) |
| **Pause / Resume** | Runtime pause and resume controls in the admin UI |
| **Skip migrator** | Skip the currently stuck migrator and continue with the next |
| **Abort** | Cancels AS jobs + marks all running/pending checkpoints as aborted |
| **Stale lock auto-clear** | Active-run lock auto-cleared when all checkpoint rows are terminal or last update > 2 hours |
| **Background processing** | Action Scheduler (WC bundled) for non-blocking browser-independent runs |
| **WP-CLI** | Full command-line migration, status, logs, reset, test-connection |
| **Cron auto-import** | Scheduled delta sync via WP-Cron (`on_duplicate=update`) |
| **SQL dump mode** | Upload `.sql` / `.gz`; importer rewrites `oc_*` prefixes; runs against WP DB |
| **ZIP image mode** | Upload a ZIP of OC images; extracted and used as local image source |
| **Pre-migration scan** | Counts all source entities; flags missing images, oversized variation sets, no-description products |
| **Migration report** | Per-migrator processed/skipped/failed counts; persisted to `wp_options` |
| **Bulk SQL purge** | Single-statement DELETE for customers, orders, reviews, pages, coupons — seconds not minutes |
| **Force vs tagged purge** | Tagged: only removes `_octowoo_oc_*`-tagged items. Force: removes all WC items of that type |
| **Purge diagnostics** | Warns when items exist but have no tag (id_map reset); suggests Force Purge |
| **Meta repair** | `repairMetaFromIdMap()` backfills missing `_octowoo_oc_id` meta before purge |
| **AUTO_INCREMENT reset** | Resets MySQL AUTO_INCREMENT on 6 core WP tables after a full purge |
| **Credential encryption** | AES-256-CBC encryption of OC DB password in `wp_options` (key from `OCTOWOO_CRYPT_KEY` or `AUTH_KEY`) |
| **Add-on hooks** | 11 filters + 7 actions for third-party customisation |
| **Version detection** | Auto-detects OC 1/2/3/4 from DB; adapts SQL per version |
| **Logger** | 5-level (DEBUG/INFO/WARNING/ERROR/SUCCESS); file (10 MB rotation) + DB; buffered writes |
| **Validation** | Pre-flight config validation with warnings |

---

## 3. Requirements

| Dependency | Minimum Version |
|---|---|
| WordPress | 5.8+ |
| PHP | 7.4+ |
| WooCommerce | 6.0+ |
| MySQL / MariaDB | (any modern version supporting utf8mb4) |

**Optional integrations:**
- WPML or Polylang (multilingual translation pass)
- Yoast SEO (SEO meta migration)
- WooCommerce Product Bundles (OC4 bundle migration)
- Any WooCommerce-compatible brand plugin (product_brand, pwb-brand, yith_product_brand, berocket_brand)
- WP-CLI (command-line migration)

---

## 4. Directory Structure

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
    │   └── AjaxHandler.php       ← All wp_ajax_* action handlers
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
    │   ├── SqlImporter.php       ← SQL dump parser & importer
    │   ├── Encryptor.php         ← AES-256-CBC credential encryption
    │   ├── MigrationReport.php   ← Post-run summary report
    │   ├── PreMigrationScanner.php ← Pre-flight entity counts & issue detection
    │   ├── BackgroundProcessor.php ← Action Scheduler integration
    │   └── Validator.php         ← Pre-flight config validation
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

## 5. Database Tables Created

| Table | Purpose |
|---|---|
| `{prefix}octowoo_logs` | Migration log entries (level, message, context, run_id) |
| `{prefix}octowoo_checkpoints` | Per-migrator progress (last_oc_id, processed_count, status) |
| `{prefix}octowoo_id_map` | OpenCart ID → WooCommerce ID cross-reference (per entity type) |

---

## 6. WordPress Options Used

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
| `octowoo_redirects` | `[old_path => new_url]` map served by `SeoMigrator::handleWpRedirect()` |
| `octowoo_last_report` | Per-migrator stats from last completed run |

---

## 7. Configuration Reference

All settings live under `octowoo_config` and are merged over `config/default-config.php`.

| Section | Key | Default | Description |
|---|---|---|---|
| source | `source` | `remote` | `remote` (live DB) or `local` (SQL dump) |
| db | `host`, `port`, `database`, `username`, `password`, `prefix`, `socket` | — | OpenCart DB credentials |
| opencart | `image_path`, `download_path`, `shop_url`, `language_id`, `language_id_secondary`, `version` | — | OC install paths & language config |
| migration | `batch_size` | `20` | Items per batch |
| migration | `dry_run` | `false` | Simulate without writing |
| migration | `demo_limit` | `0` | Cap migrators at N items (0 = unlimited) |
| migration | `on_duplicate` | `skip` | `skip` or `update` on re-run |
| migration | `run_*` flags | `true` per entity | Toggle individual migrators on/off |
| seo | `write_htaccess`, `use_wp_redirects` | — | Redirect strategy |
| multilingual | `enabled`, `use_wpml`, `use_polylang`, `primary_locale`, `secondary_locale` | — | Translation settings |
| cron | `enabled`, `interval`, `migrators` | — | Scheduled auto-import |
| woocommerce | `force_password_reset`, `migrate_oc_passwords`, `customer_role`, `default_order_status` | — | WC behaviour options |
| logging | `file_enabled`, `db_enabled`, `min_level`, `max_file_size` | — | Logging configuration |

---

## 8. Migration Execution Order

MigrationManager runs migrators in this fixed sequence (dependencies first):

```
1.  TaxMigrator             → Creates WC tax classes (needed by ProductMigrator)
2.  OrderStatusMigrator     → Builds OC→WC status map (needed by OrderMigrator)
3.  CategoryMigrator        → Product categories with hierarchy
4.  ImageMigrator           → Media library import (needed by ProductMigrator)
5.  ProductMigrator         → Products (simple/variable), attributes, variations
6.  ManufacturerMigrator    → Brand terms + product assignment (needs products)
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

## 9. Admin Interface (AJAX Actions)

All actions are registered under `wp_ajax_octowoo_*` (admin-only, nonce-verified, capability `manage_woocommerce`):

| Action | What it does |
|---|---|
| `octowoo_start_migration` | Starts a full synchronous migration |
| `octowoo_run_chunk` | Runs a single batch (chunked AJAX mode) |
| `octowoo_start_background` | Dispatches run to Action Scheduler (background) |
| `octowoo_cancel_background` | Cancels queued AS jobs |
| `octowoo_abort_migration` | Sets abort transient + marks checkpoints aborted |
| `octowoo_pause_migration` | Pauses the active run |
| `octowoo_resume_migration` | Resumes a paused run |
| `octowoo_skip_migrator` | Skips the currently running migrator |
| `octowoo_get_progress` | Returns snapshot of all checkpoint rows for polling |
| `octowoo_get_report` | Returns the last migration report (processed/skipped/failed) |
| `octowoo_get_logs` | Returns recent log entries (filterable by level/migrator) |
| `octowoo_reset_migration` | Truncates checkpoints/id_map/logs tables |
| `octowoo_test_connection` | Tests OC DB credentials |
| `octowoo_import_sql` | Handles SQL/GZ file upload and import |
| `octowoo_drop_sql` | Drops imported `octowoo_oc_*` tables |
| `octowoo_import_images` | Handles ZIP archive upload and extracts images |
| `octowoo_purge_imported` | Runs DataPurger for selected entities |
| `octowoo_scan_counts` | Returns row counts from OC database |
| `octowoo_prescan` | Runs PreMigrationScanner (counts + issues) |
| `octowoo_validate` | Runs Validator (config pre-flight) |

---

## 10. WP-CLI Commands

```bash
wp octowoo migrate                              # Full migration
wp octowoo migrate --dry-run                    # Simulate (no DB writes)
wp octowoo migrate --resume                     # Resume from last checkpoint
wp octowoo migrate --migrators=products,orders  # Run specific migrators only

wp octowoo status                               # Show checkpoint progress
wp octowoo logs --level=error --limit=50        # View logs
wp octowoo reset --yes                          # Clear all checkpoints/logs
wp octowoo test_connection                      # Test OC DB connection
```

---

## 11. Customer Migration – Field Reference

| OC Field | Migrated? | Where |
|---|---|---|
| `firstname`, `lastname` | Yes | WP user + billing/shipping meta |
| `email` | Yes | WP user login/email + billing_email |
| `telephone` | Yes | `billing_phone` |
| `date_added` | Yes | `user_registered` |
| `newsletter` | Yes | `woocommerce_marketing_optin_status` (yes/no) + `_octowoo_newsletter_optin` |
| `password`, `salt` | Opt-in only | `_octowoo_oc_password_hash/salt` (deleted after first login upgrade) |
| `status` | Filter only | Only `status=1` customers are imported |
| `address_id` → `oc_address` | Yes | Full billing + shipping address |
| `token`, `code`, `cart`, `wishlist`, `ip`, `safe`, `custom_field`, `fax`, `customer_group`, `store_id` | Never fetched | Sensitive/irrelevant — not imported |

---

## 12. Password Migration

When `woocommerce.migrate_oc_passwords = true`:
1. OC hash and salt are stored in `_octowoo_oc_password_hash` / `_octowoo_oc_password_salt` user meta during `CustomerMigrator`. A WARNING log is written every run as a reminder.
2. Filter `octowoo_oc_password_compat` (priority 30) is registered on `wp_authenticate_user`.
3. On the customer's first login, the filter verifies: `sha1( $salt . sha1( $salt . sha1( $plaintext ) ) )`.
4. On success: calls `wp_set_password()`, refreshes `$user->user_pass` from DB (so WP's own `wp_check_password` succeeds in the same request), deletes OC hash/salt meta and `default_password_nag`.
5. All subsequent logins use native WP phpass — the OC hash is gone.

**Default: disabled** (`migrate_oc_passwords = false`). Customers receive a random WP password and are prompted to reset on first login.

---

## 13. Multilingual Support (WPML / Polylang)

When `multilingual.enabled = true`, after all entity migrators complete, `WpmlIntegration` runs a translation pass:

- Reads secondary-language meta stored by migrators (`_octowoo_name_ar`, `_octowoo_description_ar`, `_octowoo_short_description_ar`, `_octowoo_metatitle_ar`, `_octowoo_metadesc_ar`, `_octowoo_metakw_ar`).
- Creates translated post/term copies via `wp_insert_post()` / `wp_insert_term()`.
- Links to primary via WPML (`wpml_set_element_language_details`) or Polylang (`pll_set_post_language`, `pll_link_post_translations`).
- **English fallback**: when Arabic title/content/excerpt is absent, English values are used — every product gets an Arabic WPML post.
- **Slug fix**: `fixTranslationSlug()` forces `post_name` to match the English slug, preventing `-2` suffix.
- **Full WC meta copy**: `copyProductDataToTranslation()` copies SKU, price, stock, gallery, product_type, product_tag, brand terms to the Arabic post.
- **Yoast SEO**: writes `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw` to translated post/term; falls back to English Yoast values when Arabic meta is absent.
- **Arabic redirects**: old OC Arabic product and category URLs → new WC Arabic URLs.
- **Update-existing path**: re-runs update title/content/WC meta/Yoast SEO on already-linked translations.

---

## 14. SEO & Redirects

`SeoMigrator` reads `oc_seo_url` and:
1. Updates the WC entity's slug to match the OC SEO keyword.
2. Builds a redirect map: old OC URL → new WC URL (for products, categories, pages).
3. Arabic redirects: `WpmlIntegration` builds `/ar/old-slug` → `/ar/product/new-slug/` and `/ar/product-category/new-slug/` maps.
4. Persists redirects via:
   - **Apache `.htaccess`**: managed block of `RedirectPermanent` lines.
   - **WordPress option** (`octowoo_redirects`): served by an early `template_redirect` hook firing 301 responses.

---

## 15. What Has Been Completed

| Area | Status |
|---|---|
| Plugin bootstrap & autoloader | Done |
| Activation (DB tables, log dir, options) | Done |
| Deactivation (cron cleanup) | Done |
| Uninstall (full data removal) | Done |
| Admin menu & dashboard UI | Done |
| Admin settings form (DB credentials, paths, toggles) | Done |
| All AJAX action handlers (20+) | Done |
| Admin CSS & JS (tabs, progress, polling) | Done |
| Logger (file + DB, rotation, buffered) | Done |
| DatabaseConnector (PDO, all query helpers, socket support) | Done |
| VersionDetector (OC 1–4, structural detection) | Done |
| CheckpointManager (resume, id_map, two-pass getWcId, static cache) | Done |
| BatchProcessor (pagination, resume, demo limit, dry-run) | Done |
| MigrationManager (orchestrator, full + chunked + background modes) | Done |
| CronManager (schedules, delta sync) | Done |
| DataPurger (force + tagged rollback, bulk SQL, diagnostics, AUTO_INCREMENT reset) | Done |
| SqlImporter (SQL/GZ parser, prefix rewrite, generator) | Done |
| Encryptor (AES-256-CBC credential encryption) | Done |
| MigrationReport (per-migrator stats, persisted) | Done |
| PreMigrationScanner (counts, missing images, oversized variations) | Done |
| Validator (pre-flight config checks) | Done |
| BackgroundProcessor (Action Scheduler integration) | Done |
| AddonManager (11 filters + 7 actions) | Done |
| TaxMigrator | Done |
| OrderStatusMigrator (+ custom WC status registration) | Done |
| CategoryMigrator (hierarchy, SEO, images, Arabic meta, Yoast meta) | Done |
| ManufacturerMigrator (brand taxonomy detection, images, product assignment) | Done |
| ImageMigrator (MD5 deduplication, sideload, batched, OC path caching) | Done |
| ProductMigrator (simple + variable, attributes, variations, specials, Yoast EN + AR meta) | Done |
| RelatedProductsMigrator (upsells) | Done |
| BundleMigrator (OC4, WC Product Bundles plugin) | Done |
| CustomerMigrator (addresses, newsletter consent, OC password hash migration) | Done |
| OrderMigrator (line items, totals, status mapping) | Done |
| CouponMigrator (percent + fixed, limits, expiry, products) | Done |
| SeoMigrator (.htaccess + WP redirects, products/categories/pages) | Done |
| InformationMigrator (static pages, SEO, Arabic meta) | Done |
| TagMigrator (comma-delimited tags, append mode) | Done |
| FilterMigrator (groups → attributes, filters → terms, product assignment) | Done |
| DownloadMigrator (file copy, meta, downloadable flag) | Done |
| ReviewMigrator (WP comments, rating meta, cache flush) | Done |
| WpmlIntegration (WPML + Polylang, posts + terms, full Yoast SEO, EN fallback, Arabic redirects) | Done |
| WP-CLI command class | Done |
| OC password compat filter (correct sha1 formula, phpass upgrade, user_pass refresh) | Done |

---

## 16. Step-by-Step Customer Guide

### Phase 1 – Install the Plugin

1. Download the OctoWoo ZIP from your purchase.
2. In WordPress Admin go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and click **Install Now**, then **Activate Plugin**.
4. Verify the **OctoWoo** menu appears in the left sidebar.

### Phase 2 – Connect to Your OpenCart Database

> You have two options: **Remote** (direct DB connection to your OC server) or **Local** (upload an SQL dump).

**Option A – Remote connection (recommended if OC is on the same server or accessible)**
1. Go to **OctoWoo → Settings** tab.
2. Enter your OpenCart database credentials: Host, Port, Database name, Username, Password, Table prefix (default: `oc_`).
3. If MySQL uses a Unix socket (e.g. Cloudways), enter the socket path and leave Host blank.
4. Click **Test Connection**. Green = ready.

**Option B – SQL dump (OC on a different server)**
1. Export your OpenCart database as a `.sql` or `.sql.gz` file using phpMyAdmin or mysqldump.
2. In **OctoWoo → Settings**, set Source to **Local (SQL Dump)**.
3. Use the **Import SQL** button to upload the dump. OctoWoo rewrites table prefixes automatically.
4. After import, click **Test Connection** to confirm tables are available.

### Phase 3 – Configure Migration Settings

In **OctoWoo → Settings**:

| Setting | Recommended value |
|---|---|
| Image path | Absolute path to your OC `/image/` folder (e.g. `/home/user/public_html/image`) |
| Download path | Absolute path to OC `/system/storage/download/` |
| Old shop URL | Full URL of the OC store (e.g. `https://old-shop.com`) — used for redirect rules |
| Primary language ID | `1` (English) |
| Secondary language ID | `3` (Arabic) — or `0` to skip multilingual |
| Batch size | `20` (safe default); increase to `50` on a powerful server |
| On duplicate | `skip` for first run; `update` for re-runs |
| Force password reset | Keep ON — customers must set a new password on first login |
| Run migrators | Enable/disable individual migrators as needed |

### Phase 4 – Run a Dry Run (optional but recommended)

1. In **Settings**, enable **Dry Run**.
2. Go to the **Migration** tab and click **Start Migration**.
3. Watch the progress table — all steps will say `[DRY-RUN]` in logs.
4. Review the **Logs** tab for any warnings (missing images, no descriptions, etc.).
5. Disable Dry Run when satisfied.

### Phase 5 – Pre-Migration Scan

1. Click **Scan Source** in the Migration tab.
2. Review the counts: products, categories, customers, orders, etc.
3. Check for warnings: missing images, products with too many variations (will be imported as simple), products with no description.
4. Fix any blocking issues in your OC store or adjust settings.

### Phase 6 – Run the Migration

1. Click **Start Migration** in the Migration tab.
2. The progress table shows each migrator moving from `pending` → `running` → `completed`.
3. Keep the browser tab open (or use WP-CLI / Background mode for large stores).
4. If the run is interrupted, click **Resume** — it continues exactly from the last saved ID.
5. When finished, a completion banner shows total processed/skipped/failed.

**For large stores (5,000+ products / 10,000+ orders), use WP-CLI:**
```bash
wp octowoo migrate --resume
```

### Phase 7 – Upload Images (if OC images are not accessible by path)

1. Create a ZIP of your OC `/image/` directory.
2. In the Migration tab, use **Upload Images ZIP** to import them.
3. Re-run the **Images** migrator only (enable `run_images`, disable all others, click Start).

### Phase 8 – Multilingual (WPML / Polylang)

*Skip this phase if you only have one language.*

1. Ensure WPML or Polylang is installed and configured with both English and Arabic languages.
2. In **Settings → Multilingual**, enable multilingual, set primary locale (`en`), secondary locale (`ar`).
3. Set secondary language ID to the OC Arabic language ID (usually `3`).
4. Run the migration (or re-run with `on_duplicate=update`).
5. The WPML pass runs automatically as the last step — it creates Arabic product/category posts and links them.

### Phase 9 – Verify SEO Redirects

1. After migration, visit one of your old OC URLs (e.g. `https://new-site.com/some-product-slug`).
2. It should 301-redirect to the new WC URL (e.g. `/product/some-product-slug/`).
3. If redirects are not working:
   - Check that `.htaccess` is writable if using the Apache option.
   - Or enable **WP Redirects** option in Settings (uses WordPress `template_redirect`).

### Phase 10 – Review & Clean Up

1. Spot-check products, categories, orders, and customers in WooCommerce.
2. Review the **Logs** tab for any ERROR entries and address them.
3. Test customer login with a known OC password (if `migrate_oc_passwords` is enabled).
4. Test checkout to confirm tax, shipping, and order flow.
5. When satisfied, go to **Settings** and disable **migrate_oc_passwords** (if enabled).
6. Optionally run **Purge** on specific entity types if a clean re-migration is needed.

### Phase 11 – Ongoing Delta Sync (optional)

To keep WooCommerce in sync with OpenCart while both stores are live:
1. In Settings → Cron, enable **Cron Auto-Import**.
2. Set interval (e.g. every 6 hours).
3. Set `on_duplicate=update`.
4. OctoWoo will run automatically on schedule, updating existing records and adding new ones.
5. Note: items deleted in OC are not deleted from WC — only additions and updates are synced.

---

## 17. Core Services – What They Do

### MigrationManager
Top-level orchestrator. Receives a merged config, generates a `run_id` (UUID), bootstraps all shared services, and dispatches to each migrator. Supports: full synchronous run, chunked AJAX mode, and Action Scheduler background mode.

### BatchProcessor
Handles paginated iteration over any data source. Given `total_callback`, `batch_callback`, `item_callback`, it loops in pages of `batch_size`, saves checkpoint after each page, stops on abort signal. Supports resume via `resume_after_id` and `demo_limit`.

### CheckpointManager
Writes to `octowoo_checkpoints`. Records `pending → running → completed/failed`. Persists `last_oc_id` for resume. Writes `octowoo_id_map`. Provides `getWcId()` with two-pass lookup (id_map first, then postmeta fallback) with in-memory static cache.

### DatabaseConnector
Thin PDO wrapper for the OpenCart database. In `source=local` mode re-points to the WP database with `octowoo_oc_` prefixed tables. Provides `fetchBatch()` for paginated reads, `scanSourceCounts()` for pre-scan.

### VersionDetector
Detects OC major version (1–4) from `oc_setting.config_version` or structural markers. Provides adapter methods (`seoUrlJoin()`, `quantityColumn()`) so migrators write one SQL query that works across all OC versions.

### Logger
PSR-inspired five-level logger. Writes to a date-stamped file (10 MB rotation) and buffers DB inserts (flushed every 25 entries). File and DB logging independently configurable.

### DataPurger
Selective rollback using bulk SQL DELETE statements. Force mode: removes all WC entities of a type. Tagged mode: removes only `_octowoo_oc_id`-tagged items. Auto-repairs missing meta via `repairMetaFromIdMap()`. Resets MySQL `AUTO_INCREMENT` after full purge. Returns `{results, diagnostics}` with hints for the UI.

### SqlImporter
Parses `.sql` / `.gz` dumps line by line. Rewrites `oc_*` → `octowoo_oc_*`. Strips incompatible clauses (`SET`, `USE`, `LOCK TABLE`, etc.). Imports into the WP database. Handles `dropImportedTables()` for cleanup.

### Encryptor
AES-256-CBC encryption for the OC DB password stored in `wp_options`. Key priority: `OCTOWOO_CRYPT_KEY` constant → `AUTH_KEY` → fallback. Prefixed ciphertext (`octowoo_enc1::`) allows transparent migration from plain-text configs.

### AddonManager
Provides 11 filters and 7 actions for third-party add-on plugins to modify migrated data or skip specific records.

---

## 18. Known Issues & Limitations

### 18.1 Variable Products / Variations
- Only `select` and `radio` OC option types are converted to variations. File-upload, date, image-linked options are not migrated.
- Products with >50 variation combinations are imported as simple products with informational attributes.

### 18.2 Image Migration
- Images must be physically accessible via `image_path`. Remote HTTP fetch is not supported (use ZIP upload for cross-server migrations).

### 18.3 Batch Size & Timeouts
- Default `batch_size=20`. Increase to 50–100 on powerful hosting. Keep the browser tab open in chunked AJAX mode, or use WP-CLI / Background mode.

### 18.4 Order Taxes
- Order-level tax totals are migrated as a fee line item. Per-line-item tax data is not recreated.

### 18.5 Bundle Migration
- Requires the paid **WooCommerce Product Bundles** plugin. Only OC 4.x bundles supported.

### 18.6 Multilingual
- Only products, categories, and pages are translated. Orders, customers, and coupons are not.
- No support for 3+ languages in a single run — primary + one secondary only.

### 18.7 SEO Redirects
- `.htaccess` writes require Apache and a writable file. Use WP-redirect option for Nginx.

### 18.8 SQL Dump Import
- Stored procedures, views, and triggers in the dump are not imported.
- Very large dumps (>500 MB) may require PHP upload/execution time increases.

### 18.9 OC Password Compatibility
- Only covers OC's standard `sha1(salt . sha1(salt . sha1(plaintext)))` scheme. Non-standard hashing is not covered.
- When disabled (default), customers use a random migration password and must reset it.

### 18.10 Reviews
- All imported reviews get `verified = 0`. If WC "only allow verified reviews" is on, they won't display.

### 18.11 Cron Delta Sync
- Deletions in OC after initial migration are NOT synced to WC. Only additions and updates are handled.

### 18.12 No Transaction Rollback
- Each item is written individually. A mid-migration failure leaves partial data. Use DataPurger to clean up before a retry.

---

## 19. Security Measures

- All AJAX handlers verify nonce (`octowoo_ajax`) and capability (`manage_woocommerce`).
- File uploads validate extension and MIME type; ZIP extraction rejects path-traversal filenames.
- SQL importer strips `SET`, `USE`, `LOCK TABLE` statements.
- Log directory is protected by `.htaccess` (deny all) and `index.html`.
- OC DB password is never logged; stored encrypted (AES-256-CBC) in `wp_options`.
- OC password hashes stored only in user meta, never logged or displayed; deleted after first-login upgrade.
- `DataPurger` tagged mode only touches `_octowoo_oc_*` meta items — cannot delete unrelated WC content.
- `uninstall.php` verifies `WP_UNINSTALL_PLUGIN` constant before removing any data.
- AJAX request logger masks `db_pass`, `password`, and `db_password` fields.

---

## 20. Extension Points for Add-on Developers

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
```

**Available filters:**
`octowoo_product_data`, `octowoo_category_data`, `octowoo_customer_data`, `octowoo_order_data`, `octowoo_coupon_data`, `octowoo_information_data`, `octowoo_manufacturer_data`, `octowoo_should_skip_product`, `octowoo_should_skip_customer`, `octowoo_should_skip_order`, `octowoo_brand_taxonomy`

**Available actions:**
`octowoo_migration_started`, `octowoo_migration_finished`, `octowoo_after_migrate_product`, `octowoo_after_migrate_category`, `octowoo_after_migrate_manufacturer`, `octowoo_after_migrate_order`, `octowoo_register_addons`

---

## 21. Changelog Summary (v2.4.x)

### v2.4.41 – Fix OpenCart password hash formula and login upgrade bug
- **Bug fix:** Hash formula was `sha1(md5(salt . plaintext))` — wrong. Correct OC 2.x/3.x formula is `sha1(salt . sha1(salt . sha1(plaintext)))`. Every customer was getting "incorrect password" even when entering the right one.
- **Bug fix:** After `wp_set_password()`, the in-memory `$user->user_pass` was not refreshed, so WP's own `wp_check_password()` (called immediately after the filter) failed. Fixed with `clean_user_cache()` + `get_userdata()` to reload the fresh phpass hash.
- Added `delete_user_meta` for `default_password_nag` on successful upgrade.

### v2.4.40 – Customer security audit and newsletter consent
- **Security:** Audited all `oc_customer` fields. Sensitive fields (`token`, `code`, `cart`, `wishlist`, `ip`, `safe`, `custom_field`, `fax`, `customer_group`, `store_id`) confirmed as never fetched.
- **Bug fix:** `newsletter` field was fetched but silently discarded. Now stored as `woocommerce_marketing_optin_status` (yes/no) and `_octowoo_newsletter_optin`.
- **Improvement:** `migrate_oc_passwords` now writes a WARNING log every run as a reminder to disable after first-login upgrade.

### v2.4.39 – Full Yoast SEO meta for products and categories (EN + AR)
- **Bug fix:** Products had no `_yoast_wpseo_focuskw` (OC `meta_keyword` was read but never written to WP). Fixed in `doCreateProduct()` and `updateProduct()`.
- **New:** `_octowoo_metakw_ar` stored for Arabic `meta_keyword` in ProductMigrator and CategoryMigrator.
- **New:** `createTranslatedPost()` and `createTranslatedTerm()` now write all three Yoast keys (`title`, `metadesc`, `focuskw`) with English fallback when Arabic is absent.
- **New:** Update-existing paths in `translatePosts()` and `translateTerms()` now call `applyYoastPostMeta()` / `applyYoastTermMeta()` helpers.

### v2.4.38 – Arabic category SEO redirects + English category fallback
- Arabic category SEO redirects (`/ar/old-slug` → `/ar/product-category/new-slug/`).
- English name fallback for categories with no Arabic name meta.

### v2.4.37 – Arabic product SEO redirects
- Arabic product SEO redirects (`/ar/old-slug` → `/ar/product/new-slug/`).

### v2.4.36 – English fallback when Arabic data is missing
- Products/pages with no Arabic title/content use English values instead of being skipped.

### v2.4.35 – Arabic URL slug fix (no more `-2` suffix)
- `fixTranslationSlug()` forces Arabic `post_name` to match the English slug after WPML linking.

### v2.4.34 – Arabic product meta: SKU, short description, tags, brands
- `copyProductDataToTranslation()` copies all WC meta + product_type + product_tag + brand terms.
- `$short_ar` from OC `tag` field threaded through create/update chain.

### v2.4.33 – Bulk SQL purge (massive speed improvement)
- All purge methods replaced with single-statement bulk SQL DELETE.
- `clearIdMapEntity()` and `resetAutoIncrements()` helpers added.
- Purging 7,400 customers + 53,000 orders now takes seconds.

*(Earlier v2.4.x entries available in git history and readme.txt)*