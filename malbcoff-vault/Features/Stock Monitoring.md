---
tags: [feature]
---
# Stock Monitoring
Route `stock-monitoring` · roles owner/branch_manager/inventory · page `pages/stock-monitoring.php` · data `core/StockMonitoringReport.php` · export `actions/export_stock_monitoring.php` · JS: inline collapse-by-brand script (localStorage).

- Params: `date`, `branch` (owner only), `brand`, `q`.
- One SQL over `stock_movements` ⨝ products, grouped per product: opening (< day start), in, out, sold, ending (< day end). `HAVING` drops all-zero products.
- Report `view`: `matrix` (owner + All Branches): KPI cards + `Model × Branch columns × Overall`. `branch` (branch user, or owner with a branch picked): Opening/IN/OUT/SOLD/Ending table scoped to that branch, no KPI cards, no Overall column.
- Branch users are always forced to their branch (`Auth::branchId()`), owner is not.
- Topbar owner switcher is hidden on this page; the page Branch filter drives it. Export follows the same view rule.
- Fixed: [[BUG Stock Monitoring Owner Branch View]] (Patch 1, 2026-10-16).
Touched by plan: Patch 1, 2, 3, 4 ([[Owner Updates Plan]]).
