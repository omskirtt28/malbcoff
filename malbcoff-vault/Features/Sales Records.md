---
tags: [feature]
---
# Sales Records
`pages/sales-records.php` (no action file; read-only). Filters via GET: `from`,`to` (default month start→today), `branch` (owner), `payment`, `q`, `p`, `sale` (detail). Filter `type=units|accessories` (Item Type). Cards: Filtered Sales, Transactions, **Units Sold** and **Accessories Sold** (qty + ₱ each; Patch 3 — no more single Items Sold). Owner: "Sales by Branch" cards (click → filter branch; show Units / Accessories counts). Detail uses `sale_items` snapshots; voided items flagged.
Already supports deep links like `sales-records?from=2026-10-16&to=2026-10-16&branch=1` → use for drill-down ([[Owner Updates Plan]] Patch 4).
Patch 3 done 2026-10-17; Patch 4 still to do.
**Patch 6c-2 (2026-10-17):** sales that were saved on a cashier's device and sent later are marked **Saved offline · OFFLINE-…** under the sale number; the date/time shown is the device time (`created_at`). Sale detail adds "Saved offline — Receipt …" for everyone and, **owner only**, "Made on device" + "Received by server". The page reads the new columns only when migration P3_005 is imported. See [[Patch 6c-2 - Offline Sales Outbox]].
- Dashboard drawers link here with `from`, `to` (+ `branch`) — e.g. Sales Today → `from=to=today`, Sales This Month → `from=month start`.
