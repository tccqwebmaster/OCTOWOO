# Dashboard Button Audit & Conversion Plan (v2.5.79 baseline)

Goal: every CLI fix we ran this session should be a dashboard button a customer can click.
Buttons must: (1) call a registered action, (2) run reliably WITHOUT background cron,
(3) show progress, (4) write logs.

## Audit results — existing buttons

| Button | JS handler | Action sent | Backend handler exists | Mode | Verdict |
|---|---|---|---|---|---|
| Re-run Images | startImagesOnlyRecovery | start_migration (images,categories,manufacturers) | yes | foreground startMigration | OK |
| Re-run Products + Images | startProductsImagesRecovery | start_migration (products,images,related) | yes | foreground | OK |
| Re-run Categories + Brands | startCategoriesManufacturersRecovery | start_migration (categories,manufacturers) | yes | foreground | OK |
| Re-run Multilingual | startMultilingualRecovery | clear_cron_lock → start_background (multilingual) | yes | **BACKGROUND (cron)** | RISK: stalls on hosts w/o reliable cron |
| Check ML Readiness | runMultilingualPrecheck | octowoo_multilingual_precheck | yes | sync | OK |
| Clear Cron Lock | clearCronLock | octowoo_clear_cron_lock | yes | sync | OK |
| Full Cleanup | runFullCleanup | octowoo_full_cleanup | yes | sync | OK but uses OLD logic (pre-session) |
| Re-run SEO | rerunSeoMigrator | octowoo_rerun_seo | yes | sync | OK |
| Fix Arabic→WPML Links | repairWpmlArabicLinks | octowoo_repair_wpml_arabic_links | yes | sync | OK |
| Fix Arabic Content | (inline) | octowoo_fix_secondary_content | yes | sync | OK |
| Fix Product→Category Links | repairCategories | octowoo_repair_categories | yes | sync | OK |
| Fix Orphan Categories | cleanupMlTerms | octowoo_cleanup_ml_terms | yes | sync | OK but OLD logic |
| Fix Order→Product Links | repairOrderItems | octowoo_repair_order_items | yes | sync | OK |

## KEY FINDING
The main risk is BACKGROUND mode (Action Scheduler/WP-Cron). On Cloudways here the loopback
cron stalls → background runs freeze at "Chunk-start". Foreground recovery buttons are fine.

## CLI fixes from this session NOT yet on dashboard (or using old logic)
1. reset_categories (category-only clean reset)            → NO button
2. relink_categories (rebuild product↔cat from OC)          → partial (repair_categories) — verify logic
3. ensure_category_translations (create AR twins, OC names) → NO button
4. pair_orphan_categories (relink real AR twins / del junk) → Fix Orphan uses OLD logic
5. fix_term_language (relabel mislabeled EN)                → NO button
6. dedupe_terms (already CLI; Full Cleanup should call it)  → verify Full Cleanup uses it
7. fix_slugs (ow-t- merge)                                  → verify Full Cleanup uses it

## Plan (one at a time, each: backend method → register action → JS handler → button → lint → version → commit)
- [ ] STEP 1: Upgrade Full Cleanup to call today's perfected logic (dedupe + fix_slugs + pair_orphan + audit_purge) with a DRY-RUN preview + report. One safe button = most customer value.
- [ ] STEP 2: Add "Reset Categories Only" button (reset_categories).
- [ ] STEP 3: Add "Rebuild Product→Category Links" (relink_categories) — verify vs repair_categories.
- [ ] STEP 4: Add "Create Missing Arabic Category Twins" (ensure_category_translations).
- [ ] STEP 5: Add "Fix Mislabeled Category Language" (fix_term_language).
- [ ] STEP 6: Make heavy buttons run in FOREGROUND chunked mode (no cron dependency) OR add a cron-health warning.
- [ ] STEP 7: Confirm progress bar + log panel update for each new button.
