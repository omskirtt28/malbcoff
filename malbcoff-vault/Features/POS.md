---
tags: [feature]
---
# POS
`pages/pos.php` + `assets/js/pos.js` (cart in JS) → `actions/pos_search.php` (fetch JSON, no timeout/retry) → checkout is a **plain `form.submit()`** to `actions/complete_sale.php` (re-reads price/stock, inserts sales, sale_items, stock_movements in one transaction, then redirect + flash). `sale_no` from `generate_sale_reference()`.
**Weak-net risks:** no idempotency key → double-tap/retry can double-sell; fetch has no timeout; page reload loses cart state. Fix in Patch 6.
Roles: branch_manager, cashier only.
