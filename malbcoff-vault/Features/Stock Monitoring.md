---
tags: [feature]
---
# Stock Monitoring
Route `stock-monitoring` · roles owner/branch_manager/inventory · page `pages/stock-monitoring.php` · data `core/StockMonitoringReport.php` · export `actions/export_stock_monitoring.php` · JS: inline collapse-by-brand script (localStorage).

- Params: `date`, `branch` (owner only), `brand`, `q`.
- One SQL over `stock_movements` ⨝ products, grouped per product: opening (< day start), five period buckets (Stock In, Transfer In, Transfer Out, Sold, Other — see [[Owner Updates Plan]] Patch 2 note), ending (< day end). `HAVING` now retains active catalog products with stock history, including all-zero ending rows (2026-10-20 task, not yet live). Voided originals + `VOID-` restores are excluded entirely.
- Report `view`: `matrix` (owner + All Branches): KPI cards (Overall Units · Stock In Today · Transfer Today · Sold Today) + `Model × Branch columns × Overall` (ending only). `branch` (branch user, or owner with a branch picked): **Model | Opening | Stock In | Transfer | Sold | Other | Ending** — Transfer is one column (green +In / red −Out), Sold is one column per row (Units/Accessories split only in group + grand totals), Other hidden when every value is 0, brand groups start expanded with Expand all / Collapse all, plain-English column hints and in-transit box ([[Patch 2C - Simpler Stock Monitoring Table]], English since [[Patch 7 - English UI and Stock Monitoring Tabs]]). No KPI cards.
- In transit (sent, not received) is outside every branch ending; shown in Overall Units card / info alert / export header. `StockMonitoringReport::dayTotals()` and `::inTransit()` are reused by the Dashboard.
- Branch users are always forced to their branch (`Auth::branchId()`), owner is not.
- Topbar owner switcher is hidden on this page; the page Branch filter drives it. Export follows the same view rule.
- Fixed: [[BUG Stock Monitoring Owner Branch View]] (Patch 1, 2026-10-16).
Touched by plan: Patch 1, 2, 3, 4 ([[Owner Updates Plan]]).
- KPI cards (owner All Branches) are clickable → shared drawer (`assets/js/kpi-drawer.js` ← `actions/stock_monitoring_drawer.php` ← `StockMonitoringReport::drawer()`), following Date/Branch/Brand/Search. Details: [[Patch 2B - Clickable KPI Drawer]].
- Sold is split Units (phone/tablet) vs Accessories: table columns **Sold Units** | **Sold Accessories** (9 columns incl. export), Sold KPI shows both lines with ₱. Patch 3, 2026-10-17.
- Patch 2C (2026-10-17): table simplified; export follows the same columns; helpers `transferLabel()`, `soldSplitLabel()`, `hasOther()` live in `StockMonitoringReport`.
- **Patch 7 (2026-10-17) — tabs for ALL roles**: `?tab=accessories` (default `phones` = **Phones & Tablets**). Tab links sit between the filter card and the table; the tab survives Apply (hidden input), Reset, Export and the other filters. Both tabs use the same columns/brand groups/search/Date/Branch/Brand filters and a Grand Total for that tab only; owner All Branches matrix gets the same two tabs. Accessories never has a serial/IMEI column.
  - Data: `StockMonitoringReport::load()` still runs the same SQL over all products, then splits rows by type (`rowInTab`: accessory vs everything else, so a legacy `preloved` product type can never fall out of both tabs). It returns `totals`/`branch_totals` for the active tab and `overall_totals` (combined). KPI cards (owner matrix) use `overall_totals`, so their numbers did not change. `validTab()` falls back to `phones` for unknown values.
  - Sold is now one number per row/group/grand total (the tab already separates units from accessories); the Units/Accessories ₱ split stays in the owner Sold KPI card.
  - Export: active tab only; file `malbcoff-stock-monitoring-<phones-tablets|accessories>-<scope>-<date>.xlsx`, title `… - Phones & Tablets|Accessories`, worksheet named accordingly, a "Tab" cell in the header.
  - Filter bar: Apply / Reset / Export now sit in one `.stock-monitoring-actions` row (fixed overflow where Export took the Search column); all controls ≥44px, text ≥14px (also the owner matrix and KPI captions).
  - All UI text is English. Rules for future patches: `DESIGN.md` §15.
- **Scale (2026-10-17)**: one type/row scale for all views and both tabs — KPI number 24px (22px phone), KPI label/caption 13px muted, table text 14px, header 13px semibold, row height 44px, numbers right-aligned, 12px card gaps, tabs 44px/14px semibold, nothing under 12px. CSS only: the last block of `assets/css/app.css` overrides the older per-patch sizes. The shared drawer's small text is now ≥12px too.

## 2026-10-20 — done, not yet live
Previously stocked models remain at 0 with Out of stock in branch / owner selected branch / compare matrix and both tabs. All / In stock / Out of stock chips use `stock=all|in|out` (default All), shared with export and KPI drawer. CSV is available alongside Excel. Never-stocked / inactive catalog models are excluded. Branches without model history show a dash. Removal is a negative Other movement, preserving stock balance. Current catalog filtering is shared with Dashboard live stock/drawer. See [[BUG Stock Monitoring Hides Zero Stock Models]] for cause/tests and [[Deploy Package]] for files. All earlier patches are LIVE.
