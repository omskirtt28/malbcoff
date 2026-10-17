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
| `sales`* | cashier_name* (name snapshot), sale_no, branch_id, subtotal, total, payment_method, payment_reference, amount_received, change_due, status (completed/voided), created_by, created_at | |
| `sale_items`* | sale_id, product_id, **unit_id (NOT NULL = device, NULL = accessory)**, item/specs/identifier snapshots, quantity, unit_cost, unit_price (computed per piece), **line_total (source of truth)**, **price_entry_mode* (`piece`/`total`, NULL = sold before Patch 8)** | **immutable: trigger `trg_sale_items_price_final` blocks edits to quantity/prices** · split Units vs Accessories via `unit_id` or `products.product_type` |
| `stock_movements` | created_by_name* (name snapshot), product_id, unit_id, branch_id, movement_type, quantity (signed), reference_no, notes, unit_cost*, unit_selling_price*, created_by, created_at | **the ledger** |
| `inventory_transfers`* | forwarded_by_snapshot*, received_by_snapshot* (name snapshots), reference_no, product_id, source_branch_id, destination_branch_id, quantity, status (pending/received), forwarded_by/at, received_by/at, receiver_name | |
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

## Offline sales columns on `sales` (Patch 6c-2, migration P3_005)
`client_id` (UNIQUE; the device UUID = idempotency key; set for every sale made after the patch, NULL before), `is_offline` (1 = saved on a device first), `device_time` (device clock at Confirm, as reported), `received_at` (server time when accepted), `offline_receipt_no` (temporary `OFFLINE-<branch>-<date>-<n>`). For an offline sale `created_at` = device time (accepted only if not in the future and ≤ 7 days old, else server time) and its `sale_items.created_at` / `stock_movements.created_at` use the same value, so all reports agree. See [[Patch 6c-2 - Offline Sales Outbox]].

## outbox_reports (Patch 6c-1, migration P3_004)
Owner's "Needs Action" to-do list: one row per staff outbox item the server refused for good (`client_key` UNIQUE = the item's idempotency UUID, `user_id`/`branch_id` from the session, `action_type` stock_in | transfer_forward | transfer_receive | **sale** (6c-2, `details` up to 2000 chars after P3_005), `summary`, `details`, `reason`, `queued_at`, `staff_discarded`, `status` open|resolved, `resolved_by/at/note`). Never changes stock. See [[Patch 6c-1 - Offline Outbox for Stock Actions]]. The queued items themselves live only on the phone (IndexedDB), not in MySQL.

## Name snapshots
Records that only stored a user id also store the creator's name at creation time (`sales.cashier_name`, `stock_movements.created_by_name`, `inventory_transfers.forwarded_by_snapshot/received_by_snapshot`) so renaming a user never rewrites history. Filled by triggers from [[Migrations]] `P3_001`; read with `snapshot_name_sql()`.
