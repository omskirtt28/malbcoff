---
tags: [plan, patch]
---
# Patch 3 — Units sold vs Accessories sold

Definition: **Units** = `products.product_type IN ('phone','tablet')` (sale_items.unit_id NOT NULL); **Accessories** = `product_type='accessory'`. (Pre-loved is a unit condition, not separate type.)

## Files
- `core/StockMonitoringReport.php` — sold split: `sold_units`, `sold_accessories` per row/branch/total (use `p.product_type` already joined).
- `pages/stock-monitoring.php` — "Sold" KPI shows two lines (Units / Accessories); branch detail Sold column split or two columns.
- `pages/dashboard.php` — owner: "Units Sold Today" + "Accessories Sold Today" (qty + ₱). Query: `sale_items si JOIN sales s JOIN products p WHERE s.status='completed' AND DATE(s.created_at)=CURDATE() AND <active item sql>` grouped by `p.product_type='accessory'`.
- `pages/sales-records.php` — replace single **Items Sold** card with **Units Sold** and **Accessories Sold** (qty and ₱ `SUM(line_total)`); add filter `type=units|accessories` (applied with `EXISTS (SELECT 1 FROM sale_items … )` on the list and to item totals); Sales by Branch cards show units/accessories counts.
- `assets/css/app.css` — append only (2-up KPI).

## Acceptance
- [ ] Sell 1 phone + 3 chargers → Units 1, Accessories 3 everywhere (Dashboard, Sales Records, Monitoring).
- [ ] Voided item removed from both counts.
- [ ] `type=accessories` filter lists only receipts containing accessories; totals follow.
- [ ] Date range + branch filters still work; sum(units+accessories) = old Items Sold.
