---
tags: [plan, patch]
---
# Patch 4 — "Sold Today" drill-down

## Files
- `pages/dashboard.php` — turn Sales Today / Units Sold Today / Accessories Sold Today cards into `<a class="kpi-card …">` → `app_url('sales-records',['from'=>today,'to'=>today] + branch + type)`. Add `:hover`/focus styles.
- `pages/stock-monitoring.php` — Sold KPI card → same link using the page's `date` (from=to=selected date, `branch` if focused, `type`).
- `pages/sales-records.php` — already reads from/to/branch/payment; add `type` (from Patch 3) and show a "Showing: Today · Branch 1 · Units" chip with Clear link. Optionally open first transaction detail via existing `sale=` param.
- `assets/css/app.css` — card link styling (append).

## Acceptance
- [ ] Click Sales Today on Dashboard → Sales Records for today, all branches, count matches card.
- [ ] Click Sold on Monitoring (date 10/15, Branch 2) → from=to=10/15, branch=2.
- [ ] Keyboard focusable, works on mobile (whole card tappable).
- [ ] Branch users unaffected (their cards link inside own branch only).
