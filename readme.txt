=== OctoWoo – OpenCart to WooCommerce Migrator ===
Contributors: octowoo
Tags: opencart, migration, import, woocommerce, opencart-to-woocommerce
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.4.33
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 6.0
WC tested up to: 9.4

Move your entire OpenCart store to WooCommerce — products, customers, orders, images, SEO, and more.

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
* **SEO URLs** — slugs updated + 301 redirects written (`.htaccess` or WP option).
* **Product Bundles** — OC4 bundles via WooCommerce Product Bundles plugin.
* **Multilingual** — WPML and Polylang translation linking pass.
* **WP-CLI** — full command-line interface for server-side automation.

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
* **Add-on hook system** — 11 filters and 6 actions for custom extensions.

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
* Yoast SEO or Rank Math (SEO meta)
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

Yes. Use the **Purge** section to delete migrated entities by type. By default only items created by OctoWoo (tagged with `_octowoo_oc_id`) are removed, leaving your manually-created content untouched. A **Force** option removes all entities of that type.

If Purge reports **0 items deleted** even though WooCommerce data exists, it usually means the OctoWoo tag was not saved (common after using Reset Progress, which clears the id-map). In that case enable **☢ Force Purge All WooCommerce Data** and run Purge again. The plugin will also show a yellow warning message explaining exactly how many untagged items were found.

= Does it migrate customer passwords? =

Optionally. Enable **Try OC password hash on login** in Settings. OctoWoo stores the OpenCart password hash in user meta and validates it on the customer’s first WP login. On successful validation the hash is automatically upgraded to WordPress’s own format. Subsequent logins use native WP authentication — no manual password reset is needed for customers who remember their old password.

Note: this covers OpenCart’s standard `sha1(md5(salt + password))` hashing only. Custom or third-party hashing schemes are not supported.

= Will my Google rankings be affected after switching domains? =

No, if you run the SEO migrator. OctoWoo’s `SeoMigrator` does two things that protect your Google position:

1. **Slug preservation** — every product and category slug in WooCommerce is updated to match the original OpenCart SEO keyword, so the URL path stays identical.
2. **301 redirects** — old OpenCart query-string URLs (`/index.php?route=product/product&product_id=X`) are redirected with HTTP 301 to the new WooCommerce URLs. Rules are written to both `.htaccess` (Apache) and a WordPress `template_redirect` hook (works on all servers).

Google’s crawler will follow the 301s and transfer link equity to the new URLs. After switching `www.tccq.com` to point at WordPress, visitors who click old Google results will be seamlessly redirected — no 404 errors.

= The migration is stuck and not progressing past the first batch. What do I do? =

This was a known issue with the **Manufacturers** migrator (fixed in v2.4.10). The product-assignment phase was running after every 20-item chunk, scanning thousands of products each time and causing a PHP timeout. After upgrading to v2.4.10:

1. Click **Cancel Background** (if running).
2. Click **Reset Progress** to clear the stale state.
3. Start a fresh Background migration.

For other migrators, if you still see a stuck run: check the Logs tab for error messages, reduce **Batch Size** in Settings (try 10), and use Background Mode so individual chunk timeouts don’t abort the whole run.

= How do I tell which version of OctoWoo is installed? =

The version is shown in two places in the admin panel: right-aligned in the page header next to the plugin title, and in a small line at the bottom of the page. It reads directly from the plugin constant so it always matches the installed code.

= The "migration in progress" banner won’t go away after Abort. =

This was fixed in v2.4.6 and v2.4.7. The lock is now self-healing:

* If all checkpoint rows are in terminal states (completed/failed/aborted), the lock clears on the next page load.
* If the checkpoint rows are more than 2 hours old (regardless of status), the lock is force-cleared.

If you are still on an older version, click **Reset Progress** — it force-aborts any active run and clears all state unconditionally.

= My server has a short PHP execution time limit. What should I do? =

Use **Background Mode** (requires WooCommerce 4.0+). This uses Action Scheduler to run each batch as a scheduled background job — the browser tab can be closed. Alternatively, use **WP-CLI** from the command line.

= Does it support WPML? =

