---
tags: [feature]
---
# Branch Transfers
## FIX 8C-2 — 2026-10-21 — done, not yet live
No-sales deleted/archived units may be permanently removed. Keep every inventory_transfers parent (quantity/status/dates unchanged) and every inventory_transfer_units line: NULL its unit_id and save deleted_unit_id + unit_snapshot JSON (label, all identifiers, condition). transfer_details.php uses LEFT JOIN and reads the snapshot; branch-transfers.js shows Unit deleted. Sales archives read identifiers from archived_* fields. Counts on this page come from parent transfers; pending quantities in Stock Monitoring also use parent quantity, not the number of joined units. Pending lines with a permanently deleted unit stay pending; receiving refuses the request atomically instead of manufacturing live stock. Already received transfers remain readable. All earlier work is LIVE.

Send: Inventory page → forward modal (`assets/js/inventory-forward.js`, `actions/forward_inventory.php`) → `inventory_transfers(pending)`, units `transferred`, movement `transfer_out` at source.
Receive: `pages/branch-transfers.php` + `actions/receive_transfer.php` → units `available` at destination, movement `transfer_in`, status `received`. Owner can view only (summary counts, list ≤250).
Touched by: Patch 2 (separate display from Stock In).
**Patch 6c-1:** both Transfer create (forward) and Transfer receive can be saved on the device when the network drops and are sent later, in order; receiving the same transfer twice while one is queued is refused as "Already saved on this device"; `?open=<transfer id>` on this page opens that transfer (used by the Outbox "Review" button). A refusal ("already received", "units no longer available") becomes Needs action for the owner. See [[Patch 6c-1 - Offline Outbox for Stock Actions]].
**Patch 6a:** Transfer create (`inventory-forward.js`/`app.js` → `forward_inventory.php`) and Transfer receive (`branch-transfers.js` → `receive_transfer.php`) use `SafeSubmit.run` with a key; replays return the first transfer / receipt — no duplicate transfer, no double stock movement.
