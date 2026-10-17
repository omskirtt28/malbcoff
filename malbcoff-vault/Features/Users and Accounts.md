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
