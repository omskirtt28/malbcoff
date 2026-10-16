---
tags: [feature]
---
# Stock In (Receive Stock)
`pages/stock-in.php` (2953 lines ⚠, mostly inline JS) + `actions/stock_in.php`. Wizard: model → variant → qty & scan IMEI/serial → confirm. Writes `inventory_units` / `inventory_balances` + `stock_movements(stock_in)`. Route allowed: branch_manager, inventory only (owner NOT in `$pageRoles`, though code has owner branch select). Needs network-retry safety: Patch 6 ([[Owner Updates Plan]]).
