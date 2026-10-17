---
tags: [plan, patch]
---
# Patch 2C — Simpler, clearer Stock Monitoring table
**Status: DONE 2026-10-17.** Presentation only — **no calculation or number changed** (same report query, same totals).

## Branch table (owner with a branch selected + branch users)
`Model | Opening | Stock In | Transfer | Sold | Other | Ending`
- **Transfer** = one column: Transfer In green `+2`, Transfer Out red `−1`, both when both exist (`+2 / −1`), dim `0` otherwise.
- **Sold** = one column per model row (units + accessories of that row). The Units/Accessories split is only in group totals and the Grand Total (`Units 3 · Accessories 5`). KPI cards (owner All Branches) from [[Patch 3 - Units and Accessories Sold Separate]] unchanged.
- **Other** column is removed when no row has a non-zero value (`StockMonitoringReport::hasOther()`); otherwise shown (+green / −red) with the hint "Adjustment at iba pa".
- Brand groups **start expanded** every time (branch view no longer remembers collapsed state; the owner matrix still does). Buttons **Buksan lahat / Isara lahat**; brand rows are real buttons (Enter/Space); group total rows stay visible when collapsed.
- Zeros dimmed, non-zero bold/coloured (Stock In green, Transfer in/out green/red, Sold amber, Other by sign), **Ending** emphasised.
- Every column header is a button with a Taglish hint (hover, keyboard focus, or tap; Esc closes): Opening "Stock sa simula ng araw", Stock In "Bagong dating galing supplier", Transfer "Galing/papunta sa ibang branch…", Sold "Naibenta", Other "Adjustment at iba pa", Ending "Stock sa dulo ng araw".
- **In-transit box** (single-branch view): "May N unit na ipinadala pero hindi pa natatanggap. Hindi pa ito kasama sa stock ng kahit anong branch. (x papunta sa branch na ito, y galing dito.)" + button "Tingnan sa Branch Transfers" (`branch-transfers?status=pending`).
- Owner All-Branches **matrix is untouched**.
- Responsive: sticky Model column, table scrolls inside its container on phones (no page scroll at 360/768/1280), text ≥14px, tap targets ≥44px.
- **Excel export** follows the same columns (Transfer as text `+2 / −1`, Sold one column, Other only when it has data, split text in group/grand totals).

## Files
`pages/stock-monitoring.php`, `core/StockMonitoringReport.php` (only three tiny formatting helpers: `transferLabel`, `soldSplitLabel`, `hasOther`), `actions/export_stock_monitoring.php`, `assets/css/app.css` (appended).

## Acceptance (checked against the report numbers on 12 date/branch/brand combos + headless Chrome at 360/768/1280)
- [x] Every cell, group total and grand total equals the report values for the same date/branch (22–26 checks per page).
- [x] Grand total Units + Accessories == old total sold.
- [x] Other column hidden when all zero (e.g. 2026-10-17), shown when data exists (2026-09-19).
- [x] Keyboard: Enter collapses / Space expands a brand, totals stay visible; Buksan/Isara lahat; hints on focus and tap.
- [x] No horizontal page scroll, sticky first column, ≥14px text, ≥44px targets, no console errors.
> **Patch 7 (2026-10-17):** all wording above is now English (hints, buttons, in-transit box). Sold is one number everywhere; the Units/Accessories split moved to the tabs. See [[Patch 7 - English UI and Stock Monitoring Tabs]].
