---
tags: [feature]
---
# Dashboard
`pages/dashboard.php`. Owner: 6 KPI cards (Total Inventory, Phones, Tablets, Accessories, **Sales Today** ₱ + txn count, Sales This Month), Recent Sales list, Branch Inventory bars. Respects `current_branch_scope()` for inventory counts but **sales KPIs are always all-branch** (queries ignore `$scope`).
- Inventory counts: live `inventory_units(status=available)` + `inventory_balances`.
- stock_in_today lumps `stock_in+transfer_in+return`; stock_out_today lumps sale+transfer_out+stock_out+defective (branch view only shows stock-in card).
- KPI cards are plain `<article>` — **not clickable**.
Touched by: Patch 2, 3, 4. See [[Owner Updates Plan]].
