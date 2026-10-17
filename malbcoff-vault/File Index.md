---
tags: [index, files]
---
# File Index

Sizes in lines. "Tables" = main DB tables touched. ⚠ = huge file, read only the section you need (use grep).

## Root
| File | What it does |
|---|---|
| `index.php` (~110) | Router. `$allowedPages`, `$pageRoles`, owner/system-admin redirects, renders layout + `pages/$page.php` |
| `bootstrap.php` | Config, secure session cookie, loads core, `Security::guardAuthenticatedSession()`, forced password change + maintenance gates |
| `login.php` | Login form + throttle (`Security::loginRateStatus`) → `Auth::attempt` |
| `logout.php` | Ends session |
| `change-password.php` | Self-service password change (only self-edit that exists today) |
| `maintenance.php` | Maintenance screen |
| `preview.html` | Static UI preview |

## core/
| File | What it does |
|---|---|
| `Auth.php` | Login, session user, `isOwner()`, `branchId()`, `refreshCurrentUser()`, `fetchUser()`, system-admin impersonation |
| `Csrf.php` | `token()`, `verify()`, `rotate()` |
| `Database.php` | PDO singleton, `Database::query($sql,$params)` |
| `Security.php` | Headers, session idle/absolute timeout, UA check, user refresh every 5 min, login throttling, `audit()`, `reportException()` |
| `StockMonitoringReport.php` (220) | **Builds Stock Monitoring data** from `stock_movements` (opening/in/out/sold/ending per product, per-branch ending for owner). See [[Stock Monitoring]] |
| `helpers.php` (846) | URLs, flash, icons, `current_branch_scope()`, branch pricing, variant duplicate repair/merge |

## pages/
| File | Roles | What it does |
|---|---|---|
| `dashboard.php` (261) | all | Owner KPIs (inventory, sales today/month, recent sales, branch bars) / branch KPIs. See [[Dashboard]] |
| `stock-monitoring.php` (297) | owner, mgr, inv | Owner matrix (branch columns) vs branch detail table. See [[Stock Monitoring]] |
| `sales-records.php` (346) | owner, mgr, cashier | Sales list + filters + per-branch cards + transaction detail. See [[Sales Records]] |
| `stock-movement.php` (27, dense) | owner, mgr, inv | Ledger list w/ tabs; System Admin void. See [[Stock Movement]] |
| `branch-transfers.php` (262) | owner, mgr, inv | Transfer list, pending/received, receive modal. See [[Branch Transfers]] |
| `stock-in.php` ⚠ (2953) | mgr, inv (owner code exists but route blocked) | Receive Stock wizard (model → variant → qty & scan → confirm). See [[Stock In (Receive Stock)]] |
| `inventory.php` ⚠ (1200) | all | Grouped inventory, View Units modal, forward, delete/adjust. See [[Inventory]] |
| `products.php` ⚠ (1233) | mgr, inv, cashier; owner = Archive only | Product master (brands/models/variants/accessories/prices) |
| `add-item.php` | mgr, inv | Add catalog item |
| `pos.php` (146) | mgr, cashier | POS screen. See [[POS]] |
| `users.php` (132) | owner (manage), mgr (view) | User list + Add/Edit/Reset/Deactivate modals. See [[Users and Accounts]] |
| `system-admin-*.php`, `security-logs.php` | system_admin | Admin console, impersonation history, deleted records, data reset, logs |