Yes. When **Multilingual** is enabled and both WPML and a secondary language are configured, OctoWoo runs a translation pass after all entities are migrated. It creates translated post/term copies and links them using the WPML API. Polylang is also supported.

= Will it work with WooCommerce HPOS? =

Yes. OctoWoo uses the WooCommerce Orders API (`wc_create_order()`, `wc_get_orders()`) everywhere, which is HPOS-compatible.

= Do I need special server access? =

No. The migration can run entirely within the WordPress admin over AJAX. For large catalogs, Background mode (Action Scheduler) or WP-CLI is recommended.

= Is the source OpenCart store affected? =

No. OctoWoo reads from your OpenCart database but never writes to it.

== Screenshots ==

1. Migration tab — entity selection, progress table, start/abort controls.
2. Settings tab — database credentials, paths, language IDs, per-entity toggles.
3. System Check — validates PHP, memory, extensions, DB connection, disk space.
4. Logs tab — live log viewer with level and migrator filters.
5. WP-CLI — progress bar during `wp octowoo migrate`.

== Changelog ==

= 2.4.25 =
* **Fixed:** Product/category descriptions now decode escaped HTML entities before cleaning, preventing literal `<p>`/`<h1>` tags from appearing on storefront.
* **Fixed:** Product secondary-language selection now falls back to the first non-primary language row when configured secondary language ID is missing.
* **Improved:** Product/category names are now normalized to plain text (decode entities + strip tags), preventing HTML fragments from leaking into titles.

= 2.4.24 =
* **Fixed:** Manufacturer/brand ID mapping now uses a stable key (`manufacturer`) with backward compatibility, improving re-run and resume linking.
* **Fixed:** Brand assignment now runs after products are migrated, ensuring product-to-brand relationships are correctly applied in the same run.
* **Improved:** Added support for `pa_brand` taxonomy detection and deterministic manufacturer batch ordering for safer resume behavior.

= 2.4.23 =
* **Fixed:** Category resume pagination is now deterministic by ordering category batches by `category_id`, preventing missed rows on resume.
* **Fixed:** Category/subcategory hierarchy now auto-recovers when a child is imported before its parent by deferring and re-parenting once the parent mapping exists.

= 2.4.22 =
* **Added:** Pause / Resume and Skip Current controls for chunked/background runs to improve recovery from slow or problematic entities.
* **Added:** Recovery shortcuts in Migration UI: `Images-Only Recovery`, `Products + Images Recovery`, and `Categories + Manufacturers Recovery`.
* **Fixed:** Local image source mode now skips cleanly (single clear warning) when image path is unavailable, instead of repeated per-image HTTP fallback warnings.
* **Improved:** Manufacturer logo import now respects global image toggle (`run_images`) for faster non-image runs.
* **Improved:** System validator now allows migrate-now/images-later workflow by returning a warning (not hard fail) when image migration is intentionally disabled.

= 2.4.21 =
* **Fixed:** Added backend safeguard for image toggle: when `run_images=false`, `ImageMigrator` fully no-ops and embedded image imports from other migrators are skipped, preventing unwanted image fallback warnings.

= 2.4.20 =
* **Fixed:** Local mode now auto-detects available OpenCart table prefix (`octowoo_oc_` or `oc_`), so manually imported SQL dumps with plain `oc_` tables no longer fail with table-not-found errors.
* **Fixed:** `SeoMigrator` now marks its checkpoint complete when `seo_url` table is missing, preventing repeated re-dispatch loops.

= 2.4.19 =
* **Fixed:** `RelatedProductsMigrator` now respects `demo_limit`, so demo runs no longer process the full related-product dataset (e.g. 3957/3957).
* **Improved:** Related-link query is scoped to the selected source-product IDs for better demo performance.

= 2.4.18 =
* **Fixed:** `SeoMigrator` now updates checkpoint progress counters and respects `demo_limit`, so progress no longer shows `completed` with `0 / total`.
* **Fixed:** `ReviewMigrator` query no longer assumes `author_email` exists in OpenCart `review` table, preventing schema-related hard failures on some OC versions.

= 2.4.17 =
* **Fixed:** Image path validation/import now auto-resolves common Cloudways path variants (`/home/...` <-> `/mnt/data/home/...`) so valid directories are not falsely reported as unreadable.
* **Improved:** Pre-migration image scanner uses the same normalization, reducing false missing-image warnings.

