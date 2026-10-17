---
tags: [plan, patch]
---
# Patch 2B — Clickable KPI cards with a right-side drawer (Owner)
**Status: DONE 2026-10-17**

## Files
- `core/StockMonitoringReport.php` — `drawer()` (data for all 4 kinds), `productFilter()` (shared Brand/Search SQL, now also used by `load()`), `dayTotals()` gained optional brand/search.
- `actions/stock_monitoring_drawer.php` (new) — read-only GET JSON endpoint. 401 not signed in · 405 not GET · **403 non-owner** · 422 bad `kind` · 500 generic (logged via `Security::reportException`). Prepared statements only; `Cache-Control: no-store`.
- `assets/js/kpi-drawer.js` (new) — ONE shared drawer; cards opt in with `data-kpi-drawer`.
- `assets/css/app.css` — appended `.is-clickable` hover/focus + `.kpi-drawer*` styles (full width ≤600px).
- `pages/stock-monitoring.php`, `pages/dashboard.php` — `data-kpi-drawer / data-date / data-branch / data-brand / data-q / data-basis` on the owner cards + script include.

## How it works
- Card → `kind`: Overall Units = `overall`, Stock In Today = `stock_in`, Transfer Today = `transfer` (tabs Out/In), Sold Today = `sold`.
- Drawer follows the **applied** page filters (Date, Branch, Brand, Search) via the card's data attributes; changing the date reloads the page, so the next click uses the new date. Branch chips (All, Branch 1..n) refetch with that branch; search box filters client-side; model rows expand to entries + IMEI/serial per unit.
- **Totals == cards**: `total` comes from `dayTotals()` (same bucket SQL as the cards); group qty sums are asserted equal in tests.
- Entries: Stock In = branch, qty, ref (`reference_no`), note (supplier text typed at receive), time, received by. Transfer = from → to, qty, status (Pending/Received), time, ref. Sold = branch, qty, amount (`sale_items.line_total`), cashier, receipt no. linking to `sales-records?sale=ID&from=D&to=D#sale-detail`. Overall = per branch qty + units.
- **Units**: movement `unit_id`, or for an unlinked multi-unit stock-in the units created by the same user/product within 3 s — accepted only if the count equals the quantity; otherwise "N unit(s) without a recorded IMEI/serial". Overall (ledger) rebuilds per-unit stock from the ledger as of the date; verified identical to live `inventory_units` for today.
- **Dashboard "Total Inventory"** is a *live* number (available units + accessory balances), so its drawer uses `basis=live` to equal the card; Monitoring's "Overall Units" is the ledger at end of selected date.
- Only the owner's All-Branches Monitoring view has KPI cards (Patch 1), so branch users / branch view are unchanged; JS is only included for the owner.

## Acceptance
- [x] Each drawer total equals its card for the same date + branch (458 automated checks across 6 dates × 5 branch scopes × 3 filter sets, plus a headless-Chrome run of the real JS on both pages).
- [x] Date change updates the drawer (checked with date=today and date=2026-10-06).
- [x] Esc, X, backdrop close; Enter/Space open; focus is moved in, trapped, and restored to the card.
- [x] Branch user (and anonymous) cannot call the endpoint (403 / 401).
- [x] No PHP warnings, no JS console errors.
- Cards stay non-links; the "Sold Today → Sales Records filtered list" idea of [[Patch 4 - Sold Today Clickable]] is partly covered (drawer lists today's receipts with links); Patch 4 still needed for the Sales Today ₱ card and a filtered Sales Records page.

Related: [[Stock Monitoring]] · [[Dashboard]] · [[Owner Updates Plan]]
