---
tags: [feature, system-admin]
updated: 2026-10-28
---
# Sold Units (System Admin Void & Restore)

**Status 2026-10-28: done locally, NOT live, not browser-tested.** php.exe is blocked by Application Control in this session, so there was no `php -l`; JS `node --check` passed; patch-tester did a manual read: PASS WITH NOTES.

**Delivery 2026-10-28:** standalone package `Desktop\malbcoff-sold-units-update-2026-10-28-1353(.zip)`, 7 files, no migration, independent of FIX 8C-2 / admin-profile (those stay pending). Awaiting "deployed". On "deployed" run `deploy\confirm-deployed.ps1` (last-package.json lists only these 7 files).

**Why:** a branch reported a unit (Samsung A07, IMEI 351388336554021, "sold" Oct 8 2026) that is still physically on hand. System Admin needs to see sold units and void the wrong sale so the unit returns to available stock.

**What:** System Admin page `?view=sold-units` (route `system-admin-sold-units`, sidebar "Sold Units"). Lists `inventory_units.status='sold'` not yet voided; filters q/branch/from/to; 25 per page. "Void & Restore" (reason 3-180) posts to the existing `actions/void_inventory_movement.php` with `return_to=sold-units`. No schema change, no migration, no `.htaccess` change. Needs table `inventory_movement_voids` (P2_024, `database/P2_024_system_admin_inventory_void.sql` is not in the repo; confirm it is live).

**Files:** `pages/system-admin-sold-units.php` (new), `index.php`, `core/helpers.php` (app_url), `partials/sidebar.php`, `partials/mobile-nav.php`, `actions/void_inventory_movement.php:10`, `assets/js/inventory-void.js:22-31`.

**Disabled rows (with reason):** later stock activity, discounted/adjusted receipt, REMOVE-/deleted history, sale not completed, inactive branch, archived product.

**Known notes (left alone):** page eligibility does not mirror unit-branch vs movement-branch check or duplicate sale_item matches (action still rejects with a clear message); LIKE does not escape % and _; Deleted Records filter form lacks hidden `view` input (same class, not touched); no Sold Units quick link on System Admin dashboard.

**Hook:** `.claude/allow-claude-edit` created 2026-10-28 with John's approval because the global `codex-first.js` hook was blocking edits (rule was revoked 2026-10-27).

See [[BUG Deleted Inventory Units and Blocked Identifiers]], [[Stock Movement]].
