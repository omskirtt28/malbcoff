---
tags: [feature]
---
# Stock Movement
`pages/stock-movement.php` — ledger list (limit 150), tabs: All | Stock In | Transfer In | Transfer Out | Sold | Other (same buckets as [[Stock Monitoring]]; Other = adjustments, stock out, defective, void restorations); `?movement=`. Branch scope via `current_branch_scope()`. System Admin can void outgoing rows (`actions/void_inventory_movement.php`, `assets/js/inventory-void.js`).
Done in Patch 2 (2026-10-17). `movement_label()` now returns Transfer In / Transfer Out.
