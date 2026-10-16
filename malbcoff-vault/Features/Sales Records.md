---
tags: [feature]
---
# Sales Records
`pages/sales-records.php` (no action file; read-only). Filters via GET: `from`,`to` (default month start→today), `branch` (owner), `payment`, `q`, `p`, `sale` (detail). Cards: Filtered Sales, Transactions, **Items Sold** (single number, units+accessories mixed). Owner: "Sales by Branch" cards (click → filter branch). Detail uses `sale_items` snapshots; voided items flagged.
Already supports deep links like `sales-records?from=2026-10-16&to=2026-10-16&branch=1` → use for drill-down ([[Owner Updates Plan]] Patch 4).
Touched by: Patch 3, 4.
