# CartShift — WooCommerce Marketplace Readiness & Worldwide Audit
_Audit date: 2026-06-02 · Audited version: 2.5.80_

Reframing the goal: this is no longer "fix tccq.com." It is "ship a product a stranger in
Brazil or Germany installs on a store I will never see, and it must not break their data."
Everything below is judged against that bar and against the WooCommerce.com Marketplace
submission requirements.

---

## VERDICT
Strong foundation, NOT yet submittable. The architecture is real (PSR-4, 48 files, HPOS
declared, config-driven languages, WP-CLI, readme.txt, uninstall.php, i18n used). But there
are ~6 hard blockers that fail automated/manual marketplace review on day one, plus
worldwide-readiness gaps that would generate support tickets and refunds.

---

## A. HARD BLOCKERS (fail review immediately — must fix before submission)

1. **readme.txt `Stable tag` is 2.5.43 but plugin is 2.5.80.**
   Marketplace + WP.org reject on mismatch. The `scripts/bump_version.php` exists but is not
   being run. FIX: always bump via the script; CI check that header version == readme Stable
   tag == OCTOWOO_VERSION == composer version.

2. **Logs written INSIDE the plugin directory** (`OCTOWOO_LOG_DIR = OCTOWOO_PLUGIN_DIR.'logs/'`).
   Disallowed: the folder is wiped on every plugin update, and writing into plugins/ is a
   security finding. FIX: write to `wp_upload_dir()['basedir'].'/octowoo-logs/'` with an
   index.html + .htaccess deny, OR use `WC_Logger` (preferred — integrates with WooCommerce →
   Status → Logs, which reviewers expect). This also fixes "logs lost on update."

3. **No automated tests.** Marketplace QA expects at least smoke/unit coverage for a data-
   migration plugin (the risk is customer data loss). FIX: PHPUnit + WP test scaffolding;
   cover the dangerous paths first (dedupe scramble-guard, WPML linking, purger). Even 20
   focused tests dramatically de-risk review and regressions.

4. **Background mode depends on WP-Cron loopback, which silently stalls** (the entire reason
   this project used CLI all day). On shared/managed hosts worldwide this will "hang at
   Chunk-start" for a large fraction of customers. FIX: (a) detect cron health and warn; (b)
   offer a true foreground AJAX-chunked runner that does not need cron; (c) document the
   real server-cron setup. This is the #1 future support-ticket generator.

5. **Debug leftovers** (7 `error_log`/`print_r`-class calls). Reviewers flag stray debug
   output. FIX: route through Logger at debug level, gate behind WP_DEBUG.

6. **Destructive operations without a built-in backup gate.** This plugin deletes/relabels
   terms, purges data, resets migrations. We hit data loss with NO backup repeatedly. A
   marketplace data tool must (a) refuse destructive ops unless the admin confirms a backup,
   (b) ideally export the affected rows to a restore file first. FIX: pre-flight "I have a
   backup" confirm + optional auto-export of affected term/post IDs to a JSON restore file.

---

## B. WORLDWIDE-READINESS (works on MY store, breaks on THEIRS)

7. **Primary-language logic assumes English in places.** Codes are config-driven
   (`primary_locale`/`secondary_locale`), good — but e.g. WpmlIntegration line ~2022 special-
   cases `primary_lang === 'en'`. A store whose primary IS Arabic/Hebrew/Japanese hits the
   edge case. FIX: remove the 'en' special-case; treat primary generically; add tests for a
   non-English primary.

8. **Two-language assumption.** The model is primary + ONE secondary. Many stores run 3–6
   languages. FIX: iterate an array of secondary locales, not a single `secondary_locale`.
   (Bigger change — can be a v3 feature, but document the limitation now.)

9. **OpenCart schema variance.** OC 1.5/2.x/3.x/4.x differ (table names, `oc_` prefix,
   `seo_url` vs `url_alias`, customer password hashing). Verify each migrator degrades
   gracefully and reports "table not found" instead of fatal. FIX: a capability/schema probe
   per OC version, surfaced in System Check.

10. **Image path assumptions.** Hardcoded example IPs (`13.206.54.252`) are only placeholders
    (fine), but image import assumes a reachable path/URL. Worldwide: remote DB + local
    images is common. FIX: support (a) HTTP image base URL, (b) zip upload, (c) SSH/FTP path
    — and validate reachability in System Check.

11. **Character set / collation.** Arabic worked, but worldwide means CJK, Cyrillic, emoji in
    product names. FIX: ensure all writes are utf8mb4; the slug helper already preserves
    \p{L}\p{N} (good) — add tests for CJK + Cyrillic slugs.

---

## C. POLISH / EXPECTED FOR A PAID PRODUCT

12. **i18n completeness + shipped .pot/.po.** i18n functions are used, but verify EVERY user-
    facing string is wrapped and ship an up-to-date `languages/octowoo.pot`. Marketplace
    checks this.
13. **Onboarding for non-technical buyers.** The wizard exists — make the happy path
    truly 3 clicks (connect → preview → migrate) and move the 13 recovery/fix buttons behind
    an "Advanced / Troubleshooting" disclosure so the dashboard isn't intimidating.
14. **Uninstall hygiene.** `uninstall.php` exists — verify it removes options, transients,
    scheduled actions, AND the (relocated) logs, but NEVER customer store data.
15. **Capability + nonce on every action.** Centralized dispatcher is good. Add an automated
    test asserting every registered action requires the nonce + cap (prevents regressions).
16. **Documentation site / support URL / changelog discipline.** Marketplace requires a
    support channel and a clear changelog (changelog is good; keep readme.txt in sync).

---

## RECOMMENDED ORDER (highest risk-reduction first)
1. Fix readme Stable tag sync + run bump script (1 hour). [BLOCKER 1]
2. Relocate logs to uploads/ or WC_Logger (half day). [BLOCKER 2]
3. Backup-gate + restore-file export on all destructive ops (1–2 days). [BLOCKER 6]
4. Foreground chunked runner + cron-health warning (2–3 days). [BLOCKER 4]
5. Remove debug leftovers + 'en' special-case (half day). [BLOCKER 5, item 7]
6. PHPUnit harness + ~20 tests on dangerous paths (3–5 days). [BLOCKER 3]
7. OC-version schema probe in System Check (2 days). [item 9]
8. Onboarding simplification + Advanced disclosure (2 days). [item 13]
9. i18n sweep + ship .pot (1 day). [item 12]

None of this requires touching a customer's data to develop — it can all be built and unit-
tested locally, which is the right way to avoid the live-DB firefighting pattern.
