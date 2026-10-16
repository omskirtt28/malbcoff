---
tags: [architecture]
---
# Architecture Map

**Stack:** PHP 8 + MySQL/MariaDB (PDO), server-rendered pages, vanilla JS. No build step, no CDN. Timezone `Asia/Manila`. Runs sa XAMPP locally; production behind HTTPS (optional Cloudflare).

## Request flow
```
browser → index.php (router)
            ├─ bootstrap.php  (config, session, Database, Csrf, Auth, Security, helpers,
            │                  forced password change, maintenance gate)
            ├─ $pageRoles check (role → allowed pages)  ← dito i-add ang bagong page
            ├─ partials/header.php → sidebar.php → topbar.php
            ├─ pages/<page>.php   (reads DB + renders HTML)
            └─ partials/mobile-nav.php → footer.php (page-specific JS)

form/AJAX → actions/<name>.php  (own bootstrap require, Auth + role + CSRF + transaction)
            → redirect with flash()  OR  JSON
```

## Layers
| Folder | Role |
|---|---|
| `index.php` | Router: `$allowedPages` (slug → title) + `$pageRoles` (slug → roles) |
| `pages/` | Views. Read-only queries + HTML. |
| `actions/` | Mutations & JSON endpoints. Always: `Auth::check`, role check, `Csrf::verify`, transaction. |
| `core/` | `Auth`, `Csrf`, `Database`, `Security` (headers, session guard, login throttle, audit), `StockMonitoringReport`, `helpers.php` |
| `partials/` | Layout. `topbar.php` has the **owner branch switcher** (`?branch=`). |
| `assets/` | `css/app.css` (205 KB!), `js/*`, `vendor/` scanners/OCR (MBs) |
| `config/` | `app.php` (env, session timeouts, rate limits), `database.php` |
| `database/` | Old base SQL only. **Migrations P1–P2 are missing** in this copy (see [[Data Model]]) |

## Key helpers (core/helpers.php)
- `current_branch_scope()` — owner: `?branch=` from URL (null = all); others: own branch
- `owner_branch_filter_url($page,$branchId)`
- `app_url($route,$params)` — clean URLs (`/stock-monitoring?branch=1`)
- `inventory_active_movement_sql()` / `inventory_active_sale_item_sql()` — exclude voided rows
- `peso()`, `e()`, `icon()`, `brand_logo_html()`, `flash()`, `redirect()`

## Two inventory models (important)
- **Devices (phone/tablet):** 1 row per unit in `inventory_units` (status available/sold/transferred/adjusted_out…)
- **Accessories:** quantity in `inventory_balances` (product + branch)
- **Ledger:** every change also writes `stock_movements` (signed qty). [[Stock Monitoring]] is computed from the ledger; [[Dashboard]] counts live tables. Dapat equal sila.

Related: [[File Index]] · [[Data Model]] · [[Conventions]]
