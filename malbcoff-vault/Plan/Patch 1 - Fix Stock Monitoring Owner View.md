---
tags: [plan, patch]
---
# Patch 1 — Stock Monitoring: owner sees every branch
**Status: DONE 2026-10-16** (see implementation notes at bottom)
Fixes [[BUG Stock Monitoring Owner Branch View]].

## Files to touch
- `core/StockMonitoringReport.php`
- `pages/stock-monitoring.php`
- `actions/export_stock_monitoring.php` (follow new report shape)
- `partials/topbar.php` (hide owner switcher on this page)
- `assets/css/app.css` (small: highlight column, only append)

## Design (REVISED 2026-10-16 per John)
Rule: **owner picks a branch → sees exactly what that branch's user sees** (Opening / IN / OUT / SOLD / Ending per model, brand groups, totals). Owner picks All Branches → sees the compare matrix (Model × every branch × Overall).
1. Keep `branch` as a real filter for the owner **only in single-branch view**: reuse the existing branch-user query + table markup with `branch_id = picked branch`.
2. All Branches (no branch picked): ONE query grouped by product + branch_id, pivot in PHP → matrix with every active branch column + Overall (Overall = sum of columns). KPI cards = all branches.
3. Branch picked: KPI cards = that branch; table = branch-user table (same columns as the screenshot John sent: Opening Stock, IN, OUT, SOLD, Ending Stock). No matrix, no Overall column.
4. Extract the branch-user table markup into `partials/stock-monitoring-branch-table.php` so owner and branch user share it (no duplicate HTML).
5. Topbar owner switcher: hide on this page (page has own Branch filter) so two selectors do not fight.
6. Export: owner All Branches = matrix sheet; owner with branch = same layout as branch-user export.
7. Branch users unchanged (forced to own branch, ignore `?branch=`).
8. Optional later: In-transit units column for All Branches.

## Acceptance
- [x] Owner, All Branches: columns = every active branch + Overall; Grand total = sum of columns.
- [x] Owner, pick Branch 1: page looks the same as Branch 1 user's Stock Monitoring (Opening/IN/OUT/SOLD/Ending, same numbers).
- [x] Owner picks a different branch → numbers change to that branch.
- [x] Branch user sees only own branch (`?branch=2` ignored).
- [x] Past date gives historical numbers.
- [x] Excel export matches screen in both modes.
- [x] Branch ending vs Dashboard "Branch Inventory" for today (note any ledger drift).

## Implementation notes (2026-10-16)
- Report returns `view` = `branch` (branch user, or owner with `?branch=` set) or `matrix` (owner, All Branches). Matrix pivot query only runs in `matrix`.
- Final spec from John: owner + any branch = EXACTLY the branch user view (no KPI cards, no Overall column, title "Stock Monitoring"). This replaces design points 3 (KPI cards for the branch) and 4 (shared partial was NOT extracted; markup stays inline in the page — `partials/stock-monitoring-branch-table.php` is unused leftover).
- Topbar owner switcher hidden on `stock-monitoring`; the page Branch filter is the only selector (shows the branch chip).
- `assets/css/app.css` not touched.
- Browser tab title is still "Overall Stock Monitoring" for owners (`index.php` L101) — out of Patch 1 scope.
- Verified on 4 branches x 4 filter sets: owner+Bn == Bn user; matrix Grand Total (11) == sum of branch cols == sum of branch users; branch user ignores `?branch=`; export body == screen. Local data has zero IN/OUT/SOLD today, so only opening/ending were exercised.
