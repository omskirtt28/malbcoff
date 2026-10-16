---
tags: [feature]
---
# Stock Movement
`pages/stock-movement.php` — ledger list (limit 150), tabs: All / Stock In / Stock Out / Sold / **Transfer (= transfer_out only)** / Adjustment; `?movement=`. Branch scope via `current_branch_scope()`. System Admin can void outgoing rows (`actions/void_inventory_movement.php`, `assets/js/inventory-void.js`).
Touched by: Patch 2 (split Transfer In / Transfer Out tabs).
