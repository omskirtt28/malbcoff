---
tags: [rules]
---
# Conventions (sundin sa bawat patch)

- Server is source of truth; never trust browser price/stock/branch/role.
- Non-owner: branch = `Auth::branchId()` lagi. Owner: `current_branch_scope()` (`?branch=`).
- Mutations sa `actions/`, may CSRF (`_csrf`), transaction + `FOR UPDATE` + guarded `UPDATE ... WHERE status='available'` + check `rowCount()`.
- Every stock change → `stock_movements` row.
- Prepared statements only. Native prepares: **bawal i-reuse ang same named placeholder** sa isang query (gumamit ng `:a1`, `:a2`).
- Escape output with `e()`.
- Schema change → ship a migration file `database/migrations/P3_0xx_name.sql` + runtime `SHOW COLUMNS` guard.
- New page = add to `$allowedPages` + `$pageRoles` in `index.php`, `app_url()` paths in `helpers.php`, sidebar + mobile-nav entries, and (production) `.htaccess` rewrite if clean URL is new.
- UI must match `DESIGN.md` + `design-reference/*.png` (cards, `kpi-card`, `data-table`, `filter-card`).
- Asset cache-busting is already `?v=filemtime`.
- Keep patches small: 1 patch = 1 feature, listed files lang.

Related: [[Architecture Map]] · [[Roles and Access]]
