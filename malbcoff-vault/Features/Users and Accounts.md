---
tags: [feature]
---
# Users and Accounts
`pages/users.php` + `assets/js/users.js` + `actions/user_management.php` (**owner only**; actions create / update / toggle / reset_password).
- **Edit already exists for the Owner**: row button `data-user-edit` opens modal (name, email, role, branch) → `action=update`. Guards: can't demote self, unique email, owner needs no branch.
- Branch manager can only *view* users of own branch.
- Self-service: only `change-password.php`; **no profile edit** for non-owners.
- After update of self → `Auth::refreshCurrentUser()`. Session user refresh every 5 min picks up role/branch/active changes.
Touched by: Patch 5.
