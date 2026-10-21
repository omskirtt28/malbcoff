---
tags: [bug, inventory, cleanup]
status: done, not yet live
fixed: 2026-10-21
---
# BUG Deleted Inventory Units and Blocked Identifiers

## FIX 8C-2 — current status
**Done, not yet live.** The user confirmed that everything before this fix is LIVE, including inventory soft deletion, P3_006 and zero-stock visibility. Earlier rules that archive any referenced unit are superseded by this note.

## Problem
Deleted Inventory Units such as Samsung GALAXY TAB A11+ #1805, #1807, #1808, #1809, #1813 and #1819 have Sales 0 / Voids 0 but remain Archived (history kept) because they have transfers/movements. They have no delete button. The owner needs their unit rows permanently removed so Branches 2, 3 and 4 can receive the same identifiers again.

## Cause and investigation
- core/InventoryDeletion.php previously used array_sum(all reference counts)>0 to archive; it skipped every already archived unit. The partial hid actions solely based on status=archived. A normal receiving/removal ledger therefore made almost every deleted unit impossible to hard-delete.
- Scanner GET lookup and receiving POST in actions/stock_in.php compare only live inventory_units.imei, imei2 and serial_no, across all branches/identifier types. Their unique indexes are the only unique identifier constraints found locally. archived_* and sale_items.identifier_snapshot are unindexed history; new snapshot JSON has no unique index. No scanner exemption is needed.
- Branch Transfers page counts parent inventory_transfers records and displays parent quantity/status. StockMonitoringReport::inTransit counts pending parent quantities. Neither derives transfer quantity from remaining unit rows.
- Transfer details and receive previously INNER JOINed inventory_units, which would silently hide detached lines. Details now LEFT JOINs; receive counts every line and explicitly refuses missing/deleted units before committing anything.
- StockMonitoringReport aggregates signed stock_movements.quantity by product/branch/date/type. Dashboard date KPIs use the same dayTotals; live stock counts status=available. Stock Movement lists movement rows with LEFT JOIN to units. Deleting the ledger rows would remove Stock In/Transfer In/Transfer Out and change opening balances for past dates, even when the unit's lifetime net is zero.
- Ledger drawers also infer live units for old unlinked stock-in batches. Snapshot rows must be explicitly excluded from that inference, so receiving the same IMEI cannot attach new inventory to the old history.

## Change
1. **Sales history means any sale_items reference or any inventory_movement_voids reference** (including a void linked through a movement). Keep only these units archived, save identifiers in archived_* and NULL all three live fields. Never edit/delete their sales, voids or movements. Already archived sales rows are harmless on retry.
2. **Sales=0 AND voids=0 means permanently deletable**, regardless of transfers/movements or deleted/archived status. Available/sold/transferred/etc. rows are never eligible. UI checkbox, single Delete permanently, selected bulk and select all now use the sales/void classification. Sales rows that have not yet been released offer Release IMEI and keep records. Archived reason: “This unit has sales history, so it is kept for records. Its IMEI can be used again.”
3. In one caller transaction, lock each unit; write a required inventory_unit_delete_audits snapshot BEFORE detaching/deleting; NULL each transfer line unit_id and store deleted_unit_id + unit_snapshot JSON (product label, IMEI, IMEI 2, serial, condition); convert the unit's movement rows to the same snapshot-only form; delete the actual inventory_units row. Required audit failure rolls back cleanup. Snapshots preserve original remover/name/date, branch, actor/name/time, full unit, product label, reference counts, transfer_lines_detached, movement_rows_detached and movement_rows_removed=0. No FK points the audit back at the deleted unit. Permanent Inventory Deletes displays this durable audit data; legacy security audit rows remain readable without duplicating the new entries.
4. **Movement choice: retain snapshots, delete zero ledger rows.** Keep every movement id, product, branch, quantity, date, type, price/cost, notes and reference unchanged. This preserves all dated buckets and pending transfer totals. Transfer parents and line count remain unchanged, including received and pending states. Transfer detail displays Unit deleted + exact saved identifiers/label. Pending transfers containing an already removed unit stay pending; receive refuses atomically instead of fabricating new stock or changing the parent quantity/status. Owner review of such a historical inconsistency remains manual.
5. Shared stock drawers read snapshots, retain historical unit labels, and exclude detached stock-in rows from live batch inference. Stock Movement shows Unit deleted; snapshot/removal history cannot be voided back into stock (UI and server).
6. Delete all with no sales previews the **global** eligible count across all branches/pages (explicitly independent of page filters). Exact typed DELETE, confirmed flag and count/ID-set preview token are required; server recomputes/locks the set inside the same transaction and rejects a stale preview. No 50-row cap for this global action. Empty re-runs succeed with zero deletions. UUID retries replay the original result. Every endpoint branch enforces direct System Admin, no impersonation, POST and CSRF.
7. Reference pills are compact 2x2, with non-wrapping labels/counts >=14px. Cleanup controls >=44px. Fixed the inherited 40px table button override and 360px Branch Transfers tab overflow; snapshot transfer labels wrap within their panel and stay >=14px.

