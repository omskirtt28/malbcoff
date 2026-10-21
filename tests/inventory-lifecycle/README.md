# Inventory lifecycle verification (local only)

## FIX 8C-2 (2026-10-21)
137 isolated browser/HTTP/PDO checks passed (102 main + 16 extra + 19 final read-only). Evidence: `output/playwright/fix8c2-checks.txt`, `fix8c2-extra-checks.txt`, cleanup and transfer screenshots at 360/768/1280.

New scripts all target only the existing isolated `malbcoff_fix_20261020_v2` database: `fix8c2-setup.php` (P3_007 twice, schema/index evidence and fixtures); `fix8c2-state.php`; `fix8c2-router.php` (explicit DB-name gate); `fix8c2-browser.js`; `fix8c2-extra-setup.php` + `fix8c2-extra-browser.js` (55 global units, new sales archive, identifier release, receipt refusal and final render checks). These are Playwright CLI run-code functions, not test specs. Example: `npx --yes --package @playwright/cli playwright-cli -s=fix8c2 run-code --filename tests/inventory-lifecycle/fix8c2-browser.js` after opening the fixture server at `http://127.0.0.1:8879`. Server command requires `DB_NAME=malbcoff_fix_20261020_v2` and an absolute PHP session.save_path pointing at this directory's sessions folder.

Fixtures are stateful and have completed their mutations, including re-receiving old IMEIs. Do not rerun setup/main against the final state. `fix8c2-reset.php` was used only to restore this run's deleted generated units after interrupted browser checks; it is not a complete reset after the receiving/extra scenarios. Prefer a fresh isolated clone for another full run.

`fix8c2-apply-local.php` was run once after validation: backs up local transfer lines/movements and applies schema-only P3_007 to local malbcoff_pos, checking no unit/identifier, transfer or ledger changes. It never purges local units. Hosting was not accessed. All these scripts/manifests/sessions/evidence/backups are excluded from deployment. All earlier fixes are LIVE per the user's 2026-10-21 confirmation; FIX 8C-2 is done, not yet live.

## Earlier verification (historical)
Task started 2026-10-20; final verification 2026-10-21. Hosting was not accessed.

These scripts use an isolated local clone, `malbcoff_fix_20261020_v2`, and test users. Never point them at hosting or production. `router.php` only enables its fixture-session route when DB_NAME matches this exact isolated test database; it is excluded from deployment along with this entire directory.

Recorded scenarios: branch soft delete; identifier blocking/release; single/selected/select-all admin dialog; sales/transfer/movement preservation; unreferenced physical delete; owner/staff endpoint denials; CSRF; idempotency; mixed-bulk rollback; valid 15-digit IMEI received at another branch; last phone/accessory sale; last-unit transfer; older zero-history rows; inactive/never-stocked model exclusion; both tabs, branch/owner/matrix, CSV, KPI drawer sums.

- `setup.php`: clone local schema/data, then apply migration twice. Reuses the isolated database if it already exists.
- `seed.php`, `extra-seed.php`: create scenario data and `fixtures.json`.
- `router.php`: PHP test server router; fixture sessions only, no changes to application authentication.
- `browser.cjs`, `extra.cjs`: stateful functional checks with bundled Playwright + Chrome (not @playwright/test).
- `final.cjs`: repeatable read-only browser sweep after the functional scenarios: 88 checks (360/768/1280, both tabs, all report scopes, drawer sums, cleanup dialog).
- `reconcile.php`: repeatable 18 numeric and actual Dashboard/Sales Records/Stock Movement rendering checks.
- `state.php`: inspect fixture state/audits without changing rows.
- `fonts.cjs`, `visual.cjs`: targeted computed typography/target checks and screenshots.
- `apply-local.php`: used once after isolated verification; validates local host/name, backs up inventory_units + stock_movements, then applies P3_006 to local malbcoff_pos. No hosting connection.

The mutation scenarios are stateful; their current isolated DB/fixtures contain the completed result. For a new full run, use a fresh isolated clone and regenerated fixtures, rather than repeating destructive scenario steps against the completed fixtures. The final/reconciliation checks may be repeated as-is.

Evidence lives in `output/playwright/` at project root (excluded by deploy/_common.ps1): screenshots, checks.json, extra-checks.json, final-checks.json. Final PHP/JS syntax and git diff whitespace checks passed. Local backup is `backups/inventory-before-P3_006-20261020.sql` (excluded from git/deploy). Local migration exposed 16 legacy removals; 6 available units stayed unchanged.
