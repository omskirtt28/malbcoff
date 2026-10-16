---
tags: [plan, patch]
---
# Patch 5 — Edit account

State today: Owner **can** edit (Users page → Edit modal → `user_management.php` `update`). Non-owners can't edit anything but password. See [[Users and Accounts]].

## Part A — verify owner edit (no code unless broken)
Test: edit name, email, role, branch of a cashier; change role owner→x (blocked for self); duplicate email error; deactivate; reset password. Fix whatever fails (check `assets/js/users.js` submit + `syncBranchField`).

## Part B — My Account (self-service)
- New page `pages/account.php` (route `account`), allowed for all non-system-admin roles; add to `index.php` `$allowedPages`/`$pageRoles`, `app_url()` map in `core/helpers.php`, link in `partials/topbar.php` user menu (next to Change Password), `.htaccess` rewrite if clean URL needed.
- New `actions/account_update.php`: CSRF, `Auth::check`, updates ONLY own `name`, `email` (unique, validated by same rules as `clean_user_email`), requires current password to change email; audit `user.self_updated`; `Auth::refreshCurrentUser()`.
- Role/branch/active NOT editable here.

## Part C (optional) — Branch manager edits own-branch staff (name/email/active, not role/branch). Skip unless requested.

## Acceptance
- [ ] Owner edit works for all fields, errors shown via flash.
- [ ] Cashier changes own name → topbar updates; cannot change role/branch (tamper POST → ignored).
- [ ] Email change needs current password; duplicate email rejected.
- [ ] Audit log entry created.
