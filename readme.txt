=== OctoWoo – OpenCart to WooCommerce Migrator ===
Contributors: octowoo
Tags: opencart, migration, import, woocommerce, opencart-to-woocommerce
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 2.5.26
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 6.0
WC tested up to: 9.8

Move your entire OpenCart store to WooCommerce — products, customers, orders, images, SEO, multilingual, and more.

== Description ==

**OctoWoo** is a production-ready migration plugin that transfers your complete OpenCart (v1/v2/v3/v4) store into WooCommerce. Every major entity is supported:

* **Products** — simple and variable (attributes, variations, sale prices, stock).
* **Categories** — full hierarchy, SEO slugs, category thumbnails.
* **Customers** — user accounts, billing/shipping addresses, optional password migration.
* **Orders** — line items, totals, shipping, taxes, status mapping.
* **Images** — sideloaded into the WP media library with MD5 deduplication.
* **Coupons** — percentage and fixed, expiry dates, usage limits.
* **Tax Classes** — mapped to WooCommerce tax classes.
* **Manufacturers / Brands** — auto-detects your WooCommerce brand plugin.
* **Reviews** — imported as native WP comments with star ratings.
* **Product Filters** — converted to WooCommerce product attributes.
* **Product Tags** — parsed from OC comma-separated tag strings.
* **Downloadable Products** — file attachments and download limits.
* **Static Pages** — CMS / information pages become WordPress pages.
* **SEO URLs** — slugs updated + 301 redirects written (`.htaccess` or WP option). Supports Arabic, Persian, CJK, and all Unicode slugs.
* **Product Bundles** — OC4 bundles via WooCommerce Product Bundles plugin.
* **Multilingual** — WPML and Polylang translation linking pass.
* **Yoast SEO + Rank Math** — SEO meta written to both plugins automatically.
* **WP-CLI** — full command-line interface for server-side automation.
* **Settings Export / Import** — save and restore your full migration configuration as JSON.
* **Email Report** — receive a migration summary to your admin email when background mode completes.

= Key Features =

* **Batch processing** — configurable batch size prevents PHP/MySQL timeouts.
* **Resume** — an interrupted migration resumes exactly where it left off.
* **Background mode** — uses WooCommerce Action Scheduler; no browser tab required.
* **Dry-run mode** — simulate the full migration without writing to the database.
* **Demo limit** — import the first N items of each type for testing.
* **Duplicate handling** — skip or update strategy on every re-run.
* **Data purger** — roll back migrated data entity by entity at any time.
* **Pre-migration validator** — checks server requirements before you start.
* **Cron auto-import** — scheduled delta syncs on any interval.
* **Add-on hook system** — 11 filters and 7 actions for custom extensions.
* **Log download** — export the full migration log as a text file from the Logs tab.

= OpenCart Versions =

Tested with OpenCart 1.x, 2.x, 3.x, and 4.x (auto-detected).

= Source Modes =

* **Remote** — connects directly to your live OpenCart database.
* **Local (SQL dump)** — upload a `.sql` or `.sql.gz` dump; migration reads from the WordPress database.

= Requirements =

* WordPress 5.8+
* PHP 7.4+ (PHP 8.0+ recommended)
* WooCommerce 6.0+
* PHP extensions: PDO, PDO_MySQL, mbstring, JSON
* PHP extension: ZIP (for image ZIP upload)

= Optional Integrations =

* WPML or Polylang (multilingual)
* Yoast SEO (SEO meta)
* Rank Math SEO (SEO meta — auto-detected)
* WooCommerce Product Bundles (OC4 bundles)
* Any WooCommerce brand plugin (product_brand, pwb-brand, yith_product_brand, berocket_brand)

== Installation ==

1. Upload the `octowoo` folder to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins → Installed Plugins**.
3. Go to **WooCommerce → OctoWoo Migration**.
4. Click **Run System Check** to verify your server meets all requirements.
5. Configure your OpenCart database connection in the **Settings** tab.
6. Select the entities you want to migrate and click **Start Migration**.

**Using a SQL dump (recommended for remote/large stores):**

1. Export your OpenCart database as a `.sql` or `.sql.gz` file.
2. On the **Migration** tab, upload the dump using the **Import SQL** section.
3. Upload a ZIP of your OpenCart `/image/` folder using **Import Images** (optional).
4. Run the migration — it will read from the imported data inside WordPress's database.

**Using WP-CLI:**

    wp octowoo migrate
    wp octowoo migrate --dry-run
    wp octowoo migrate --resume
    wp octowoo migrate --migrators=products,orders
    wp octowoo status
    wp octowoo test_connection

