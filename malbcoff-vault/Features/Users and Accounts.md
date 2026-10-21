---
tags: [feature]
---
# Users and Accounts
`pages/users.php` + `assets/js/users.js` + `actions/user_management.php` (**owner only**; actions create / update / toggle / reset_password).
- **Edit already exists for the Owner**: row button `data-user-edit` opens modal (name, email, role, branch) → `action=update`. Guards: can't demote self, unique email, owner needs no branch.
- Branch manager can only *view* users of own branch.
- Self-service: `change-password.php` and **My Account** (`pages/account.php` + `actions/account_update.php`, Patch 5) — every logged-in user (owner included) can edit ONLY their own display name (max 60 chars). History keeps the old name via snapshot columns ([[Migrations]]).
- After update of self → `Auth::refreshCurrentUser()`. Session user refresh every 5 min picks up role/branch/active changes.
Patch 5 done 2026-10-17.

## 2026-10-21 - System Admin and profile update
**Status: done locally, not yet live.** No live hosting access or deployment confirmation.

- Direct System Admin > User Accounts now has **Add User** and **Manage Branches**. Both endpoints require direct System Admin authentication outside impersonation, POST, CSRF, current active-user permission revalidation, a valid retry key, row locks, a transaction and the existing audit helper.
- Add User: Full Name, Email, Role, Branch, Temporary Password. Reuses the unchanged Owner validators in `core/UserManagement.php`: owner/branch_manager/inventory/cashier only; normalized name capped at 120, email validation up to 160, 12-128-byte password with letter and number. Owner always stores NULL branch (All Branches); other roles require an active branch. Duplicate email rejected by check and existing unique index. Hash + must_change_password=1; no password/hash is returned, logged or stored in browser/outbox/idempotency intent/results.
- Manage Branches lists active and inactive branches with Rename. Required normalized name, current schema maximum (100 characters), no HTML/control characters, duplicate check using database collation. Ordered branch locks serialize renames without a schema change. Only `branches.name` changes; codes, IDs, status, assignments, stock, money, transfers and snapshots stay intact. Current selectors/account listings read the new name from branches.
- My Account was already present in the profile menu; branch access was verified. Edit Name now uses SafeSubmit + transactional idempotency/audit. Session ID selects the row; foreign id/user_id/target_user_id and protected-field attempts are rejected. Own name remains 1-60 characters, normalized and HTML/control-free. Session and visible topbar name refresh immediately. History is never updated; old snapshot values remain, new records use the new name.
- Owner Create/Edit/Activate/Deactivate/Reset Password and Enter Account/Return are preserved. Shared validator bodies match the pre-task file byte for byte. No new System Admin creation and no profile edit during impersonation.
- New UI uses native dialogs, focus return/Escape, English copy, SafeSubmit states and a separate scoped stylesheet. Phone account cards and keyboard-accessible wider-table scroll; 360/768/1280 checked, no horizontal page scroll, content controls >=44px and text >=14px.

No migration. Requires the existing security/impersonation schema, must_change_password, P3_001 snapshot columns/triggers and P3_002 idempotency_keys, already in confirmed-live baseline v3. No dependency on FIX 8C-2/P3_007.

349 isolated assertions passed (287 main HTTP/browser + 19 retry/Owner/native-form + 34 UI/responsive + 9 original-history/new-snapshot/validator checks); 11 runtime PHP syntax checks, JavaScript syntax and task-scoped whitespace checks passed. Test DB: `malbcoff_admin_profile_20261021`, schema cloned without real rows; synthetic fixtures only. Simulated lost response after commit retried the same key and created one user; stale CSRF recovered. Historical rows compared unchanged and new snapshot triggers verified in a rolled-back test transaction. Real accounts/branch names were not modified for tests.

ZIP: `C:\Users\John Joshua\Desktop\malbcoff-admin-profile-update-20261021-225800.zip`. Separate upload/rollback guide: `C:\Users\John Joshua\Desktop\malbcoff-admin-profile-update-20261021-225800.txt`.

Exact 13 runtime files, SHA256s, prerequisites and upload order: [[Deploy Package]]. Pre-task copies/hashes and task-only diff: `output/admin-profile-update/`. Verification scripts: `tests/admin-profile/`, evidence JSON and screenshots: `output/admin-profile-update/` and `output/playwright/admin-profile-*`. All are excluded from the ZIP. Not performed: hosting/staging UAT, real-device/Safari/Firefox checks, parallel multi-worker stress and real outage testing.
