---
tags: [plan, patch, network]
---
# Patch 6a — Weak internet: no lost, no double actions, friendly status
**Status: DONE 2026-10-17.** No offline queue, service worker or caching (that is 6b/6c).

## Server — idempotency (`core/Idempotency.php`, migration `P3_002_idempotency_keys.sql`, see [[Migrations]])
- The browser sends one UUID per intention (`X-Idempotency-Key` header and `client_key` field). The server inserts it into `idempotency_keys` (UNIQUE) **inside the same DB transaction** as the work and stores the response + reference (`SALE-…`, `STK-…`, `TRF-…`, `ADJ-…`). Same key again → the stored result is returned with `replayed:true`; no second record, no second stock movement. Key + user + action + sha256 of the submitted content must match, otherwise **409** (never replay a wrong result). Concurrent duplicates: the 2nd INSERT waits for the 1st to commit, then returns the same result.
- Wired into: `actions/complete_sale.php` (POS), `actions/stock_in.php` (Receive Stock page + inventory quick-receive), `actions/forward_inventory.php` (Branch Transfer create), `actions/receive_transfer.php` (Transfer accept), `actions/adjust_inventory.php` (stock adjustments).
- **No key (or migration not imported yet) = exactly the old behaviour**, including the classic redirect + flash form post of POS and Stock In. JSON mode is chosen by `Accept: application/json`.
- CSRF is still enforced. A stale token returns `419 {code:'csrf', csrf:<fresh token>}`; the client swaps the token and resends the SAME key once. Logged out → `401 {code:'auth'}`.
- Failure codes: business problems (not enough cash/stock…) = 422 (message shown, key stays usable); real server trouble = 500 → client offers Retry.
- `actions/ping.php`: tiny public heartbeat (`{"ok":true}`, no session, no DB) for the banner.

## Client — one helper + one CSS component
- `assets/js/safe-submit.js` (loaded for every page from `partials/footer.php`): `SafeSubmit.run({url,keyHolder,button,host,before,getBody,onCsrf,successDetail,onDismiss})` and the connection banner.
  - Button disabled, spinner + "Sine-save…", ~20 s timeout, form/cart never cleared before success.
  - Success: green card "Na-save na ✓" + reference, stays until **OK**. Failure/timeout/offline: red card "Hindi pa na-save. Mahina o walang internet. Walang nawala sa laman mo." + big **Retry — Subukan ulit** (same key) and "Ayusin muna ang laman". No technical words. Expired login: message + "Mag-login (bagong tab)" link, then Retry.
  - `role=status`/`alert` + `aria-live`; text ≥16px; buttons ≥48px; works at 360px.
  - Banner: amber "Mahina ang internet…" at the top of `.app-main` (in the page flow, topbar moves below it); `navigator.onLine` + ping every 15 s (RTT > 3 s = slow).
- CSS: appended `Patch 6a` block in `assets/css/app.css` (`.save-status*`, `.net-banner`).
- Wired into: `assets/js/pos.js` (checkout, no more `form.submit()`), `pages/stock-in.php` (confirm button), `assets/js/inventory-forward.js` + `assets/js/app.js` (forward/transfer create), `assets/js/branch-transfers.js` (receive), `assets/js/app.js` + `pages/inventory.php` (stock adjustments, System Admin/impersonation UIs). `assets/js/product-quick-setup.js` (Products quick "variant + stock"): sends a key on the receive step (server-safe retry) but keeps its own message UI.

## Acceptance (real HTTP sessions + headless Chrome with DevTools-protocol offline/response-drop simulation; DB restored afterwards)
- [x] Same key 3× (double-click / replay) → exactly 1 sale + 1 movement; parallel duplicates → 1 sale; same for transfer, receive, adjustment and stock-in (single-use review nonce no longer strands a lost response).
- [x] Offline in the browser: cart / form survive; red Retry card; Retry with the same key after the network returns → exactly one record. Response dropped after the server saved → Retry returns the SAME reference, still one record. 20 s timeout verified (20.1 s).
- [x] Requests without a key still work (JSON and classic form post); migration run twice; key reuse with other content/user → 409.
- [x] Branch users and the owner both get the banner; 360 / 768 / 1280px: no horizontal scroll, ≥44px targets, ≥16px text.
- Not exercised in a real browser: the System Admin adjustment modals (needs impersonation) — server path tested, JS syntax-checked, same helper.

Related: [[POS]] · [[Stock In (Receive Stock)]] · [[Branch Transfers]] · [[Inventory]] · [[Conventions]]
> **Patch 6b (2026-10-17):** the connection banner now has three states — "Weak connection" / "No internet. Your data is safe." / "Back online" — and is slimmer. See [[Patch 6b - Offline Shell and Cache]].
> **Patch 6c-1 (2026-10-17):** for Receive Stock, Branch Transfer and Receive Transfer the red Retry card is replaced by "Saved on this device." + the Outbox when the browser supports IndexedDB; the same idempotency key is reused. See [[Patch 6c-1 - Offline Outbox for Stock Actions]].
> **Patch 7 (2026-10-17):** the Tagalog messages above are now English: Saving... / Saved ✓ / Not saved yet. / Retry / Go back and edit / weak-internet banner. See [[Patch 7 - English UI and Stock Monitoring Tabs]].