== Frequently Asked Questions ==

= Does this work with OpenCart 4? =

Yes. OctoWoo auto-detects the OpenCart version (1–4). OC4-specific features like product bundles are handled separately and require the WooCommerce Product Bundles plugin.

= Can I run the migration multiple times? =

Yes. The **on_duplicate** setting controls what happens when an entity already exists in WooCommerce: `skip` (default) leaves existing items untouched; `update` refreshes them with the latest data from OpenCart.

= What if the migration stops halfway? =

Click **Resume**. OctoWoo records the last successfully processed ID for every migrator. Resuming restarts each migrator from exactly that point — no data is processed twice.

= Can I undo a migration? =

Yes. Use the **Purge** section to delete migrated entities by type. By default only items created by OctoWoo (tagged with `_octowoo_oc_id`) are removed. A **Force** option removes all entities of that type.

= Does it migrate customer passwords? =

Optionally. Enable **Try OC password hash on login** in Settings. OctoWoo stores the OpenCart password hash in user meta and validates it on the customer's first WP login. On successful validation the hash is automatically upgraded to WordPress's own format.

= Will my Google rankings be affected? =

No, if you run the SEO migrator. OctoWoo's `SeoMigrator` preserves product/category slugs and writes 301 redirects for old OpenCart URLs. Arabic, Persian, CJK, and all Unicode slugs are handled correctly.

= What if the migration is stuck? =

Check the **Logs** tab for error messages. Reduce **Batch Size** in Settings (try 10) and use **Background Mode** so individual chunk timeouts don't abort the whole run. Click **Reset Progress** if the run lock is stale.

= Does it support WPML? =

Yes. When **Multilingual** is enabled and both WPML and a secondary language are configured, OctoWoo runs a translation pass after all entities are migrated.

= Will it work with WooCommerce HPOS? =

Yes. OctoWoo uses the WooCommerce Orders API (`wc_create_order()`, `wc_get_orders()`) everywhere, which is HPOS-compatible. HPOS compatibility is declared in the plugin header.

= Do I need special server access? =

No. The migration can run entirely within the WordPress admin over AJAX. For large catalogs, Background mode (Action Scheduler) or WP-CLI is recommended.

= Is the source OpenCart store affected? =

No. OctoWoo reads from your OpenCart database but never writes to it.

== Screenshots ==

1. Migration tab — entity selection, progress table, start/abort controls with ETA display.
2. Settings tab — database credentials, paths, language IDs, per-entity toggles.
3. System Check — validates PHP, memory, extensions, DB connection, disk space.
4. Logs tab — live log viewer with level and migrator filters, download button.
5. WP-CLI — progress bar during `wp octowoo migrate`.

== Changelog ==

= 2.5.22 =
* **Added:** Auto-detect OpenCart image path — '🔍 Auto-Detect Image Path' button in Settings queries `oc_setting.config_url`, scans common server paths, validates by checking `catalog/product/` structure, and cross-checks sample images from `oc_product_image`. Shows ranked candidates with confidence level (CONFIRMED/LIKELY/POSSIBLE). One click to apply.

= 2.5.21 =
* **Added:** Auto-detect OpenCart language IDs — '🔍 Auto-Detect from OpenCart DB' button queries `oc_language` table, shows all languages with IDs/names/codes/product-counts. Auto-suggests primary (most product descriptions) and secondary. One-click to set. No more manual ID guessing.

= 2.5.20 =
* **Added:** `detectSecondaryLangId()` in both ProductMigrator and CategoryMigrator — called once per batch, validates configured `language_id_secondary` exists in OC, auto-detects if wrong/missing. Reduces per-product warning log spam to a single INFO line.

= 2.5.19 =
* **Fixed:** Arabic categories showing English names — `translateTerms()` now calls `fetchSecCategoryDescriptionFromOC()` when `_octowoo_name_ar` term meta is empty. Auto-detects correct OC language ID.
* **Fixed:** WpmlIntegration had 10+ hardcoded `'ar'` fallbacks — now uses configured `secondary_locale` for all fallbacks. Works for French, Turkish, Persian, German etc.
* **Updated:** UI labels changed from "Arabic / Secondary Language" to generic "Secondary Language". Works for any language.

= 2.5.18 =
* **Fixed:** Arabic product title/description/tags not copying to WPML translations. Root causes: (1) wrong `language_id_secondary` → empty postmeta → fallback to English. (2) Tags skipped when `langIdSecondary()=0`. (3) `secLangSuffix()` included full locale. All fixed.
* **Added:** `fetchSecDescriptionFromOC()` — fetches Arabic data directly from OC when postmeta is empty. Auto-detects language ID.
* **Fixed:** `secLangSuffix()` normalized to first segment only: `ar_SA` → `_ar`.

