---
tags: [feature]
---
# Branch Transfers
Send: Inventory page → forward modal (`assets/js/inventory-forward.js`, `actions/forward_inventory.php`) → `inventory_transfers(pending)`, units `transferred`, movement `transfer_out` at source.
Receive: `pages/branch-transfers.php` + `actions/receive_transfer.php` → units `available` at destination, movement `transfer_in`, status `received`. Owner can view only (summary counts, list ≤250).
Touched by: Patch 2 (separate display from Stock In).
**Patch 6c-1:** both Transfer create (forward) and Transfer receive can be saved on the device when the network drops and are sent later, in order; receiving the same transfer twice while one is queued is refused as "Already saved on this device"; `?open=<transfer id>` on this page opens that transfer (used by the Outbox "Review" button). A refusal ("already received", "units no longer available") becomes Needs action for the owner. See [[Patch 6c-1 - Offline Outbox for Stock Actions]].
**Patch 6a:** Transfer create (`inventory-forward.js`/`app.js` → `forward_inventory.php`) and Transfer receive (`branch-transfers.js` → `receive_transfer.php`) use `SafeSubmit.run` with a key; replays return the first transfer / receipt — no duplicate transfer, no double stock movement.
