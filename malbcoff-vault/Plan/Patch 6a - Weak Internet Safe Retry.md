---
tags: [plan, patch, network]
---
# Patch 6a — Weak internet: no lost, no double actions

## Problems found in code
- `assets/js/pos.js` ~L289: checkout = `checkoutForm.submit()` (browser POST, no key). Slow net → user taps again/reloads → **duplicate sale**.
- `fetch()` in `pos.js`/`app.js` has no timeout or retry.
- `actions/stock_in.php`, `forward_inventory.php`, `receive_transfer.php` likewise not retry-safe.
- Session idle 30 min (`config/app.php`) → expired CSRF after waiting.

## Files
- **Migration** `database/migrations/P3_001_idempotency.sql`: `sales.client_ref VARCHAR(64) NULL UNIQUE`; same for `inventory_transfers.client_ref`, and a small `request_log(client_ref PK, action, result_json, created_at)` for stock-in.
- `assets/js/net.js` (new): `netFetch(url, opts, {timeout:15000, retries:3, backoff})`, generates `crypto.randomUUID()` per action, online/offline banner, `fetch` of `actions/ping.php`.
- `actions/ping.php` (new): returns `{ok, csrf}` (keep-alive + fresh CSRF; call every 5 min while page open).
- `assets/js/pos.js` + `pages/pos.php`: checkout via `netFetch` JSON with `client_ref`; button locked, "Sending… retrying" status; on success show receipt like current flash.
- `actions/complete_sale.php`: accept `client_ref`; if a sale with that ref exists → return the SAME result (no second sale). Return JSON when `Accept: application/json`, keep redirect path for old form.
- `actions/stock_in.php`, `forward_inventory.php`, `receive_transfer.php`: same `client_ref` replay logic; JS wrappers in `pages/stock-in.php` (inline JS, edit only the submit handler), `assets/js/app.js` (forward), `assets/js/branch-transfers.js` (receive).
- `partials/footer.php`: include `net.js`.

## Acceptance
- [ ] Chrome DevTools "Slow 3G": sale completes once; shows progress.
- [ ] Offline mid-submit then back online: retry results in exactly 1 sale (check `sales` count).
- [ ] Double-click / reload during submit: no duplicate.
- [ ] Same for stock-in (no double units) and receive transfer.
- [ ] Idle page > 30 min: keep-alive or clear "session expired, log in" with cart preserved (localStorage).