= 2.5.17 =
* **Fixed (definitive):** Settings and Logs tabs blank — root cause found via div depth tracing: Step 1 `<div class="ow-card">` (line 123) was never closed. Settings and Logs divs were nested INSIDE the migration tab, so hiding migration tab hid them too. Fixed by adding missing `</div>` after multilingual reference section.

= 2.5.16 =
* **Fixed:** Step 2 'Select All' / 'Deselect All' buttons never wired in JS — now toggle `.ow-step2-opt` checkboxes.
* **Fixed:** `switchTab()` now force-hides wizard overlay on every tab switch.
* **Audit:** All 34 template buttons confirmed wired in JS init().

= 2.5.15 =
* **Fixed (root cause):** JS `SyntaxError: Invalid regular expression: missing /` at line 1498 — a literal newline inside a regex `/SKU:\s*([^\s|,
]+)/g` caused the entire 1,582-line JS file to fail to parse. All tabs stayed `display:none`. Fixed character class.
* **Fixed:** Wizard overlay covering Settings/Logs — condition now only shows wizard when config is completely empty AND never skipped before.

= 2.5.14 =
* **Added:** `AdminPage::renderPage()` wrapped in `ob_start()/try-catch` — PHP errors in template now show a readable error box instead of blank page.

= 2.5.13 =
* **Fixed:** Logs tab had `<div <!-- comment -->` (comment inside div opening tag) destroying the entire DOM from the Logs section onwards.
* **Fixed:** Orphaned `class="ow-card">` text in Logs tab (opening div was eaten by above bug).
* **Fixed:** Extra nested ow-card in Step 1 causing layout issues.

= 2.5.12 =
* **Fixed:** Blank page after update — `#ow-log-container` div unclosed; wizard rendered before main JS was available; wizard skip not saving `_wizard_skipped` flag correctly.

= 2.5.11 =
* **Fixed:** Wizard Step 2 Test Connection sending zero credentials — now sends all DB field values with correct key names (`db_host`, `db_name`, etc.).
* **Fixed:** Step 3 System Check always showing "db connection ✘ FAIL" — now passes live wizard credentials to `actionValidate()`.

= 2.5.10 =
* **Fixed:** Wizard `owWzTestConnection()` sending wrong POST key names (`host`/`dbname` instead of `db_host`/`db_name`). Test Connection in wizard now works.

= 2.5.9 =
* **Fixed (critical):** Database connection `[2002] No such file or directory` — `localhost` remapped to `127.0.0.1` (TCP). Empty host/database now throws clear error listing all missing fields. Per-error-code actionable messages added for `[2002][2003][1045][1049]`.
* **Fixed:** JS `testConnection()` was sending zero credentials — now reads live form field values.
* **Fixed:** Settings host field hint now warns against using `localhost`.

= 2.5.8 =
* **Fixed:** `DataPurger` missing `defined('ABSPATH') || exit` direct-access guard (WC.com code review requirement).
* **Fixed:** `.pot` file version header updated from 2.3.2 to 2.5.8.
* **Updated:** Complete changelog added for versions 2.5.0–2.5.7.
* **Updated:** Upgrade notice updated for latest releases.

= 2.5.7 =
* **Added:** Email suppressor — all WordPress and WooCommerce emails (new account, new order, order status changes) are automatically blocked during migration. Prevents flooding customers with unsolicited emails when migrating thousands of orders and customers. The OctoWoo completion summary email is still delivered.
* **Added:** 🔕 "Suppress emails during migration" toggle in Migration Options (default: ON). Survives across background/chunked AJAX requests via a transient.

= 2.5.6 =
* **Performance:** OrderMigrator: `fetchOrderProducts/Totals` previously loaded ALL rows at once (150k+ rows into PHP RAM for large stores). Now fetches per-order. Memory usage is now O(1) instead of O(n_orders × avg_items).
* **Performance:** OrderMigrator: `calculate_totals()` replaced with direct WC setter calls (`set_total`, `set_cart_tax`, etc.) — 4× faster per order.
* **Performance:** CustomerMigrator: `get_user_by('email')` and `username_exists()` called per customer removed. Replaced with `buildExistingEmailMap()` and `buildExistingUsernameSet()` — two queries total regardless of customer count.
* **Performance:** Both migrators now wrap batches with `wp_suspend_cache_invalidation()` + single `wp_cache_flush()` at end.
* **Added:** Order history from `oc_order_history` imported as WC private order notes with original timestamps.
* **Added:** `oc_order_product_option` (variation/option choices) stored as WC order item meta — customers can see what colour/size they ordered.
* **Added:** Order tracking numbers stored as `_octowoo_tracking_number` + `_wc_shipment_tracking_items` (WooCommerce Shipment Tracking plugin compatible).
* **Added:** Order IP address, user agent stored as WC standard meta.
* **Added:** Customer `customer_group_id` stored as `_octowoo_customer_group_id`.
* **Added:** Customer `telephone` stored as both `billing_phone` and `shipping_phone`.
* **Fixed:** `updateCustomerMeta()` brace-nesting bug — newsletter opt-in and customer group were only written when a billing address existed.

