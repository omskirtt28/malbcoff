---
tags: [plan, deploy]
updated: 2026-10-21
---
# Deploy Package

## Admin/profile update - 2026-10-21 - latest-only Desktop delivery
**Status: done locally, not yet live.** No live hosting access or deployment confirmation.

ZIP: `C:\Users\John Joshua\Desktop\malbcoff-admin-profile-update-20261021-225800.zip`. Separate upload/rollback guide: `C:\Users\John Joshua\Desktop\malbcoff-admin-profile-update-20261021-225800.txt`.

ZIP SHA256: `9f9a3db5a11c885dd8c6c9d30ac6ab0678b280890b10306b3376bd51a84750df`. Exactly 13 runtime entries, forward-slash app-root paths, no wrapper folder; 13/13 ZIP bytes match current sources. No vault, tests, screenshots, backups, logs, uploads, secrets/config, .git, tools, old ZIPs, README or SQL inside. Guide is separate on Desktop.

### Runtime files
- `actions/account_update.php` (changed)
- `actions/system_admin_branch_rename.php` (new)
- `actions/system_admin_user_create.php` (new)
- `actions/user_management.php` (changed)
- `assets/css/admin-profile.css` (new)
- `assets/js/admin-profile.js` (new)
- `core/AccountChanges.php` (new)
- `core/UserManagement.php` (new)
- `pages/account.php` (changed)
- `pages/system-admin-users.php` (changed)
- `partials/admin-profile-dialogs.php` (new)
- `partials/footer.php` (changed)
- `partials/header.php` (changed)

### Baseline and dependency audit
- Baseline v3 and existing pending records were captured before editing, with six replaced runtime versions and hashes. These six matched the confirmed-live manifest, so no earlier unconfirmed changes are silently bundled. Seven new files were absent from that manifest.
- `core/Idempotency.php`, `core/helpers.php`, `core/Auth.php`, `core/Security.php`, `assets/js/safe-submit.js`, `assets/js/app.js`, `index.php`, `.htaccess` match confirmed-live hashes and remain unchanged. New required helper/partial/asset dependencies are all included.
- No shared path with FIX 8C-2. `assets/css/app.css` remains its pending version, excluded here; the new `admin-profile.css` supplies task-only styling. No P3_007 requirement. Prior pending ZIP absent at its recorded Desktop path, but its last-package record and 12 local hashes are intact.
- `deploy/baseline-manifest.json`, `deploy/last-package.json`, `deploy/migrations.sql`, `deploy/package-notes.txt` are byte-for-byte unchanged. This delivery is recorded separately in `deploy/admin-profile-update-20261021-225800.json`. Do not run confirm-deployed.ps1 against the unrelated inventory last-package record.
- No migration. Requires the existing security/impersonation schema, must_change_password, P3_001 snapshot columns/triggers and P3_002 idempotency_keys, already in confirmed-live baseline v3. No dependency on FIX 8C-2/P3_007.

### Upload and rollback
Back up the database and six existing replaced files first, preserving paths; record whether the seven new paths already exist. Upload all 13 to the exact app-root-relative destinations in the Desktop TXT: new core helpers/assets/partial first, action endpoints next, layout/pages last. `core/UserManagement.php` must precede the refactored Owner action. No SQL, config, app-version or rewrite change. Use normal filemtime assets. Pause account/profile writes briefly during upload to avoid partial versions.

Run mutation smoke tests in an isolated staging copy with synthetic data. Review existing live controls/listings without renaming real branches/accounts just for testing. Guide covers direct admin roles, first-login password gate, duplicate/invalid/CSRF/foreign-ID checks, lost-response Retry, Owner management, Enter Account and history/responsive checks.

Rollback: restore the six backed-up files, then remove only the seven files introduced by this package if no newer patch depends on them. No schema rollback. Code rollback does not undo saved users/names; preserve IDs/history, use authorized targeted correction/deactivation when needed. Full database restore would discard later writes and must be reviewed. Do not alter the inventory pending package or baseline.

