---
tags: [bug, stock-monitoring]
status: fixed
fixed: 2026-10-16 (Patch 1)
---
# BUG: Owner picks a branch → Stock Monitoring shows only that one branch

**Symptom (screenshot):** Owner chooses "Branch 1" → table has only `Branch 1` + `Overall Total` columns (same numbers, 6 units). Page says "compare across all branches" but can't.

## Root cause (3 places, same idea: branch filter is applied to the DATA, not just the highlight)
`core/StockMonitoringReport.php`
1. **L36-42** — `if ($isOwner && $branchId !== null) $displayBranches = [only that branch]` → the view gets one column.
2. **L50-53** — `$where[] = 'sm.branch_id = ?'` in the main query → rows/totals only for that branch.
3. **L113-130** — per-branch ending query again adds `sm.branch_id = ?` (L122-125), so `branch_ending` has only that branch, and **L142** `$totals['ending'] = array_sum($branchTotals)` makes "Overall Total" = that one branch.

Plus the UI trigger: `partials/topbar.php` (owner branch switcher, sets `?branch=` on every page, incl. stock-monitoring) and the page's own Branch dropdown (`pages/stock-monitoring.php` L72-82) both feed the same `branch` param. So two selectors both narrow the data.

Same filter leaks into `actions/export_stock_monitoring.php` (uses the same report).

## Also found (secondary)
- IN column lumps `stock_in` + `transfer_in` + `return` + positive `adjustment` (L66-70) — see [[Owner Updates Plan]] Patch 2.
- Void restores are written as `adjustment +` so they appear as "IN" and the original sale still shows in "SOLD" (L75). Net ending is correct; IN/SOLD are inflated on the void day.
- In-transit units (after `transfer_out`, before `transfer_in`) are in no branch column, so Overall Total can be lower than reality.
- Owner matrix only shows *ending* per branch; no opening/IN/OUT/SOLD per branch.

## Fix → see Patch 1 in [[Owner Updates Plan]]
Related: [[Stock Monitoring]] · [[Data Model]]
