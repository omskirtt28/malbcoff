---
tags: [feature]
---
# Inventory
`pages/inventory.php` ⚠ (1200) grouped by product, View Units modal (`actions/product_units.php`, `variant_inventory.php`), forward (`forward_inventory.php`), owner adjust (`adjust_inventory.php`), delete (`delete_inventory_unit.php`, `delete_accessory_inventory.php`). Global JS in `assets/js/app.js`.
**Patch 6a:** stock adjustments (`adjust_inventory.php`) use `SafeSubmit.run` + key (System Admin/impersonation modals in `app.js` and `pages/inventory.php`).
