---
tags: [auth]
---
# Roles and Access

Source of truth: `$pageRoles` in `index.php` + checks inside each `actions/*.php`.

| Page | owner | branch_manager | inventory | cashier |
|---|---|---|---|---|
| dashboard | ✓ (all/selected branch) | ✓ | ✓ | ✓ |
| pos | ✗ | ✓ | ✗ | ✓ |
| sales-records | ✓ all | ✓ own | ✗ | ✓ own |
| products | Archive only | ✓ | ✓ | ✓ |
| inventory | ✓ | ✓ | ✓ | ✓ |
| stock-monitoring | ✓ | ✓ | ✓ | ✗ |
| branch-transfers | ✓ view | ✓ | ✓ | ✗ |
| stock-in (Receive) | **✗ route blocked** (code supports owner) | ✓ | ✓ | ✗ |
| stock-movement | ✓ | ✓ | ✓ | ✗ |
| users | ✓ manage | view own branch | ✗ | ✗ |
| needs-action (Patch 6c-1) | ✓ only | ✗ | ✗ | ✗ |

Offline outbox (Patch 6c-1) exists only for assigned branch users (not owner, System Admin or impersonation); the owner reads the Needs Action list and resolves items.

- Owner branch scope = `?branch=` (topbar switcher, see `partials/topbar.php`). No `?branch` = All Branches.
- System Admin uses impersonation ("Enter Account") to view owner/branch screens.

Related: [[Users and Accounts]] · [[Auth and Session]]

## 2026-10-21 - Current account mutation permissions
**Status: done locally, not yet live.** No live hosting access or deployment confirmation.

| Action | Direct System Admin | Owner | Branch Manager / Inventory / Cashier | Impersonation |
|---|---|---|---|---|
| New System Admin Add User endpoint | Yes | No | No | No |
| Rename Branch | Yes | No | No | No |
| Own display name via My Account | No (business account page) | Yes | Yes | No |
| Existing Owner user management | Via existing Owner Enter Account view | Yes, unchanged | Existing manager view only | Existing Owner permissions preserved |
| Enter Account / Return | Yes, existing workflow | No start | No start | Return only |

The new admin endpoints check effective direct role plus `!Auth::isImpersonating()`, then re-read active-user permission from the database and revalidate under lock. Self rename derives ID from Auth session, rejects another account ID, and cannot alter email, role, branch or account status. POST + CSRF + idempotency + transaction are enforced server-side. No client-only permission gates. See [[Users and Accounts]] and [[Deploy Package]] for 349-check evidence and package details.