= 2.4.16 =
* **Fixed:** `ImageMigrator` now processes images in real batches/chunks, updates checkpoint progress per batch, and respects `demo_limit` in demo runs.
* **Fixed:** Missing local images no longer make progress appear stuck at `0/x`; failed image fetches still advance checkpoint counters so migration can continue.

= 2.4.15 =
* **Fixed:** Prevented false "Migration completed" banner while checkpoint rows are still `pending`/`running`. The chunk loop now continues until all rows are terminal.
* **Fixed:** `Order_statuses` now honors demo mode limit (`demo_limit`) so a 20-item demo does not import all statuses.

= 2.4.14 =
* **Fixed:** Migration could get stuck with many entities permanently `PENDING` because checkpoint keys were inconsistent (`category/product/customer/order/coupon`) while `MigrationManager` dispatches plural keys (`categories/products/customers/orders/coupons`).
* **Changed:** Updated checkpoint keys in affected migrators to plural so status/progress rows are tracked under the same keys used by chunk dispatch.
* **Compatibility:** Kept ID-map entity keys singular (`category/product/customer/order/coupon`) so cross-migrator lookups and existing mapped data continue to work.

= 2.4.13 =
* **Fixed:** Race condition between `pollProgress()` and `runNextChunk()` caused migrations to show "Migration completed!" instantly with all migrators still PENDING. `startPolling()` was firing immediately with an empty `run_id`, the server returned `active: false` for the OLD finished run, and the JS completion handler killed the chunk loop before it even started. Polling now starts only after the first chunk sets a valid `currentRunId`, and the completion guard requires the poll's `run_id` to match the current run.

= 2.4.12 =
* **Fixed:** `ManufacturerMigrator::importBrandImage()` was using `download_url()` to HTTP-fetch local files via the OpenCart shop URL. When the OC URL was unreachable the call hung for 60–300 s, causing a PHP fatal timeout on every chunk after the first 20 manufacturers. Replaced with direct `copy()` to temp file + `media_handle_sideload()` (same approach as `ImageMigrator`).
* **Improved:** Chunk dispatcher now logs the migrator key and current offset on every chunk request, making stuck migrations easy to diagnose in the Logs tab.
* **Improved:** After migration completes the progress table auto-scrolls into view — especially helpful for fast demo runs that previously showed only the "completed" banner.
* **Improved:** Completion banner now includes "See progress table below for details."

= 2.4.11 =
* **Fixed:** `FilterMigrator` was assigning product filter terms using the non-existent taxonomy `product_attr_filter`. Terms were created correctly in per-group `pa_filter-xxx` taxonomies but never linked to products. Phase 3 now receives the `group_map` and assigns per correct taxonomy.
* **Fixed:** `CheckpointManager` had a duplicate `case 'order':` that made the HPOS fallback unreachable dead code. Merged into a single case with HPOS detection.
* **Fixed:** `CheckpointManager::getWcId()` was hitting the DB on every call (N+1 problem). Added a static in-memory cache that is primed by `saveIdMap()` and uses null-caching for misses.
* **Improved:** `TagMigrator` now logs a diagnostic warning when all OC products have empty tag fields.

= 2.4.10 =
* **Fixed:** `ManufacturerMigrator` was calling `assignManufacturersToProducts()` (which scans ALL products) after every single 20-item chunk. With 4,000+ products this caused PHP timeouts on chunk 2+ and left the background migration stuck at 20/117. The product-assignment phase now runs only once, after the final manufacturer chunk completes.

= 2.4.9 =
* **Fixed:** Purge now detects when WooCommerce items exist but have no OctoWoo tag (e.g. after using Reset Progress which clears the id-map). Instead of silently returning "0 deleted", the UI now shows a clear warning: "N item(s) exist in WooCommerce but have no OctoWoo tag — enable Force Purge to remove them."
* **Improved:** `DataPurger::purge()` returns per-entity diagnostic counts (total WC items vs. tagged items) alongside deletion results.
* **Improved:** Detailed warnings are also written to the migration log so the cause is visible in the Logs tab.