### Verification
349 isolated assertions passed (287 main HTTP/browser + 19 retry/Owner/native-form + 34 UI/responsive + 9 original-history/new-snapshot/validator checks); 11 runtime PHP syntax checks, JavaScript syntax and task-scoped whitespace checks passed.

Isolated DB `malbcoff_admin_profile_20261021`: empty current schema (22 tables, 5 existing triggers) plus synthetic records; no real rows copied. All three SafeSubmit flows succeeded. First-login password change unlocked business pages only after replacement. Duplicate emails/branch names, inactive/missing branch creation, invalid names/passwords, unauthorized/anonymous/impersonated sessions, CSRF, repeated keys, foreign user IDs and protected profile fields were checked. Owner Create/Edit/Activate/Deactivate/Reset plus Enter/Return passed. Simulated response loss committed once; Retry reused its key. Storage including IndexedDB contained no temporary password. Original transaction/stock/transfer rows stayed byte-equivalent; new snapshot triggers captured the new name in a rolled-back test.

Pre-task evidence: `output/admin-profile-update/pre-task/`, `pre-task-hashes.json`, `pre-task-git-status.txt`, `task-only.diff`. Results: `checks-result.json`, `extra-result.json`, `ui-result.json`, `history-result.json`. Twelve screenshots: `output/playwright/admin-profile-*` at 360/768/1280; visually reviewed representative phone/tablet/desktop views. Task support files excluded from deployment.

Not performed: live hosting/staging UAT, real devices or Safari/Firefox, parallel multi-worker load/stress or actual network outage (response loss simulated). Wait for explicit **deployed** confirmation naming this ZIP. FIX 8C-2 remains separately pending.

Log ng bawat live-deploy package. **Next package = ang nabago pagkatapos ng huling baseline sa baba lang.**

## Previous deployment status (before the admin/profile task) — confirmed 2026-10-21
**Everything before FIX 8C-2 is LIVE**, including P3_006, inventory soft deletion and zero-stock visibility. Only FIX 8C-2 below is **done, not yet live**. This supersedes the former pending list of 17 files and P3_006.

Packaging update 2026-10-21: reconciled the old v2 manifest to **v3** using the user's prior-work-is-LIVE confirmation, only for the nine files unchanged by FIX 8C-2 and migration P3_006. FIX 8C-2 bytes were NOT marked LIVE. The three files missing from the older manifest still appear NEW to tooling, but already exist on hosting per the owner's confirmation; all 12 delivered app files are replacement updates. The builder dry run now selects exactly these 12 files and only P3_007. `deploy/last-package.json` records the exact scoped ZIP hashes; confirm only after the user says deployed.

## Not yet deployed — FIX 8C-2 only — done, not yet live
No hosting upload, hosting query or production cleanup was performed.

### Desktop package — built and verified 2026-10-21
- ZIP: `C:\Users\John Joshua\Desktop\malbcoff-FIX8C2-only-2026-10-21-004916.zip`
- Extracted folder: same path without `.zip`.
- Exactly 14 ZIP entries: the 12 app files below + `deploy/migrations.sql` (P3_007 only) + `deploy/README.txt`. Every ZIP entry SHA256 matches its source. No old patch files, P3_006 SQL, vault, tests, backups, uploads or credentials.
- Initial builder output included nine already-live files/P3_006 because of the stale v2 manifest. That undelivered output was retired to workspace `backups/superseded-malbcoff-deploy-update-2026-10-21-0047*`, removed from the Desktop. Use only the FIX8C2-only ZIP above.
- Back up first; run the SQL before replacing each app file at its same relative path. Do not upload deploy/ to the website. No live files/database were changed. Reply **deployed** after uploading.