## actions/
| File | What it does |
|---|---|
| `complete_sale.php` | POS checkout (JSON for SafeSubmit / the outbox, classic form post kept). Locks, prices from the typed entries, inserts `sales`, `sale_items`, `stock_movements(sale)`. Patch 6c-2: `X-Outbox-Send` = queued offline sale → device time sets the sale date, `sales.client_id` UNIQUE guard, plain refusal reasons |
| `pos_search.php` | JSON search for POS |
| `stock_in.php` ⚠ (534) | Receive stock: units/balances + `stock_movements(stock_in)`; owner allowed here |
| `forward_inventory.php` | Start branch transfer: source `transfer_out`, units → `transferred`, `inventory_transfers(pending)` |
| `receive_transfer.php` | Destination receives: `transfer_in`, units → `available` at new branch |
| `transfer_details.php` / `lookup_forward_unit.php` | JSON helpers for transfers |
| `adjust_inventory.php` | Owner/branch adjustments (adjusted_out, defective, accessory +/-) |
| `delete_inventory_unit.php` / `delete_accessory_inventory.php` | Remove mistaken stock rows |
| `void_inventory_movement.php` | System Admin void → restore stock + `adjustment` (+) with ref `VOID-<id>` |
| `product_master.php` ⚠ (541) | Brand/model/variant CRUD, archive, merge |
| `add_item.php` | Create catalog item |
| `product_units.php`, `variant_inventory.php` | JSON unit lists / per-branch variant stock |
| `export_stock_monitoring.php` | Excel export of Stock Monitoring (uses same report class) |
| `stock_monitoring_drawer.php` | Read-only owner-only JSON for the KPI drawer (`StockMonitoringReport::drawer`) |
| `account_update.php` | Self-service: a user changes ONLY their own display name (id from session, CSRF, validated) |
| `pos_last_prices.php` | Patch 8: per-product price THIS branch last sold at (typed at checkout, not voided) for the "Last: ₱…" chips; branch from login only |
| `ping.php` | Tiny public heartbeat for the connection banner (no session, no DB) |
| `outbox_status.php` | Patch 6c-1: GET heartbeat for the device outbox — signed in? as which user? fresh CSRF token (no state change) |
| `outbox_report.php` | Patch 6c-1: branch staff report a "needs action" outbox item (idempotent on UUID) or flag it discarded; user/branch from session; writes `outbox_reports` only |
| `outbox_resolve.php` | Patch 6c-1: owner-only, marks a Needs Action report resolved (+ note) |
| `user_management.php` | Owner-only: create / update / toggle / reset_password |
| `impersonation.php`, `system_admin_*.php` | System admin tools |

## partials/
`header.php` (CSS), `sidebar.php` (nav per role), `topbar.php` (**owner branch switcher**, user menu), `mobile-nav.php`, `footer.php` (page-specific JS, all `defer`), `inventory-accessory-delete.php`, `pwa-head.php` (manifest + icons, in every `<head>`), `pwa-register.php` (loads `sw-register.js`, in every page — Patch 6b)

## web root (Patch 6b)
`sw.js` (service worker, static files only), `offline.html` (no-internet page), `manifest.webmanifest` (install on phone/tablet). See [[Service Worker and Offline Page]].

## assets/
- `css/app.css` 205 KB (all styles), `css/branch-transfers.css`
- `js/app.js` (global: search, inventory modals, forward, adjust), `pos.js`, `users.js`, `branch-transfers.js`, `kpi-drawer.js` (owner KPI drawer), `safe-submit.js` (shared saving status + retry + connection banner: Weak connection / No internet / Back online; hands failed saves to the outbox), `outbox.js` (Patch 6c-1/6c-2: IndexedDB outbox for Receive Stock / Branch Transfer / Receive Transfer / POS sales (holds, 50-sale / 24 h warning) — queue, FIFO sender with backoff + lock, topbar badge, Outbox panel, pending hint, sign-out guard, banner messages), `sw-register.js` (registers or removes the service worker), `inventory-*.js`, scanners (`device-scanner.js`, `imei-reader.js`, `serial-label-reader.js`, `serial-ocr.js`), `product-quick-setup.js`
- `icons/` PNG home-screen icons (192, 512, maskable, Apple 180) made from `favicon.svg`
- `vendor/` zxing-wasm (~1 MB), legacy-scanner, serial-ocr (~13 MB, Tesseract) — loaded only on pos/inventory/add-item

Related: [[Architecture Map]] · [[Data Model]]