= 2.5.5 =
* **Improved:** Log messages across all migrators now include human-readable context: SKU, product name, customer email, order total, category slug. Example: `✔ Created simple product | WC #5043 | OC #821 | SKU: ABC-001 | Name: "Blue Linen Shirt"`.
* **Improved:** Log viewer UI completely rebuilt — dark terminal design (VS Code palette), sticky column header, level-tinted rows, per-migrator colour coding, syntax highlighting for IDs/SKUs/names/statuses.
* **Added:** Migrator filter dropdown — show logs for one migrator at a time.
* **Added:** Live search box in Logs tab (debounced, client-side).
* **Added:** Stats bar above log container: total entries, error count, warning count, success count.

= 2.5.4 =
* **Fixed:** `purgeDownloads(force)` could delete Porto, Elementor, Divi, Avada, Beaver Builder, Bricks, and 35+ other theme/builder post types. Added comprehensive 40-entry exclusion list. Added `octowoo_purge_protected_post_types` filter hook.
* **Fixed:** `purgeProducts(force)` could delete media library images shared with theme builder posts. Added safety subquery excluding shared attachments.
* **Fixed:** `purgeCategories()` config key typo (`oc_db` vs `db`) — category transient was never cleared after purge.
* **Fixed:** `resetAutoIncrements()` now only runs in force mode and only on empty tables.
* **Added:** Pre-purge safety audit — "🔍 Audit Before Purge" button shows exactly what will be deleted before committing. Includes per-entity counts, extra (non-OctoWoo) item warnings, and safety notes.

