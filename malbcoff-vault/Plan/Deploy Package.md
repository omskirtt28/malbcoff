---
tags: [plan, deploy]
updated: 2026-10-17
---
# Deploy Package

Log ng bawat live-deploy package. **Next package = ang nabago pagkatapos ng huling baseline sa baba lang.**

## Baseline para sa susunod na package
> **UPDATE 2026-10-17:** ang bagong baseline ay **Package #2** (`Desktop\malbcoff-deploy-update-2026-10-17.zip`, tingnan sa baba). Ang susunod na package ay dapat ihambing sa package #2 (SHA-256), hindi na sa package #1.
- Git: wala pang tag/commit para sa deployed state (lahat ng patch ay uncommitted sa working tree nang gawin ang package #1).
- **Gawin muna:** i-commit ang kasalukuyang state at mag-tag, hal. `git tag deployed-2026-10-17`, at isulat dito ang tag. Hanggang wala, gamitin ang listahan sa ibaba (files "covered" na).
- Kung hindi pa na-deploy ang package #1 sa live, ang susunod na package ay dapat isama pa rin ang laman nito.

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
**Next package = whatever changes after this one.** Compare with this package's folder (or the project at the time of building) the same way; update this section when it is deployed to live (not yet confirmed as deployed).

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
