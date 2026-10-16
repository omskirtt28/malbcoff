---
tags: [feature]
---
# Branch Transfers
Send: Inventory page → forward modal (`assets/js/inventory-forward.js`, `actions/forward_inventory.php`) → `inventory_transfers(pending)`, units `transferred`, movement `transfer_out` at source.
Receive: `pages/branch-transfers.php` + `actions/receive_transfer.php` → units `available` at destination, movement `transfer_in`, status `received`. Owner can view only (summary counts, list ≤250).
Touched by: Patch 2 (separate display from Stock In).