= 2.4.8 =
* **Fixed:** Purge now backfills missing `_octowoo_oc_id` meta from the `octowoo_id_map` table before deleting items. Categories, products, and other entities imported by older versions of the plugin (where a slug-lookup bug prevented the meta from being saved) will now be found and deleted correctly instead of showing "0 item(s) deleted".

= 2.4.7 =
* **Fixed:** `getActiveRunId()` now has a time-based stale detection fallback. If any run's checkpoint rows have a `MAX(updated_at)` older than 2 hours, the lock is auto-cleared on the next page load — even if the rows are still showing `running`/`pending` status (e.g. old runs that were never properly closed by the old code). This permanently resolves the "migration in progress" banner for existing stale runs like ones from previous days without requiring any button click.

= 2.4.6 =
* **Fixed:** Clicking Abort on a Background migration no longer re-shows the "migration in progress" banner on page refresh. Abort now cancels all pending Action Scheduler jobs via `BackgroundProcessor::abort()` — previously AS would re-queue the next batch ~5 s later and call `markRunActive()` again.
* **Fixed:** Reset Progress now works even when an active-run lock exists. It force-aborts any running/background migration first, then wipes all checkpoint and ID-map rows for both the active and last-known run IDs. Previously it returned "Cannot reset while a migration is active".
* **Fixed:** `CheckpointManager::getActiveRunId()` is now self-healing — if the stored run has checkpoint rows but none are in an active state (running/pending), the stale lock is cleared automatically on any page load or AJAX call.
* **Fixed:** Purge Imported Data no longer hard-blocks on stale locks; only genuinely active runs block the purge.
* **Improved:** Abort handler now marks both `running` and `pending` checkpoints as `aborted`.

= 2.4.5 =
* **Fixed:** Duplicate WooCommerce categories/products no longer created on re-runs. Two-stage guard: (1) `wp_insert_term` `term_exists` error now resolves the existing term directly from WP_Error data instead of a stale slug lookup; (2) `term_exists(name, 'product_cat', parent)` last-resort check before creation backfills the id_map and skips per policy.
* **Fixed:** AJAX dispatch logger no longer logs high-frequency `octowoo_get_progress` and `octowoo_get_logs` polling calls, eliminating log noise and unnecessary DB writes during active migrations.

= 2.4.4 =
* **Fixed:** Added PHP Fatal error shutdown handler in chunk runner — captures memory exhaustion, class-not-found, and parse errors that bypass try/catch, logs them to the OctoWoo log table, and returns a structured JSON error so the JS UI displays the actual error message instead of a generic "HTTP 500".
* **Improved:** JS retry handler now parses JSON body from fatal error responses for clearer diagnostics.

= 2.3.2 – 2026-04-16 =
* **New:** Pre-migration system validator — checks PHP version, extensions, memory limit, DB connection, image path, and disk space before starting.
* **New:** Background mode using WooCommerce Action Scheduler — migration runs without the browser tab open.
* **New:** `composer.json` with dev dependencies (PHPCS, PHPUnit, Brain\Monkey).
* **New:** `phpcs.xml` — WordPress Coding Standards configuration.
* **New:** Time-based stale lock detection — locks older than 2 hours are auto-cleared on the next Start/Resume.
* **Improved:** HPOS compatibility confirmed — DataPurger uses `wc_get_orders()` throughout.
* **Improved:** BackgroundProcessor retry logic — transient failures reschedule with a 30-second delay instead of permanently failing.

= 2.3.1 =
* Chunked AJAX mode — single-batch AJAX keeps the browser UI responsive during large migrations.
* Improved stale lock guard — empty-checkpoint runs are auto-cleared.
* SQL importer: generator-based line parser handles dumps > 500 MB.
* ZIP image upload: rejects path-traversal filenames.

= 2.3.0 =
* Initial stable release.
* Full 18-entity migration pipeline.
* Resume/checkpoint system.
* WP-CLI support.
* Cron auto-import.
* WPML and Polylang integration.
* Data purger.

== Upgrade Notice ==

= 2.3.2 =
Adds Background Mode and pre-migration validation. Fully backwards-compatible.
No database schema changes in this release.
