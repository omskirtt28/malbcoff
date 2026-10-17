---
tags: [plan, patch]
---
# Patch 3 — Units sold vs Accessories sold
**Status: DONE 2026-10-17** (implementation notes at bottom)

Definition: **Units** = `products.product_type IN ('phone','tablet')` (sale_items.unit_id NOT NULL); **Accessories** = `product_type='accessory'`. (Pre-loved is a unit condition, not separate type.)

## Files
- `core/StockMonitoringReport.php` — sold split: `sold_units`, `sold_accessories` per row/branch/total (use `p.product_type` already joined).
- `pages/stock-monitoring.php` — "Sold" KPI shows two lines (Units / Accessories); branch detail Sold column split or two columns.
- `pages/dashboard.php` — owner: "Units Sold Today" + "Accessories Sold Today" (qty + ₱). Query: `sale_items si JOIN sales s JOIN products p WHERE s.status='completed' AND DATE(s.created_at)=CURDATE() AND <active item sql>` grouped by `p.product_type='accessory'`.
- `pages/sales-records.php` — replace single **Items Sold** card with **Units Sold** and **Accessories Sold** (qty and ₱ `SUM(line_total)`); add filter `type=units|accessories` (applied with `EXISTS (SELECT 1 FROM sale_items … )` on the list and to item totals); Sales by Branch cards show units/accessories counts.
- `assets/css/app.css` — append only (2-up KPI).

## Acceptance
- [x] Sell 1 phone + 3 chargers → Units 1, Accessories 3 everywhere (Dashboard, Sales Records, Monitoring).
- [x] Voided item removed from both counts.
- [x] `type=accessories` filter lists only receipts containing accessories; totals follow.
- [x] Date range + branch filters still work; sum(units+accessories) = old Items Sold.

## Implementation notes (2026-10-17)
- **Definition** lives in one place: `StockMonitoringReport::UNITS_SQL` (`p.product_type IN ('phone','tablet')`) and `ACCESSORIES_SQL` (`= 'accessory'`). Every page uses them. (`products.product_type` enum also has `preloved`, but no such products exist; if one appears it would be missing from both — the old total is exposed as `all_qty` on Sales Records for that check.)
- **Sources**: Dashboard + Sales Records use `sale_items` (qty and `line_total` = peso) of `completed` sales, excluding item-level voids via `inventory_active_sale_item_sql()`; `StockMonitoringReport::soldByType()` is the shared helper. Stock Monitoring columns/KPI qty come from the ledger (`sold_units_qty`, `sold_accessories_qty`; voided originals + `VOID-` restores already excluded) and the KPI peso lines from `soldByType()`. Tested equal for several dates/branches.
- **Stock Monitoring**: Sold column split into **Sold Units** | **Sold Accessories** (9 columns); Sold KPI shows total + "Units n · ₱" and "Accessories n · ₱" lines; Excel export has the same two columns.
- **Dashboard (owner)**: "Sold Today" replaced by **Units Sold Today** and **Accessories Sold Today** (qty + ₱); both open the KPI drawer (`data-type=units|accessories`, drawer `type` param) and follow the topbar branch scope. Grid class `owner-dash-kpis`: 5 cols desktop, 2 tablet, 1 phone.
- **Sales Records**: KPI row = Filtered Sales, Transactions, **Units Sold**, **Accessories Sold** (qty + ₱); new **Item Type** filter `type=units|accessories` (`EXISTS` active item of that type) applied to the list, Transactions, Filtered Sales, the item cards and Sales-by-Branch. With a type filter the item cards describe the *listed receipts* (an accessories receipt that also holds a phone still counts that phone under Units). Branch cards show "Units n · Accessories n"; the Items column shows "n unit(s) · n acc.". Branch users still pinned to their own branch.
- **Responsive (360 / 768 / 1280 / 1440)**: no horizontal page scroll; KPI/branch cards 1 col phone, 2 tablet; tap targets ≥44px (filters, Apply/Reset, View buttons, heading buttons, quick-search input). Also fixed a pre-existing bug: on phones the Sales Records transaction table was clipped (only Date + Sale No. visible); it now scrolls inside its container.
- Verified with fixtures rolled back afterwards: 1 phone + 3 chargers in one sale → Units 1 / Accessories 3 on Dashboard, Sales Records and Monitoring; voiding the chargers → Accessories 0 everywhere; `type=accessories` lists only receipts with accessories; branch user sees only own branch.