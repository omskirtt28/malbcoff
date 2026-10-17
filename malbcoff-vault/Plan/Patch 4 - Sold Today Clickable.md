---
tags: [plan, patch]
---
# Patch 4 — Remaining Dashboard KPI cards clickable (drawer)
**Status: DONE 2026-10-17.** Supersedes the original "Sold Today → link to Sales Records" idea: all remaining owner Dashboard cards now open the shared drawer from [[Patch 2B - Clickable KPI Drawer]] (no second drawer / endpoint).

## What changed
- `pages/dashboard.php` — Total Inventory (already live drawer), **Phones Available**, **Tablets Available**, **Accessories Stock**, **Sales Today**, **Sales This Month** get `data-kpi-drawer` (+ `data-basis=live`, `data-type=phone|tablet|accessories`). Stock In / Transfer / Units Sold / Accessories Sold cards unchanged.
- `core/StockMonitoringReport.php` — `drawer()` new kinds `sales_today`, `sales_month` (private `salesDrawer()`); `overallLive()` takes a type (phone/tablet/accessories, bound parameter) so the live drawers' totals equal the cards.
- `assets/js/kpi-drawer.js` — money totals, "View in Sales Records" link, per-transaction / per-day rows, `sales_*` entry layout. `assets/css/app.css` — appended link/tap-target styles (44px chips, tabs, close, search, links).
- `actions/stock_monitoring_drawer.php` — no code change (kinds are validated in the report; still GET-only, owner-only: 401 / 403 / 405 / 422).
- `pages/sales-records.php` — no change needed; it already accepts `from`, `to`, `branch`.

## Behaviour
- **Inventory drawers** (live, same queries as the cards): models with available stock split per branch, branch chips, search, IMEI/serial collapsed under each model, total at top == card.
- **Sales Today**: today's completed sales (receipt no. → Sales Records detail, branch, amount, payment, cashier, time) + "View in Sales Records" = `?from=today&to=today[&branch=N]`.
- **Sales This Month**: completed sales from the 1st → today grouped by day with a day subtotal; month total == card; link `?from=month-start&to=today[&branch=N]`.
- Voided sales (`status='voided'`) are excluded everywhere; last month's sales are excluded.
- **Branch selector scopes the drawer.** To make "drawer total == card" true under a branch scope, the **Sales Today / Sales This Month cards now follow the topbar branch selector too** (before, they were always all-branch) and use the same PHP date boundaries as the drawer instead of MySQL `CURDATE()`. "Recent Sales" list is still all-branch.

## Acceptance
- [x] Every drawer total == its card for scopes All, B1, B2, B3, B4 (script run with fixtures: voided sale, last-month sale, several branches).
- [x] Branch selector changes the drawer (chips refetch; page scope pre-selects the chip).
- [x] Esc / X / backdrop close, Enter/Space open, focus trapped + restored (headless Chrome, 168 checks at 360 / 768 / 1280 widths, no JS errors).
- [x] Branch users and signed-out requests cannot call the endpoint (403 / 401); branch dashboard has no drawer markup.
- [x] Sales Records links open pre-filtered and their totals equal the drawer.
- [x] Responsive: no horizontal page scroll; drawer full-width on phone (≤600px); tap targets ≥44px (receipt link fixed from 39px).