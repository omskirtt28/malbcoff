---
tags: [plan, patch]
---
# Patch 2 — Stock In and Transfer shown separately (Owner)
**Status: DONE 2026-10-17** (see implementation notes at bottom)

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
- [x] Receive 2 units at Branch A → Stock In +2 (Transfer In 0).
- [x] Forward 1 to Branch B → A: Transfer Out 1; after B receives → B: Transfer In 1, Stock In unchanged.
- [x] While pending: shows in In-transit, not in either branch ending.
- [x] Dashboard owner cards match monitoring for same day/scope.
- [x] Stock Movement has separate Transfer In and Transfer Out tabs with correct rows.

## Implementation notes (2026-10-17)
Final spec from John replaced the file plan above:
- **Buckets** (one place: `StockMonitoringReport::bucketConditions()`): Stock In = `stock_in`/`return` with qty>0 (not `VOID-`); Transfer In = `transfer_in`; Transfer Out = `transfer_out`; Sold = `sale` qty<0; **Other = signed net of everything else** (adjustments, stock_out, defective...). Every ledger row is in exactly one bucket, so Opening + Stock In + Transfer In − Transfer Out − Sold + Other = Ending always.
- **Voids**: `StockMonitoringReport::validMovementSql()` drops voided originals (`inventory_movement_voids`) AND their `VOID-` restore rows from opening/columns/ending. They net to zero, so a voided sale is never Sold, a restore is never Stock In, and past days are restated as if the mistaken sale never happened. They still show in Stock Movement (Sold tab as Voided, restore under Other).
- **In transit** = `inventory_transfers` forwarded before end of day and (still `pending` OR received after end of day); `cancelled` ignored. Not in any branch ending. Shown as a note in Overall Units card (owner All Branches), an info alert (branch view), and an `In transit` cell in the export header row. Matrix columns unchanged (ending per branch).
- **KPI cards**: Overall Units · Stock In Today · Transfer Today (= units *sent*, small text shows received count) · Sold Today. Dashboard owner got Stock In Today / Transfer Today / Sold Today (units) via `StockMonitoringReport::dayTotals()` — same code as Monitoring, so numbers match. Cards are NOT clickable (Patch 4).
- **Stock Movement tabs**: All | Stock In | Transfer In | Transfer Out | Sold | Other. Old `?movement=stock_out|adjustment|return|defective` links still filter by raw type. `movement_label()` (core/helpers.php) now says Transfer In / Transfer Out.
- Old `in_qty`/`out_qty` keys removed from the report (no other callers).
- Verified (rolled-back test rows): +2 Stock In at A (Transfer In 0); A→B pending = A Transfer Out 1, in transit 1, B/overall ending unchanged; after receive B Transfer In 1, Stock In unchanged; void pair excluded; dashboard == monitoring; matrix Grand Total == sum of columns; branch user ignores `?branch=`.
- `pages/branch-transfers.php` optional per-branch counts: skipped.
