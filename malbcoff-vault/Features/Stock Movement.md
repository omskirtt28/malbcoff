---
tags: [feature]
---
# Stock Movement
## FIX 8C-2 — 2026-10-21 — done, not yet live
No-sales unit cleanup converts its stock_movements rows to snapshots: unit_id=NULL, deleted_unit_id=old id, unit_snapshot=product label/identifiers. No quantity, movement type, date, cost, reference or movement id changes. This preserves Opening + Stock In + Transfer In - Transfer Out - Sold + Other = Ending for every historical period and branch; deleting rows would erase genuine receiving/transfer activity. Stock Movement displays Unit deleted and its identifier; the shared drawer reads the same snapshot and never guesses it as a new unlinked batch. Removed history is denied by the Void endpoint and has no selectable checkbox. Sales/void units keep all original movement references. All earlier work is LIVE.

`pages/stock-movement.php` — ledger list (limit 150), tabs: All | Stock In | Transfer In | Transfer Out | Sold | Other (same buckets as [[Stock Monitoring]]; Other = adjustments, stock out, defective, void restorations); `?movement=`. Branch scope via `current_branch_scope()`. System Admin can void outgoing rows (`actions/void_inventory_movement.php`, `assets/js/inventory-void.js`).
Done in Patch 2 (2026-10-17). `movement_label()` now returns Transfer In / Transfer Out.

## 2026-10-20 — done, not yet live
Other is now labelled Other / Removed. Branch deletion records -1 Removed from inventory; history and archived identifiers remain readable. These removal entries are not eligible for Void. Signed Other includes the negative removal, so Opening + In - Out - Sold + Other = Ending. See [[BUG Deleted Inventory Units and Blocked Identifiers]].
All preceding patches are LIVE. See [[Deploy Package]] for pending files.