### App files (12 changed for FIX 8C-2)
- `actions/receive_transfer.php` — retain/count detached lines; refuse receipt atomically if a unit was permanently removed.
- `actions/system_admin_deleted_records.php` — guarded global preview/delete-all plus existing selected cleanup transaction.
- `actions/transfer_details.php` — LEFT JOIN, saved identifiers/product label, Unit deleted data; archived sales identifiers remain visible.
- `actions/void_inventory_movement.php` — deny restoration of removed/snapshot history on the server.
- `assets/css/app.css` — compact 2x2 no-wrap pills, 14px metadata/snapshots, 44px cleanup buttons, 360px transfer tab bounds.
- `assets/js/branch-transfers.js` — Unit deleted label and saved product/identifiers.
- `assets/js/deleted-inventory-units.js` — global count preview, typed DELETE, preview token, SafeSubmit.
- `core/InventoryDeletion.php` — sales/void-only archival, required before-delete audit, transfer/movement snapshots and physical removal including old no-sales archives.
- `core/StockMonitoringReport.php` — snapshot drawer/history labels, exclude detached stock-ins from live batch matching; quantities unchanged.
- `pages/stock-movement.php` — Unit deleted identifiers and no void checkbox on snapshot rows.
- `pages/system-admin-deleted-records.php` — durable Permanent Inventory Deletes snapshot log with actor/time/remover/identifiers/detachment counts.
- `partials/deleted-inventory-units.php` — no-sales eligibility, archive reason, global cleanup button and confirmation copy.

### Migration and support
- NEW `database/migrations/P3_007_inventory_delete_snapshots.sql` — nullable transfer/movement unit_id, deleted_unit_id (no FK) + unit_snapshot JSON text on transfer lines and movements. Conditional DDL, schema only, re-runnable. P3_006 is already LIVE and is not pending.
- CHANGED `deploy/migrations.sql` — **only P3_007**.
- CHANGED `DESIGN.md` and vault notes — documentation only, excluded from upload.
- `tests/inventory-lifecycle/fix8c2-{setup.php,state.php,router.php,browser.js,reset.php,extra-setup.php,extra-browser.js,final.js,apply-local.php}` and README — local verification/support only; excluded from upload. Generated manifests/sessions and output/playwright/fix8c2-* evidence are excluded.
- Local backup `backups/inventory-before-P3_007-20261020-184113.sql`, never upload. Schema applied locally with all units/identifiers, transfers and ledger counts/sums unchanged. No actual local units were purged.

### Updated notes (excluded from upload)
- `00 Start Here.md`
- `Changelog.md`
- `Conventions.md`
- `Design System.md`
- `File Index.md`
- `Migrations.md`
- `Bugs/BUG Deleted Inventory Units and Blocked Identifiers.md`
- `Features/Inventory.md`
- `Features/Branch Transfers.md`
- `Features/Stock Movement.md`
- `Plan/Deploy Package.md`

### Deployment / acceptance
Back up the live database and replaced files. Import P3_007 from deploy/migrations.sql **before** uploading these 12 app files, preserving paths. No route, .htaccess, production credentials or configuration changes. Normal filemtime asset versions apply.

Migration does not purge anything. After upload use System Admin > Deleted Records > Deleted Inventory Units > Delete all with no sales, review the global count and type DELETE. The six reported production unit IDs were not accessed here. Verify their actual removal/audit, IMEI receiving at Branches 2/3/4, original transfer display/totals/statuses and sale receipt access. Sales/void rows stay archived with identifiers released.

137 local isolated browser/HTTP/PDO checks passed (102 main + 16 additional + 19 final read-only), including 55-unit global cleanup, rollback, CSRF/roles/typed confirmation, stale/empty preview, audit snapshots, unchanged dated movement buckets and Monitoring totals, unchanged transfer parents, original sales/voids, shared drawers, IMEI receiving at Branches 2/3/4, 360/768/1280 layout/typography. P3_007 passed twice on the isolated DB; local schema application backed up and verified. Hosting checks remain pending deployment.

## Earlier packages — LIVE
All packages below and earlier patches are LIVE per user confirmation on 2026-10-20. Original build details are retained for history; their former unconfirmed status no longer applies.

