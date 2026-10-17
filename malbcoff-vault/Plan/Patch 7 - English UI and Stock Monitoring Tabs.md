---
tags: [plan, patch]
---
# Patch 7 — English UI + Stock Monitoring tabs + design rules
**Status: DONE 2026-10-17.** No calculation or number changed.

## Part A — English only
All user-facing Tagalog/Taglish replaced with plain English (logic untouched):
- `pages/stock-monitoring.php`: Expand all / Collapse all, brand hint, column hints ("Stock at the start of the day"…), Transfer/Sold titles, the in-transit box ("N units were sent but not received yet…", "View in Branch Transfers").
- `assets/js/safe-submit.js`: Saving... / Saved ✓ / Not saved yet. ("Your internet is weak or offline. Nothing was lost.") / Not saved (+reason) / Retry / Go back and edit / OK / Log in (new tab) / weak-internet banner / "Please do not refresh or close this page." / "This request could not be saved."
- "The page did not load completely. Please refresh the page and try again." in `assets/js/app.js`, `assets/js/branch-transfers.js`, `assets/js/inventory-forward.js`, `pages/inventory.php`.
- `assets/js/pos.js`: "Sukli" → "Change"; comments in `pos.js` / `pages/stock-in.php` reworded.
- Not found in code (only in this brief): "Naka-pila", "Kailangan ng aksyon". Final grep of `pages partials actions core assets(js,css) *.php` for common Tagalog words: **no hits** (vendor libs excluded; vault notes are internal docs and stay as they are).

## Part B — Tabs
See [[Stock Monitoring]] (Patch 7 bullet). Files: `core/StockMonitoringReport.php` (`validTab`, `rowInTab`, `sumRows`, `tab`, `overall_totals`, `tab_rows`), `pages/stock-monitoring.php`, `actions/export_stock_monitoring.php`, `assets/css/app.css` (appended Patch 7 blocks).

## Part C — Design rules
`DESIGN.md` §15 "App Rules" (English-only, simple wording, mobile-first 360/768/desktop, 44px targets, ≥14px text, tab + table pattern, zero dim / non-zero bold, clickable KPI cards → shared drawer, Saving.../Saved/Not saved yet… pattern, owner and branch share views) + "Before finishing any patch" checklist. Linked from [[Conventions]] and [[00 Start Here]]. (`DESIGN.md` already existed, so no separate vault file was created.)

## Checks done
- [x] Tab totals add up to the combined totals (opening, stock in, transfer in/out, sold, other, ending) for 3 dates, branch 1, owner matrix and branch view (CLI script).
- [x] Owner viewing branch 1 and the branch user get identical rows and totals on both tabs.
- [x] Export matches the active tab (owner matrix, owner branch, branch user × both tabs); Accessories export has no serial column.
- [x] No PHP warnings/notices rendering the page as owner and branch user (7 URL variants); `php -l` clean.
- [x] Headless Chrome at 360 / 768 / 1280: no horizontal page scroll, tab and filter targets ≥44px, text ≥14px on the monitoring page.
- Local test data is small (11 units, no movements today), so non-zero Sold/Transfer cells were verified only by the arithmetic checks, not visually.
