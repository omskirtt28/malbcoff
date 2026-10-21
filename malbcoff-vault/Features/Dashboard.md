---
tags: [feature]
---
# Dashboard
`pages/dashboard.php`. Owner: 6 KPI cards (Total Inventory, Phones, Tablets, Accessories, **Sales Today** ₱ + txn count, Sales This Month), Recent Sales list, Branch Inventory bars. Respects `current_branch_scope()` for inventory counts but **sales KPIs are always all-branch** (queries ignore `$scope`).
- Inventory counts: live `inventory_units(status=available)` + `inventory_balances`.
- Stock In / Transfer / Sold-units KPIs come from `StockMonitoringReport::dayTotals()` (same numbers as Stock Monitoring). Owner cards: Stock In Today, Transfer Today (units sent; shows received + In transit), Sold Today (units). Branch card Stock In Today uses the same Stock In bucket (no more transfers/returns lumped in).
- Owner cards Total Inventory (live), Stock In Today, Transfer Today, Sold Today open the shared KPI drawer ([[Patch 2B - Clickable KPI Drawer]]); the other cards are still plain `<article>`.
Touched by: Patch 2, 3, 4. See [[Owner Updates Plan]].
- Owner: **Units Sold Today** and **Accessories Sold Today** (qty + ₱, from `sale_items` via `StockMonitoringReport::soldByType()`) replace the old single Sold Today card; both open the KPI drawer. Patch 3, 2026-10-17.
- **Patch 4 (2026-10-17):** every owner card (Total Inventory, Phones, Tablets, Accessories, Sales Today, Sales This Month, Stock In, Transfer, Units/Accessories Sold) opens the shared KPI drawer. Sales Today / Sales This Month now follow the topbar branch selector and use PHP date boundaries (same as the drawer). See [[Patch 4 - Sold Today Clickable]].

## 2026-10-20 — done, not yet live
Live current-stock counts and branch summaries now share the active catalog predicate with Stock Monitoring and the live inventory drawer. Only available units count; deleted/archived units stay excluded. Sales amounts and saved sale-item snapshots remain intact. Actual Dashboard/Sales Records rendering and 18 reconciliation checks passed. See [[BUG Stock Monitoring Hides Zero Stock Models]].
All preceding patches are LIVE. See [[Deploy Package]] for pending files.
