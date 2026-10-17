---
tags: [schema]
---
# Migrations
Files live in `database/migrations/` (blocked from the web by `.htaccess`). Import with phpMyAdmin or `mysql -u USER -p DB < file.sql`. Every file must be **re-runnable**.

| File | Purpose | Notes |
|---|---|---|
| `P3_001_user_name_snapshots.sql` | Patch 5: adds `sales.cashier_name`, `stock_movements.created_by_name`, `inventory_transfers.forwarded_by_snapshot` / `received_by_snapshot`; backfills NULL rows with the user's current name; creates 4 `BEFORE INSERT/UPDATE` triggers that fill the snapshot at creation time | Needs `TRIGGER` privilege. Readers fall back to live `users.name` (`snapshot_name_sql()`), so the app works before it is imported. |

See [[Patch 5 - Edit Account]] · [[Data Model]] · [[Conventions]]
| `P3_002_idempotency_keys.sql` | Patch 6a: creates `idempotency_keys` (UNIQUE `client_key`, action, user_id, request_hash, reference_no, result_json) used for safe retries | `CREATE TABLE IF NOT EXISTS`; re-runnable. Old rows can be deleted after ~30 days. Without it, requests with a key just behave as before. |
| `P3_004_outbox_reports.sql` | Patch 6c-1: creates `outbox_reports` (UNIQUE `client_key` = outbox item id, user_id, branch_id, action_type, summary, details, reason, queued_at, staff_discarded, status open/resolved, resolved_by/at/note) for the owner's Needs Action list | `CREATE TABLE IF NOT EXISTS`; re-runnable. Without it the phone's report call answers ok (nothing stored) and `/needs-action` says "not set up yet". All migrations are also concatenated in `deploy/migrations.sql`. |
| `P3_005_offline_sales.sql` | Patch 6c-2: adds `sales.client_id` CHAR(36) NULL + UNIQUE `uq_sales_client_id` (the sale's idempotency key — second guard against duplicates), `is_offline` TINYINT(1) DEFAULT 0, `device_time` DATETIME, `received_at` DATETIME (server time when an offline sale was accepted), `offline_receipt_no` VARCHAR(60); widens `outbox_reports.details` to VARCHAR(2000) (a refused sale lists items, amounts, payment, device time) | Every step runs only if still missing → re-runnable (tested twice); no drop/truncate; old sales keep NULL / 0. Without it sales still save (no client-id guard, no offline marks, owner report cut at 600 chars). In `deploy/migrations.sql`. |
| `P3_003_price_at_checkout.sql` | Patch 8: adds `sale_items.price_entry_mode` (VARCHAR(10) NULL; `piece`/`total`) and trigger `trg_sale_items_price_final` (BEFORE UPDATE: refuses changes to quantity, unit_price, line_total, price_entry_mode → completed sales are final) | Column added only if missing, trigger dropped + recreated: re-runnable. Needs `TRIGGER` privilege. Without the column the app still saves sales (no mode); without the trigger only the PHP code keeps prices final. Old rows stay as they are (mode NULL, old prices). |