## Migration / local changes
- New database/migrations/P3_007_inventory_delete_snapshots.sql; deploy/migrations.sql contains only P3_007 because P3_006 and earlier changes are LIVE.
- Schema-only, re-runnable. Preserve unit_id column types/FKs; make transfer/movement unit_id nullable if needed; add deleted_unit_id (no live FK) and unit_snapshot LONGTEXT to inventory_transfer_units and stock_movements. No units are automatically purged and no quantities/history are backfilled or erased.
- SHOW COLUMNS runtime guard refuses cleanup until the new schema is present; ordinary historical readers fall back to pre-migration columns.
- Passed twice on isolated local malbcoff_fix_20261020_v2. Applied only to local malbcoff_pos after backup: backups/inventory-before-P3_007-20261020-184113.sql. Verified unit rows/identifiers, transfer rows/statuses and movement counts/sums unchanged. No real local units were purged. Hosting was not accessed.

## How to test on hosting after deployment
1. Back up database/files; apply only P3_007; upload the 12 app files in [[Deploy Package]].
2. As System Admin find the six reported IDs (or equivalent Sales 0 / Voids 0 with Transfers 1 / Movements 3). Old archived rows must show checkbox + Delete permanently. Single, selected and select all page use the same service. Bulk requires exact DELETE, including a selected bulk of one.
3. Delete all with no sales: preview the global count, type DELETE, confirm. Run again: 0 eligible and no second audit/deletion. Changed preview set must ask for a new preview. Search/page filters must not silently narrow this action.
4. Confirm each unit is absent from inventory_units and Deleted Inventory Units. Its required audit snapshot remains in Permanent Inventory Deletes with all original identifiers/remover, current actor/time and detachment counts.
5. Branches 2/3/4 scan and receive the original IMEI again through normal Receive Stock. Also check IMEI 2 and serial lookup. New live inventory remains subject to normal uniqueness rules.
6. Open original Branch Transfers details as owner or either participating branch: Unit deleted, original identifiers, same line count/quantity/status/proof. Pending detached transfers stay pending; attempted receive refuses without any partial stock or status change.
7. Sale/void unit: cleanup archives/releases live identifiers but keeps the row and exact sale/void references. Original receipt opens in Sales Records; sale amounts remain unchanged. Archived reason is visible.
8. Direct owner/branch_manager/inventory/cashier/impersonated requests denied. Invalid CSRF denied; forged bulk without DELETE denied; available+deleted mixed bulk rolls back every earlier snapshot/detachment/deletion. UUID retries create no duplicates.
9. Compare per-product/branch/date movement sums and Monitoring buckets, Dashboard available count and sales totals before/after; shared drawer entries still show historical identifiers. Snapshot outgoing rows cannot be voided. Check 360/768/1280 widths, 2x2 non-wrap pills, 14px text and 44px targets.

## Validation
137 browser/HTTP/PDO checks passed on the isolated local DB: 102 main + 16 additional + 19 final read-only. Includes physically deleting transfer/movement units (including old archived rows), durable audits, rollback, permissions/CSRF/typed confirmation, stale/zero/global 55-unit previews, all branch/date/type movement buckets unchanged, all Monitoring scope totals unchanged, transfer parents unchanged (pending included), sale/void preservation and receipt rendering, drawer snapshots, 360/768/1280 layouts, and real IMEI receiving at Branches 2, 3 and 4. New sales archive releases IMEI, IMEI 2 and serial. PHP/JS syntax checks passed. Evidence: output/playwright/fix8c2-checks.txt, fix8c2-extra-checks.txt and fix8c2-*.png. Fixture scripts are excluded from deployment.

Actual production IDs #1805/#1807/#1808/#1809/#1813/#1819 were not queried or deleted in this session. Their matching fixture scenarios passed; the owner must run the confirmed cleanup after deployment. **Done, not yet live.**
