---
tags: [plan, patch]
---
# Patch 5 — My Account: edit own display name
**Status: DONE 2026-10-17.** (Final spec from John replaced the earlier plan: ONLY the display name, no email/password/audit/"assigned to"/owner-side editing.)

## What was built
- **Page** `pages/account.php` (route `account`, new "My Account" link in the topbar user menu, `.htaccess` rule `^account$`, `app_url('account')`, `index.php` `$allowedPages` + `$pageRoles` = owner, branch_manager, cashier, inventory). There was no existing settings/profile page for branch users (only `change-password.php`), so a small new page was added. Email, role, branch are shown read-only.
- **Action** `actions/account_update.php` — POST only, CSRF, `Auth::check`, blocked while impersonating / for System Admin. The user id is **`Auth::user()['id']` from the session** — nothing in the request can pick another user. Rules in `validate_display_name()` (helpers): trimmed, spaces collapsed, required, ≤ 60 chars, no `<`/`>`/control chars/HTML. `UPDATE users SET name=? WHERE id=? AND is_active=1` (prepared), then `Auth::refreshCurrentUser()`; output is escaped with `e()`.
- **History is never renamed** → snapshot columns (migration `database/migrations/P3_001_user_name_snapshots.sql`, see [[Migrations]]):
  `sales.cashier_name`, `stock_movements.created_by_name`, `inventory_transfers.forwarded_by_snapshot` / `received_by_snapshot`. Filled by `BEFORE INSERT` (and `BEFORE UPDATE` for received) **triggers**, so none of the ~20 INSERT sites changed; existing rows are backfilled once with the user's current name.
- **Readers** use `snapshot_name_sql()` (helpers): `COALESCE(NULLIF(snapshot,''), live users.name)`; falls back to the live name if the snapshot is empty or the migration is not imported yet. Updated: Sales Records (list, detail, search by cashier), Dashboard Recent Sales, POS Recent Sales, Stock Movement "Added By", Patch 2B drawer (Sold cashier, Stock In "received by", Sales Today/Month cashier), Branch Transfers page + transfer details. Topbar and Users page keep showing the live name.

## Caveat (deployment)
Triggers need the MySQL `TRIGGER` privilege. If a host refuses, the migration stops at the trigger step (columns + backfill are already done, safe to re-run) and the app keeps working with live-name fallback — but then new rows would not be snapshotted until triggers are created (phpMyAdmin with a privileged user).

## Acceptance (tested against the local DB through a PHP built-in server with real sessions, then cleaned up)
- [x] Branch 1 user "Jeff" → "Josh": topbar shows Josh after reload, Users page shows Josh.
- [x] Sale/movement made before the rename still show "Jeff" (Sales Records, Stock Movement, Dashboard Recent Sales, KPI drawer); new ones show "Josh". Searching Sales Records by the old name finds the old sale.
- [x] Tampered POST (`id=3`, `user_id=3`) only changes the logged-in user; GET, anonymous and missing-CSRF requests change nothing.
- [x] Empty, whitespace-only, `<b>…</b>`, `a<b` and 61-character names are rejected with a message and nothing changes; 60 characters accepted; `&` and quotes saved and escaped on output.
- [x] Migration run twice: no errors, no duplicate columns/triggers, backfill only touches NULL rows. Transfer snapshots keep the forwarder's old name and take the receiver name at receive time.
- [x] Responsive 360 / 768 / 1280: no horizontal scroll, 44px controls.

Related: [[Users and Accounts]] · [[Auth and Session]] · [[Data Model]]

## 2026-10-21 amendment
**Status: done locally, not yet live.** No live hosting access or deployment confirmation.

The earlier acceptance above records historical behavior. Current action now explicitly rejects another account's id/user_id/target_user_id (rather than ignoring it), rejects protected-field attempts, and uses CSRF + SafeSubmit + transactional idempotency/audit. The session and topbar refresh immediately. Existing profile-menu My Account access and 60-character validation remain. Old snapshot rows stay unchanged; current trigger capture was reverified. See [[Users and Accounts]] and [[Deploy Package]] for the 349-check evidence, 13 runtime files, separate Desktop ZIP/TXT and prerequisites.
