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

- Owner branch scope = `?branch=` (topbar switcher, see `partials/topbar.php`). No `?branch` = All Branches.
- System Admin uses impersonation ("Enter Account") to view owner/branch screens.

Related: [[Users and Accounts]] · [[Auth and Session]]
