---
tags: [bug, stock-monitoring, zero-stock]
status: done, not yet live
fixed: 2026-10-20
---
# BUG Stock Monitoring Hides Zero Stock Models

## Problem
Models disappear after the last unit is sold or sent out; branch staff and owner cannot see that a previously stocked model is now out of stock.

## Cause
StockMonitoringReport::load grouped the movement ledger but its HAVING required a nonzero opening, daily bucket or ending. After historical in/out quantities netted to zero and the chosen day had no movement, all values were zero and the row was discarded. Owner single-branch, compare matrix and export shared the same omission.

## Change
- Retain products with a positive stock/opening movement or sale history before the selected day ends, in that branch. No catalog-only product expansion: a never-stocked product stays absent. Inactive products, archived models/brands and catalog_deleted_at rows are excluded from current-stock reports.
- A current-stock catalog predicate is shared with Dashboard live inventory cards / branch summaries and their live drawer so retired catalog stock cannot inflate one screen only. Historical sales records and their money are preserved.
- Ending remains numeric 0 with a muted-red **Out of stock** label. Compare matrix shows 0 + label for branches with ledger history; an unstocked branch cell is a dash. Existing brand groups, number alignment, row scale and table pattern are retained (six baseline columns; existing conditional Other still shows removals when needed).
- **All / In stock / Out of stock** server-rendered chips (`stock=all|in|out`, default All) survive filters, Units/Accessories tabs and export. Availability is applied before row/group/grand totals and combined KPI totals. Existing combined Units + Accessories KPI convention stays intact; each active tab has its own totals.
- Shared drawer transports stock + monitoring scope and uses the identical selected product set. Dashboard uses its own existing report scope. Filtered table quantities, combined cards and drawer groups match.
- Excel export continues; **Export CSV** shares the same generated report rows, including zero rows and selected availability. No identifier column is added to Accessories.
- Deleted units are excluded from current stock through their -1 removal movement, while original stock and sale histories remain auditable. Formula: Opening + Stock In + Transfer In - Transfer Out - Sold + signed Other = Ending (Other includes negative Removed).

## How to test
1. Sell the last phone/tablet or transfer it out. Keep the model with Ending 0 in branch, owner selected-branch and owner All Branches matrix, including the following day when there is no activity.
2. Sell the last accessory: Accessories tab still shows 0 and Out of stock. No serial/IMEI column.
3. All includes zero and positive rows; In stock only positive ending; Out of stock only zero ending. Check totals, tab links, Apply and both export formats.
4. Never-stocked and inactive catalog models stay absent; branches without model history show no false out-of-stock cell.
5. Owner KPI drawer groups sum to the card total for every chip state and stock/sale/transfer kind. Units + Accessories table totals sum to combined KPI totals.
6. At 360 / 768 / 1280: numbers remain right-aligned, readable rows, chips >=44px, no horizontal page overflow; existing table wrapper may scroll internally.
7. Check stock balance, Dashboard current inventory, Stock Movement removals, and Sales Records quantity/amount with identical branch/date scope.

## Validation and status
**Done, not yet live.** Real Chrome and isolated local database tests passed for last-unit sale/transfer, older zero-only history, both tabs, owner branch/matrix, filters, CSV, drawer sums and responsive overflow. 18 reconciliation checks passed across two branches/all branches and actual Dashboard/Sales Records rendering. PHP/JS syntax checks passed. No hosting deployment or production data changes. See [[Deploy Package]]. All work before this task is LIVE per user confirmation.

Final local verification completed 2026-10-21 (task started 2026-10-20): 88 final browser checks and 18 reconciliation checks passed. New cleanup body/metadata typography checked at >=14px, controls >=44px. Hosting status remains **done, not yet live**.