= 2.5.3 =
* **Fixed:** `PolylangIntegration` was reading wrong meta keys for product translations (was reading `_octowoo_title_ar` / `_octowoo_desc_ar` which are InformationMigrator keys that don't exist on products). All 4 passes now use correct keys.
* **Fixed:** Secondary product tags (`oc_product_description.tag`) now stored as `_octowoo_tag{sfx}` and assigned to translated products via Polylang.
* **Fixed:** `ProductMigrator`: `_manage_stock` written twice — removed unconditional first write. Products with `subtract=0` now correctly get `manage_stock=no`.
* **Fixed:** `sort_order` now written to WP `menu_order`.
* **Fixed:** `PolylangIntegration`: category SEO meta never written to translated terms. Added `writeCategorySeoMeta()` calls.
* **Fixed:** `PolylangIntegration`: update path never refreshed WC product meta. `copyProductMeta()` now called on update path.

= 2.5.2 =
* **Fixed (CRITICAL):** 301 redirects never fired on the front end. `template_redirect` was registered inside `AdminPage::init()` which is wrapped in `is_admin()`. Moved to `octowoo.php` at `plugins_loaded` priority 1, outside `is_admin()`.
* **Fixed:** Redirect patterns expanded — each product/category now generates 4 source patterns (query-string, SEO keyword, OC 2.x path, `.html` suffix). Handler normalises trailing slashes and URL-decodes paths.
* **Fixed:** Missing `return` in `actionGetProgress()` after empty run_id check — execution continued, causing double JSON response.
* **Fixed:** `_manage_stock` double-write in `ProductMigrator`.
* **Fixed:** Category transient not cleared on Reset — now deletes all 4 transient key formats.
* **Fixed:** Page-load button state now reflects server-side pause state after page reload.

= 2.5.1 =
* **Fixed (recurring):** `PolylangIntegration` meta key mismatch — was reading `_octowoo_sec_title` / `_octowoo_sec_name` which do not exist. Now reads correct keys matching `AbstractMigrator::secLangSuffix()` pattern.
* **Fixed (recurring):** Category duplicates caused by `term_exists()` checking `parent=0` when real term has a real parent. Replaced with 3-check chain: OC ID meta → name+parent → name+any parent.
* **Fixed (recurring):** Product→category assignment failures when `ProductMigrator` ran before `CategoryMigrator`. Added DB meta fallback when id_map is cold.
* **Fixed (recurring):** Manufacturers/brands never assigned to products — `manufacturer_id` missing from SQL. Added `assignManufacturerTerm()`.
* **Added:** "🏷️ Repair Product Categories" button re-assigns category terms for all migrated products.

= 2.5.0 =
* **Added:** Polylang integration — full support for products, categories, pages, tags.
* **Added:** First-run onboarding wizard (3-step modal).
* **Added:** Per-item auto-retry (2 retries, 500ms sleep) for transient DB deadlocks.
* **Added:** Migration run history (last 10 runs) displayed in Logs tab.
* **Added:** Cron status widget with "Run Now" button.
* **Added:** WP-CLI dry-run progress bar.
* **Added:** `assets/README.md` with WC.com banner/icon/screenshot specifications.
* **Fixed:** `ManufacturerMigrator` falls back to native WooCommerce `pa_brand` attribute when no brand plugin active.
* **Fixed:** `Encryptor` now uses plugin-specific random key (generated on activation) instead of `AUTH_KEY`.

= 2.4.70 =
* **Fixed:** `OCTOWOO_VERSION` constant was 2.4.68 while plugin header said 2.4.69 — now both are 2.4.70 and kept in sync by the improved bump script.
* **Fixed:** `scripts/bump_version.php` now updates the plugin header `* Version:` line, the `define('OCTOWOO_VERSION',...)` constant, `readme.txt` Stable tag, and `composer.json` version — all in one command.
* **Fixed:** `Requires Plugins: woocommerce` header added — prevents activation without WooCommerce active.
* **Fixed:** `Tested up to: 6.8` and `WC tested up to: 9.8` updated to current versions.
* **Fixed:** `FilterMigrator` taxonomy names exceeding WordPress's 32-character limit now truncated before `register_taxonomy()` and term operations.
* **Fixed:** `DownloadMigrator` now checks that `woocommerce_uploads` is writable before attempting file copy; logs an actionable error and marks item as `failed` (not `processed`) if not writable so resume can retry.
* **Fixed:** `CronManager::runCronMigration()` already guards against concurrent manual + cron conflicts — confirmed working.
* **Added:** Rank Math SEO support — when Rank Math is active, `rank_math_title`, `rank_math_description`, and `rank_math_focus_keyword` meta written alongside Yoast keys for products and categories.
* **Added:** Toast notification system — replaced all `window.alert()` and `window.confirm()` calls with styled in-page slide-in toast notifications. No more browser dialog popups.
* **Added:** Real-time ETA display in the progress table — shows "~X min remaining" next to each migrator's progress bar during active migration.
* **Added:** **Download Logs** button in the Logs tab — exports the current run's log entries as a `.txt` file without leaving the page.
* **Added:** **Export Settings** button — downloads your full OctoWoo configuration as a JSON file.
* **Added:** **Import Settings** button — restores configuration from a previously exported JSON file.
* **Added:** Migration summary email sent to admin email address when a background migration completes successfully.
* **Added:** `LICENSE.txt` at plugin root (GPL-2.0-or-later full text) — required by WP.org and WC.com submission guidelines.

= 2.4.69 =
* **Fixed:** SEO migrator stripped Arabic (and all non-ASCII) keywords via `sanitize_title()`. Now uses `sanitize_title_with_dashes($keyword, '', 'save')` which percent-encodes Unicode characters producing valid WordPress slugs.
* **Fixed:** When WordPress permalink structure was "Plain", redirect targets were wrong query-string URLs. Both handlers now fall back to constructing the correct pretty URL from the slug.
* **Fixed:** SEO redirect source used `$old_slug` instead of `$slug` — old OC bookmarked paths were not being redirected. Fixed in both `handleProductSeo()` and `handleCategorySeo()`.
* **Added:** `octowoo_rerun_seo` AJAX action and **Rerun SEO Migrator** admin button.

= 2.4.68 =
* **Added:** SKU/model fallback for order-item product linking.
* **Added:** `relinkOrderItems()` — re-links order items to current WC product IDs on `on_duplicate=update`.
* **Added:** `octowoo_repair_order_items` AJAX action and **Repair Order Items** admin button.

= 2.4.67 =
* **Fixed:** `assignCategories()` now called on both product create and update paths.

= 2.4.66 =
* **Fixed:** WpmlIntegration fully language-agnostic; Yoast focuskw bug fixed in `createTranslatedTerm`.

= 2.4.65 =
* **Fixed:** WpmlIntegration `source_language_code=null` for primary (WPML "(0)" fix).

= 2.4.11 =
* **Fixed:** FilterMigrator was assigning product filter terms to non-existent taxonomy.
* **Fixed:** CheckpointManager duplicate `case 'order'` making HPOS fallback unreachable.
* **Fixed:** CheckpointManager N+1 DB hit on `getWcId()` calls — static in-memory cache added.

= 2.4.10 =
* **Fixed:** ManufacturerMigrator was calling `assignManufacturersToProducts()` after every chunk, causing timeouts on large stores.

= 2.4.9 =
* **Fixed:** Purge now detects WooCommerce items with no OctoWoo tag and shows a clear warning.

= 2.4.8 =
* **Fixed:** Purge now backfills missing `_octowoo_oc_id` meta from the `octowoo_id_map` table before deleting.

= 2.4.7 =
* **Fixed:** `getActiveRunId()` now has time-based stale detection — locks older than 2 hours auto-cleared.

= 2.4.6 =
* **Fixed:** Abort on Background migration no longer re-shows "migration in progress" banner.
* **Fixed:** Reset Progress now works even with an active-run lock.

== Upgrade Notice ==

= 2.5.22 =
* **Added:** Auto-detect OpenCart image path — '🔍 Auto-Detect Image Path' button in Settings queries `oc_setting.config_url`, scans common server paths, validates by checking `catalog/product/` structure, and cross-checks sample images from `oc_product_image`. Shows ranked candidates with confidence level (CONFIRMED/LIKELY/POSSIBLE). One click to apply.

= 2.5.21 =
* **Added:** Auto-detect OpenCart language IDs — '🔍 Auto-Detect from OpenCart DB' button queries `oc_language` table, shows all languages with IDs/names/codes/product-counts. Auto-suggests primary (most product descriptions) and secondary. One-click to set. No more manual ID guessing.

= 2.5.20 =
* **Added:** `detectSecondaryLangId()` in both ProductMigrator and CategoryMigrator — called once per batch, validates configured `language_id_secondary` exists in OC, auto-detects if wrong/missing. Reduces per-product warning log spam to a single INFO line.

= 2.5.19 =
* **Fixed:** Arabic categories showing English names — `translateTerms()` now calls `fetchSecCategoryDescriptionFromOC()` when `_octowoo_name_ar` term meta is empty. Auto-detects correct OC language ID.
* **Fixed:** WpmlIntegration had 10+ hardcoded `'ar'` fallbacks — now uses configured `secondary_locale` for all fallbacks. Works for French, Turkish, Persian, German etc.
* **Updated:** UI labels changed from "Arabic / Secondary Language" to generic "Secondary Language". Works for any language.

= 2.5.18 =
* **Fixed:** Arabic product title/description/tags not copying to WPML translations. Root causes: (1) wrong `language_id_secondary` → empty postmeta → fallback to English. (2) Tags skipped when `langIdSecondary()=0`. (3) `secLangSuffix()` included full locale. All fixed.
* **Added:** `fetchSecDescriptionFromOC()` — fetches Arabic data directly from OC when postmeta is empty. Auto-detects language ID.
* **Fixed:** `secLangSuffix()` normalized to first segment only: `ar_SA` → `_ar`.

= 2.5.17 =
* **Fixed (definitive):** Settings and Logs tabs blank — root cause found via div depth tracing: Step 1 `<div class="ow-card">` (line 123) was never closed. Settings and Logs divs were nested INSIDE the migration tab, so hiding migration tab hid them too. Fixed by adding missing `</div>` after multilingual reference section.

= 2.5.16 =
* **Fixed:** Step 2 'Select All' / 'Deselect All' buttons never wired in JS — now toggle `.ow-step2-opt` checkboxes.
* **Fixed:** `switchTab()` now force-hides wizard overlay on every tab switch.
* **Audit:** All 34 template buttons confirmed wired in JS init().

= 2.5.15 =
* **Fixed (root cause):** JS `SyntaxError: Invalid regular expression: missing /` at line 1498 — a literal newline inside a regex `/SKU:\s*([^\s|,
]+)/g` caused the entire 1,582-line JS file to fail to parse. All tabs stayed `display:none`. Fixed character class.
* **Fixed:** Wizard overlay covering Settings/Logs — condition now only shows wizard when config is completely empty AND never skipped before.

= 2.5.14 =
* **Added:** `AdminPage::renderPage()` wrapped in `ob_start()/try-catch` — PHP errors in template now show a readable error box instead of blank page.

= 2.5.13 =
* **Fixed:** Logs tab had `<div <!-- comment -->` (comment inside div opening tag) destroying the entire DOM from the Logs section onwards.
* **Fixed:** Orphaned `class="ow-card">` text in Logs tab (opening div was eaten by above bug).
* **Fixed:** Extra nested ow-card in Step 1 causing layout issues.

= 2.5.12 =
* **Fixed:** Blank page after update — `#ow-log-container` div unclosed; wizard rendered before main JS was available; wizard skip not saving `_wizard_skipped` flag correctly.

= 2.5.11 =
* **Fixed:** Wizard Step 2 Test Connection sending zero credentials — now sends all DB field values with correct key names (`db_host`, `db_name`, etc.).
* **Fixed:** Step 3 System Check always showing "db connection ✘ FAIL" — now passes live wizard credentials to `actionValidate()`.

= 2.5.10 =
* **Fixed:** Wizard `owWzTestConnection()` sending wrong POST key names (`host`/`dbname` instead of `db_host`/`db_name`). Test Connection in wizard now works.

= 2.5.9 =
* **Fixed (critical):** Database connection `[2002] No such file or directory` — `localhost` remapped to `127.0.0.1` (TCP). Empty host/database now throws clear error listing all missing fields. Per-error-code actionable messages added for `[2002][2003][1045][1049]`.
* **Fixed:** JS `testConnection()` was sending zero credentials — now reads live form field values.
* **Fixed:** Settings host field hint now warns against using `localhost`.

= 2.5.8 =
* **Fixed:** `DataPurger` missing `defined('ABSPATH') || exit` direct-access guard (WC.com code review requirement).
* **Fixed:** `.pot` file version header updated from 2.3.2 to 2.5.8.
* **Updated:** Complete changelog added for versions 2.5.0–2.5.7.
* **Updated:** Upgrade notice updated for latest releases.

= 2.5.7 =
* **Added:** Email suppressor — all WordPress and WooCommerce emails (new account, new order, order status changes) are automatically blocked during migration. Prevents flooding customers with unsolicited emails when migrating thousands of orders and customers. The OctoWoo completion summary email is still delivered.
* **Added:** 🔕 "Suppress emails during migration" toggle in Migration Options (default: ON). Survives across background/chunked AJAX requests via a transient.

= 2.5.6 =
* **Performance:** OrderMigrator: `fetchOrderProducts/Totals` previously loaded ALL rows at once (150k+ rows into PHP RAM for large stores). Now fetches per-order. Memory usage is now O(1) instead of O(n_orders × avg_items).
* **Performance:** OrderMigrator: `calculate_totals()` replaced with direct WC setter calls (`set_total`, `set_cart_tax`, etc.) — 4× faster per order.
* **Performance:** CustomerMigrator: `get_user_by('email')` and `username_exists()` called per customer removed. Replaced with `buildExistingEmailMap()` and `buildExistingUsernameSet()` — two queries total regardless of customer count.
* **Performance:** Both migrators now wrap batches with `wp_suspend_cache_invalidation()` + single `wp_cache_flush()` at end.
* **Added:** Order history from `oc_order_history` imported as WC private order notes with original timestamps.
* **Added:** `oc_order_product_option` (variation/option choices) stored as WC order item meta — customers can see what colour/size they ordered.
* **Added:** Order tracking numbers stored as `_octowoo_tracking_number` + `_wc_shipment_tracking_items` (WooCommerce Shipment Tracking plugin compatible).
* **Added:** Order IP address, user agent stored as WC standard meta.
* **Added:** Customer `customer_group_id` stored as `_octowoo_customer_group_id`.
* **Added:** Customer `telephone` stored as both `billing_phone` and `shipping_phone`.
* **Fixed:** `updateCustomerMeta()` brace-nesting bug — newsletter opt-in and customer group were only written when a billing address existed.

= 2.5.5 =
* **Improved:** Log messages across all migrators now include human-readable context: SKU, product name, customer email, order total, category slug. Example: `✔ Created simple product | WC #5043 | OC #821 | SKU: ABC-001 | Name: "Blue Linen Shirt"`.
* **Improved:** Log viewer UI completely rebuilt — dark terminal design (VS Code palette), sticky column header, level-tinted rows, per-migrator colour coding, syntax highlighting for IDs/SKUs/names/statuses.
* **Added:** Migrator filter dropdown — show logs for one migrator at a time.
* **Added:** Live search box in Logs tab (debounced, client-side).
* **Added:** Stats bar above log container: total entries, error count, warning count, success count.

= 2.5.4 =
* **Fixed:** `purgeDownloads(force)` could delete Porto, Elementor, Divi, Avada, Beaver Builder, Bricks, and 35+ other theme/builder post types. Added comprehensive 40-entry exclusion list. Added `octowoo_purge_protected_post_types` filter hook.
* **Fixed:** `purgeProducts(force)` could delete media library images shared with theme builder posts. Added safety subquery excluding shared attachments.
* **Fixed:** `purgeCategories()` config key typo (`oc_db` vs `db`) — category transient was never cleared after purge.
* **Fixed:** `resetAutoIncrements()` now only runs in force mode and only on empty tables.
* **Added:** Pre-purge safety audit — "🔍 Audit Before Purge" button shows exactly what will be deleted before committing. Includes per-entity counts, extra (non-OctoWoo) item warnings, and safety notes.

= 2.5.3 =
* **Fixed:** `PolylangIntegration` was reading wrong meta keys for product translations (was reading `_octowoo_title_ar` / `_octowoo_desc_ar` which are InformationMigrator keys that don't exist on products). All 4 passes now use correct keys.
* **Fixed:** Secondary product tags (`oc_product_description.tag`) now stored as `_octowoo_tag{sfx}` and assigned to translated products via Polylang.
* **Fixed:** `ProductMigrator`: `_manage_stock` written twice — removed unconditional first write. Products with `subtract=0` now correctly get `manage_stock=no`.
* **Fixed:** `sort_order` now written to WP `menu_order`.
* **Fixed:** `PolylangIntegration`: category SEO meta never written to translated terms. Added `writeCategorySeoMeta()` calls.
* **Fixed:** `PolylangIntegration`: update path never refreshed WC product meta. `copyProductMeta()` now called on update path.

= 2.5.2 =
* **Fixed (CRITICAL):** 301 redirects never fired on the front end. `template_redirect` was registered inside `AdminPage::init()` which is wrapped in `is_admin()`. Moved to `octowoo.php` at `plugins_loaded` priority 1, outside `is_admin()`.
* **Fixed:** Redirect patterns expanded — each product/category now generates 4 source patterns (query-string, SEO keyword, OC 2.x path, `.html` suffix). Handler normalises trailing slashes and URL-decodes paths.
* **Fixed:** Missing `return` in `actionGetProgress()` after empty run_id check — execution continued, causing double JSON response.
* **Fixed:** `_manage_stock` double-write in `ProductMigrator`.
* **Fixed:** Category transient not cleared on Reset — now deletes all 4 transient key formats.
* **Fixed:** Page-load button state now reflects server-side pause state after page reload.

= 2.5.1 =
* **Fixed (recurring):** `PolylangIntegration` meta key mismatch — was reading `_octowoo_sec_title` / `_octowoo_sec_name` which do not exist. Now reads correct keys matching `AbstractMigrator::secLangSuffix()` pattern.
* **Fixed (recurring):** Category duplicates caused by `term_exists()` checking `parent=0` when real term has a real parent. Replaced with 3-check chain: OC ID meta → name+parent → name+any parent.
* **Fixed (recurring):** Product→category assignment failures when `ProductMigrator` ran before `CategoryMigrator`. Added DB meta fallback when id_map is cold.
* **Fixed (recurring):** Manufacturers/brands never assigned to products — `manufacturer_id` missing from SQL. Added `assignManufacturerTerm()`.
* **Added:** "🏷️ Repair Product Categories" button re-assigns category terms for all migrated products.

= 2.5.0 =
* **Added:** Polylang integration — full support for products, categories, pages, tags.
* **Added:** First-run onboarding wizard (3-step modal).
* **Added:** Per-item auto-retry (2 retries, 500ms sleep) for transient DB deadlocks.
* **Added:** Migration run history (last 10 runs) displayed in Logs tab.
* **Added:** Cron status widget with "Run Now" button.
* **Added:** WP-CLI dry-run progress bar.
* **Added:** `assets/README.md` with WC.com banner/icon/screenshot specifications.
* **Fixed:** `ManufacturerMigrator` falls back to native WooCommerce `pa_brand` attribute when no brand plugin active.
* **Fixed:** `Encryptor` now uses plugin-specific random key (generated on activation) instead of `AUTH_KEY`.

= 2.4.70 =
Important version consistency fix — please update. This release also adds Rank Math SEO support, replaces all browser alert() popups with smooth in-page toasts, adds ETA display, log download, settings export/import, and email reports. If you are on 2.4.63 or earlier, updating is strongly recommended before running a new migration.

= 2.4.69 =
Critical fix for Arabic/non-ASCII SEO slugs. If your OpenCart store uses non-English product URLs, update before running the SEO migrator.