## Package #1 — 2026-10-17
Zip: `Desktop\malbcoff-owner-updates-2026-10-17.zip` (folder `Desktop\malbcoff-deploy\`)
Method: (b) walang baseline file/tag → `git diff 021cbad` (huling commit bago ang unang patch, 2026-10-09) + untracked files. Hindi na-verify kung ano ang aktwal na nasa live (posibleng nandoon na ang commit `80dcecd` "UP").

**Covered patches (done at ticked):** 1, 1R, 2, 2B, 2C, 3, 4, 5, 6a.
**Hindi covered:** 6b (done later on 2026-10-17 — goes into the next package: `sw.js`, `offline.html`, `manifest.webmanifest`, `assets/icons/`, `assets/js/sw-register.js`, `partials/pwa-*.php`, plus changed files listed in [[Patch 6b - Offline Shell and Cache]]; `.htaccess` blocks for 6b must be copied too), 6c (not started).
**Partial/untested flag:** walang patch na naka-flag na partial; "Acceptance" ng bawat patch ay naka-tick sa notes pero lahat ay local-test lang — hindi pa nasubukan sa live. Patch 6a: slow-network behavior na-simulate lang.

**Files (34):** `index.php`; actions: `account_update`*, `adjust_inventory`, `complete_sale`, `export_stock_monitoring`, `forward_inventory`, `ping`*, `receive_transfer`, `stock_in`, `stock_monitoring_drawer`*, `transfer_details`; assets: `css/app.css`, `js/app.js`, `branch-transfers.js`, `device-scanner.js`†, `inventory-forward.js`, `kpi-drawer.js`*, `pos.js`, `product-quick-setup.js`, `safe-submit.js`*; core: `helpers.php`, `Idempotency.php`*, `StockMonitoringReport.php`; pages: `account`*, `branch-transfers`, `dashboard`, `inventory`, `pos`, `sales-records`, `stock-in`, `stock-monitoring`, `stock-movement`; partials: `footer.php`, `topbar.php`.
(* = bago · † = galing sa commit "UP", walang patch — unsure, isinama.)

**Hindi kinopya:** `.htaccess` (isang linya lang ang dagdag: `RewriteRule ^account$ index.php?page=account [L,QSA]` — nasa README para idagdag mano-mano), `CLAUDE.md`, `malbcoff-vault/`, `config/` (walang pagbabago).

**Migrations (`deploy/migrations.sql`, ayos):** 1) `P3_001_user_name_snapshots.sql` (Patch 5) 2) `P3_002_idempotency_keys.sql` (Patch 6a). Pareho re-runnable; walang drop/truncate ng data.

Details at test checklist: `deploy/README.txt` sa loob ng zip. Tingnan din [[Migrations]] · [[Owner Updates Plan]] · [[Changelog]].

## Package #2 — 2026-10-17 — BUILT (incremental) — **this is the NEW BASELINE**
Zip: `Desktop\malbcoff-deploy-update-2026-10-17.zip` (folder `Desktop\malbcoff-deploy-update-2026-10-17\`, 45 files + `deploy/migrations.sql` + `deploy/README.txt`). Package #1 was not deleted.
Method: compared every app file with package #1 (SHA-256); files that package #1 does not contain were compared with git commit `021cbad` (that is what package #1 used as its baseline, so those files are assumed live as in that commit). Result: **16 new + 29 changed**; the set equals exactly the files modified after package #1 was built (cross-checked by file time). `deploy/migrations.sql` holds only P3_003, P3_004, P3_005. Not included on purpose: vault, `CLAUDE.md`, `.md` docs, `DESIGN.md`, `database/`, `deploy/` (old), real config (`config/*.local.php`). Manual config lines (live `config/app.local.php`): `'version' => '1.1.0'`, `'enable_service_worker' => true`. `.htaccess` is in the package but README step 2 says to merge by hand if the live file has host-specific lines (needs the `needs-action` + `account` rewrite rules and the 6b blocks). No vault note exists for a "Patch 9", so none was covered.
**Next package = whatever changes after this one.** Compare with this package's folder (or the project at the time of building) the same way; deployment confirmed 2026-10-20; see the current baseline above.

### What package #2 covers — everything done after package #1
**Patches:** 6b (service worker / offline page / banner), 8 (price at checkout), 8B (checkout modal polish), **6c-1 (offline outbox for stock actions)** and **6c-2 (offline sales outbox)**.

**Migrations — `deploy/migrations.sql` now exists in the repo** (P3_001 → P3_005 concatenated, each section re-runnable; P3_001/P3_002 may already be live, re-running is harmless; needs the TRIGGER privilege). New since package #1:
- `P3_005_offline_sales.sql` (Patch 6c-2: offline columns + UNIQUE `client_id` on `sales`, wider `outbox_reports.details`) — see the 6c-2 paragraph below.
- `P3_003_price_at_checkout.sql` (Patch 8: `sale_items.price_entry_mode` + trigger `trg_sale_items_price_final`). **Import it BEFORE or together with the Patch 8 code** — the code also works without it, but then prices are not locked at database level and the Last chip stays empty.
- `P3_004_outbox_reports.sql` (Patch 6c-1: table `outbox_reports` for the owner's Needs Action list). Without it the app still works: the phone's report call answers "ok" and the owner page says "This list is not set up yet".

**Patch 6c-1 files** (see [[Patch 6c-1 - Offline Outbox for Stock Actions]]): new `assets/js/outbox.js`*, `actions/outbox_status.php`*, `actions/outbox_report.php`*, `actions/outbox_resolve.php`*, `pages/needs-action.php`*, `database/migrations/P3_004_outbox_reports.sql`*, `deploy/migrations.sql`*; changed `assets/js/safe-submit.js` (also a 6b/8B file), `assets/js/branch-transfers.js`, `assets/js/inventory-forward.js`, `pages/stock-in.php`, `actions/stock_in.php`, `core/Idempotency.php`, `core/helpers.php`, `index.php`, `.htaccess` (**add `RewriteRule ^needs-action$ index.php?page=needs-action [L,QSA]`** — without it `/needs-action` is a 404 on the live host), `partials/header.php`, `partials/footer.php`, `partials/sidebar.php`, `partials/mobile-nav.php`, `pages/dashboard.php`, `assets/css/app.css`, `DESIGN.md`. Import the migration before or together with the code; bump `version` in `config/app.local.php` so phones drop the old cached JS. **Smoke test:** as a branch user turn on airplane mode on an open Receive Stock page → confirm → "Saved on this device" + Queued: 1 badge → airplane mode off → item sends once, badge disappears; as owner check `/needs-action` opens (empty).

**Patch 6c-2 files (offline sales, see [[Patch 6c-2 - Offline Sales Outbox]]):** new `database/migrations/P3_005_offline_sales.sql`*; changed `actions/complete_sale.php`, `actions/outbox_report.php`, `core/Idempotency.php`, `core/helpers.php`, `assets/js/outbox.js`, `assets/js/safe-submit.js`, `assets/js/pos.js`, `pages/pos.php`, `pages/sales-records.php`, `pages/needs-action.php`, `pages/dashboard.php`, `assets/css/app.css`, `DESIGN.md`, and `deploy/migrations.sql` (now P3_001 → **P3_005**). **Migration `P3_005_offline_sales.sql`** (adds `sales.client_id` UNIQUE, `is_offline`, `device_time`, `received_at`, `offline_receipt_no`; widens `outbox_reports.details` to 2000): re-runnable, no drop/truncate; import it **before or together with** the code (the app still works without it, but then there is no client-id duplicate guard, no offline marks in Sales Records and a refused sale's owner report is cut at 600 characters). No `.htaccess` change. Bump `version` in `config/app.local.php` so phones drop the old cached JS. **Smoke test:** as a branch user open POS, scan an item, Checkout → type price → Confirm Sale, then turn airplane mode on **before** the final Confirm → "Sale saved on this device" + `Queued: 1` → airplane mode off → the sale is created once and the Outbox row shows its real Sale no.; Sales Records shows "Saved offline · OFFLINE-…"; as owner `/needs-action` opens.

**Patch 8 files:** `actions/complete_sale.php`, `actions/pos_last_prices.php`*, `pages/pos.php`, `assets/js/pos.js`, `assets/css/app.css`.
**Patch 8B files (visual only, on top of Patch 8):** `pages/pos.php`, `assets/js/pos.js`, `assets/js/safe-submit.js` (also a 6b file), `assets/css/app.css`.
**Patch 6b files:** `sw.js`*, `offline.html`*, `manifest.webmanifest`*, `assets/icons/*.png`*, `assets/js/sw-register.js`*, `assets/js/safe-submit.js`, `partials/pwa-head.php`*, `partials/pwa-register.php`*, `partials/header.php`, `partials/footer.php`, `login.php`, `change-password.php`, `maintenance.php`, `config/app.php`, `config/app.production.example.php`, `.htaccess` (6b blocks + this block must be copied — it was not part of package #1).
(* = new.) `assets/css/app.css` carries both patches. After deploy: bump `version` in `config/app.local.php` (e.g. 1.0.1) so phones drop the old cache; emergency off switch: [[How to turn off the service worker]].
**Smoke test after deploy:** log in as a branch user → scan an item → Checkout → type prices → Confirm Sale → pay → check Sales Records shows the typed totals; try to save a price of 0 (button must stay disabled).

## Package #4 — 2026-10-18 — BUILT with the Deploy Rule — **LIVE — confirmed 2026-10-20, baseline v2**
Folder + zip: `Desktop\malbcoff-deploy-update-2026-10-18-1154\` / `.zip` (real zip, 15 entries: 13 files + `deploy/migrations.sql` + `deploy/README.txt`). Baseline used: **v1** (Package #2 live, confirmed 2026-10-18 by the user's statement). Method: SHA-256 of every app file vs `deploy/baseline-manifest.json`; zip entries re-hashed against the project (0 mismatches); no forbidden path inside.
**0 new + 13 changed** — the tablet login fix, same set as package #3: `.htaccess`, `assets/css/app.css`, `assets/js/safe-submit.js`, `assets/js/sw-register.js`, `change-password.php`, `config/app.php`, `config/app.production.example.php`, `core/Security.php`, `index.php`, `login.php`, `maintenance.php`, `partials/pwa-register.php`, `sw.js`.
**Migrations:** none (`deploy/migrations.sql` only says "no new database changes"). **Manual config (live `config/app.local.php`):** `'version' => '1.1.1'`, `'enable_service_worker' => true`; optional `'login_debug' => true` only while diagnosing. README has the `.htaccess` block, the tablet clear-cache step and a test checklist.
**Use this one instead of package #3** (same files; #3 stays on the Desktop as history). Confirmed LIVE 2026-10-20. `deploy/confirm-deployed.ps1` has advanced the baseline to v2 from this exact package before task edits.

## Package #3 — 2026-10-18 — BUILT (incremental) — tablet login fix
Folder + zip: `Desktop\malbcoff-deploy-tablet-login-fix-2026-10-18` / `.zip` (13 files + `deploy/README.txt`; **no migration**). Method: every app file compared byte-for-byte with package #2 (12 differ) and with git HEAD for files not in #2 (`core/Security.php`); equals `git status`. Not included: vault, `config/*.local.php`, `offline.html` (unchanged, now unused).
Fixes [[BUG Tablet Login Stays on Login Page]].

**Changed files (13):** `sw.js`, `assets/js/sw-register.js`, `assets/js/safe-submit.js`, `assets/css/app.css`, `partials/pwa-register.php`, `login.php`, `change-password.php`, `maintenance.php`, `index.php`, `core/Security.php`, `config/app.php`, `config/app.production.example.php`, `.htaccess` (add the `FilesMatch` no-store block above the "service worker, offline page and manifest" comment if the live file is host-specific).
**Manual config (live `config/app.local.php`):** `'version' => '1.1.1'` (any new value); optional `'login_debug' => true` only while diagnosing, then back to false.
**README includes** a "tablet clear cache" step (Chrome / Samsung Internet / Safari / home-screen app) and a real-tablet test list. Check afterwards that Security Logs show no `auth.session_rejected` / `user_agent_changed` for tablets.
