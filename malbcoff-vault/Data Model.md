---
tags: [schema]
---
# Data Model

Full contract: `SCHEMA.md`. ⚠ `database/malbcoff_pos.sql` is the OLD base; columns/tables below marked *ext* come from migrations P1–P2 that are **not in this folder** (e.g. `P2_024_system_admin_inventory_void.sql`). Production DB already has them.

## Core tables
| Table | Key columns | Notes |
|---|---|---|
| `branches` | id, name, code, is_active | 4 branches |
| `users` | id, branch_id (NULL for owner), name, email, role, is_active, must_change_password*, password_changed_at*, last_login_at* | roles: owner, branch_manager, inventory, cashier, system_admin* |
| `brands` → `product_models` (device_type*) → `products` | product_type phone/tablet/accessory, ram, storage, connectivity*, color, barcode, cost_price, selling_price, is_active, catalog_deleted_at* | variant = 1 product row |
| `categories` | accessory categories |
| `inventory_units` | product_id, branch_id, imei, imei2*, serial_no, condition_type* (brand_new/preloved), acquisition_cost*, status | status: available, sold, transferred, adjusted_out*, defective, returned, reserved |
| `inventory_balances` | product_id, branch_id, quantity | accessories, UNIQUE(product,branch) |
| `branch_product_prices`* | product_id, branch_id, selling_price | |
| `sales`* | sale_no, branch_id, subtotal, total, payment_method, payment_reference, amount_received, change_due, status (completed/voided), created_by, created_at | |
| `sale_items`* | sale_id, product_id, **unit_id (NOT NULL = device, NULL = accessory)**, item/specs/identifier snapshots, quantity, unit_cost, unit_price, line_total | split Units vs Accessories via `unit_id` or `products.product_type` |
| `stock_movements` | product_id, unit_id, branch_id, movement_type, quantity (signed), reference_no, notes, unit_cost*, unit_selling_price*, created_by, created_at | **the ledger** |
| `inventory_transfers`* | reference_no, product_id, source_branch_id, destination_branch_id, quantity, status (pending/received), forwarded_by/at, received_by/at, receiver_name | |
| `inventory_transfer_units`* | transfer_id, unit_id | |
| `inventory_movement_voids`* | movement_id, sale_item_id, sale_id, restored_quantity, reason, voided_by | |
| `security_*`* | audit logs, login throttles, impersonation sessions | |

## movement_type meanings
| Type | Sign | Written by |
|---|---|---|
| `stock_in` | + | [[Stock In (Receive Stock)]] (`actions/stock_in.php`) |
| `transfer_out` | − | `forward_inventory.php` (at source, when sent) |
| `transfer_in` | + | `receive_transfer.php` (at destination, when received) |
| `sale` | − | `complete_sale.php` |
| `adjustment` | ± | `adjust_inventory.php`, accessory delete, **void restore (ref `VOID-…`)** |
| `defective`, `stock_out`, `return` | ± | adjustments |

**In transit:** between `transfer_out` and `transfer_in` the qty is in no branch's ledger. Count = `inventory_transfers WHERE status='pending'`.

Related: [[Architecture Map]] · [[Stock Monitoring]]
