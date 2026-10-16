---
tags: [plan, patch]
---
# Patch 2 — Stock In and Transfer shown separately (Owner)

## Files
- `core/StockMonitoringReport.php` — split `in_qty` into `stock_in_qty` (type `stock_in`, `return`), `transfer_in_qty`; split `out_qty` into `transfer_out_qty` and `other_out_qty` (stock_out/defective/adjustment). Keep `in_qty`/`out_qty` totals for old callers.
- `pages/stock-monitoring.php` — KPI cards: **Stock In**, **Transfer In**, **Transfer Out**, **Sold** (owner). Branch detail table columns: Opening | Stock In | Transfer In | Transfer Out | Sold | Other Out | Ending.
- `pages/dashboard.php` — `stock_in_today` only `stock_in`; add `transfer_in_today`, `transfer_out_today`; show as separate KPI cards for owner (all branches or focus).
- `pages/stock-movement.php` — tabs: All | Stock In | Transfer In | Transfer Out | Sold | Stock Out | Adjustment (currently "Transfer" = transfer_out only).
- `pages/branch-transfers.php` — owner summary already separate; only add per-branch In/Out counts if cheap (optional).
- `actions/export_stock_monitoring.php` — new columns.

## Notes
- No schema change. Movement types already distinct ([[Data Model]]).
- Void restores (`reference_no LIKE 'VOID-%'`, type `adjustment +`) should NOT count as Stock In: classify as "Void restore" or exclude from IN, and exclude voided originals from SOLD using `inventory_active_movement_sql()` where voids table exists.

## Acceptance
- [ ] Receive 2 units at Branch A → Stock In +2 (Transfer In 0).
- [ ] Forward 1 to Branch B → A: Transfer Out 1; after B receives → B: Transfer In 1, Stock In unchanged.
- [ ] While pending: shows in In-transit, not in either branch ending.
- [ ] Dashboard owner cards match monitoring for same day/scope.
- [ ] Stock Movement has separate Transfer In and Transfer Out tabs with correct rows.
