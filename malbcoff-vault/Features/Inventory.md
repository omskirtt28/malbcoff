---
tags: [feature]
---
# Inventory
`pages/inventory.php` ⚠ (1200) grouped by product, View Units modal (`actions/product_units.php`, `variant_inventory.php`), forward (`forward_inventory.php`), owner adjust (`adjust_inventory.php`), delete (`delete_inventory_unit.php`, `delete_accessory_inventory.php`). Global JS in `assets/js/app.js`.
**Patch 6a:** stock adjustments (`adjust_inventory.php`) use `SafeSubmit.run` + key (System Admin/impersonation modals in `app.js` and `pages/inventory.php`).

## Inventory soft deletion — LIVE (confirmed 2026-10-21)
Branch Delete is soft (`deleted` + metadata), implemented by InventoryDeletion. No stock-in/history rows are erased; one Removed from inventory adjustment decreases stock. Adjustments formerly adjusted_out use the same service; defective/returned stay separate.

## FIX 8C-2 — done, not yet live
Only direct System Admin can permanently clean removed units. Only sale-item/void history requires archival with all live identifiers NULL. No-sales rows (deleted or previously archived) can be physically removed regardless of transfers/movements; write the audit first and detach transfer/movement rows into snapshots in one transaction. Global Delete all with no sales previews all branches/pages and requires DELETE. This supersedes the former any-reference archive rule. Scanner uniqueness stays unchanged. See [[BUG Deleted Inventory Units and Blocked Identifiers]] and [[Deploy Package]].
