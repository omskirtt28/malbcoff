---
tags: [plan, patch, network, offline]
---
# Patch 6c-1 — Offline outbox for stock actions (stock only, NOT sales)
**Status: DONE 2026-10-17.** Sales (POS checkout) are **not** included — that is [[Patch 6c - Offline Sale Queue]] (now 6c-2). Builds on [[Patch 6a - Weak Internet Safe Retry]] (idempotency keys) and [[Patch 6b - Offline Shell and Cache]] (banner, service worker).

## What staff see
- Receive Stock, Branch Transfer (send) and Receive Transfer: if the save fails because of the network (offline, timeout, dropped, 5xx) — or the login expired — the blue card says **"Saved on this device. It will be sent when you are back online."** (OK + View Outbox). No red error, the form is not lost.
- Topbar badge **Queued: N** (becomes **Needs action: N** when only refused items remain; red chip with the count when both) → opens the **Outbox** panel: type, one-line summary, time, status (Waiting · Sending... · Sent ✓ · Needs action), "Next try in N s", **Send now**.
- Needs action items show the reason in plain English + **Review** (details of what was saved + "Open the form" → Receive Stock with the product, Inventory, or Branch Transfers with the transfer opened via `?open=<id>`) and **Discard** (inline "Discard this item?" confirmation).
- Banner (same bar as 6a/6b): "Sending N queued items..." · "All queued items were sent" · "N items need action" · "Please log in again to send your queued items." (+ Log in button).
- Pages where stock numbers matter (Dashboard, Inventory, Stock Monitoring, Stock Movement, Branch Transfers, Receive Stock) show **"N queued actions are not counted yet."** The device never changes a displayed stock number.
- Sign Out with unsent items: "You have N unsent items. Stay signed in until they are sent." → Stay signed in / Sign out anyway (items stay on the device for the next login of the same user).

## How it works (`assets/js/outbox.js`, hooked into `SafeSubmit.run` via `outbox:{type,summary,lines,dedupe,review}`)
- **Storage:** IndexedDB `malbcoff-outbox`, store `outbox`, index by scope `u<userId>b<branchId>`. Item: `id` (= the 6a idempotency key), `type`, `payload` (form fields or JSON **without** `_csrf`, `client_key`, `receive_nonce`), `summary`, `lines`, `review`, `dedupe`, `created_at`, `status` (queued · sending · sent · needs_action · discarded), `attempts`, `last_error`, `next_try_at`, `reported`.
- **No IndexedDB** (old/private browsers) → `Outbox.ready` is false → SafeSubmit shows the normal 6a **Retry** card. Owner, System Admin and impersonation get no `outbox-scope` meta tag = no outbox at all.
- **Sending:** one item at a time, oldest first (FIFO). Triggers: page load, `online` event, tab becomes visible, **Send now**, backoff timer **5 s → 15 s → 60 s → 120 s (then every 2 min)**. Each cycle first calls `actions/outbox_status.php` (signed in? as whom? fresh CSRF token) so a queued item is never sent under another user or with a stale token. Requests go through `SafeSubmit.attempt()` with header `X-Outbox-Send: 1`.
- **Lock:** Web Locks API (`navigator.locks`, `ifAvailable`) per scope, localStorage lease fallback. Two tabs never send together; and even if they did, the server idempotency key makes the second a harmless replay.
- **Results:** success or replayed ("already processed") → "Sent ✓" for ~4 s then removed · network/5xx/`in_progress` → stays queued, backoff · business refusal (422/403/409…) → **needs_action**, never retried, queue continues with the next item, reported to the owner · 401/419 → queue **paused** (nothing discarded); polls every 15 s and resumes by itself after login (another tab or page). Another user signed in → paused with a message, items untouched.
- **Duplicates:** same key can only exist once in IndexedDB; a second "Receive transfer" for the same transfer while one is queued is refused as "Already saved on this device" (`dedupe: transfer_receive:<id>`).
- **Cross-tab UI** stays in sync with BroadcastChannel (storage-event fallback).

## Server
- `actions/outbox_status.php` (GET, no state change): `{ok,user_id,branch_id,csrf}` or 401.
- `actions/outbox_report.php` (POST JSON, CSRF, branch staff only; user + branch from the **session**): `op=report` inserts into `outbox_reports` (UNIQUE `client_key` → idempotent), `op=discard` flags `staff_discarded`. Answers `{ok:true,stored:false}` when migration P3_004 is not imported (the phone never fails because of it).
- `actions/outbox_resolve.php` (owner only, CSRF): marks a report `resolved` with an optional note. Read-mostly; never touches stock.
- `pages/needs-action.php` (owner only, route `/needs-action`, `.htaccess` rewrite, sidebar + "More" entry with count, Dashboard card with count): tabs Open / Resolved, each item shows action, summary, **why**, details, branch, staff, saved-on-phone time, reported time, "Mark as resolved" + note.
- `actions/stock_in.php`: a request with header `X-Outbox-Send: 1` skips only the one-time **review nonce** check (`receive_nonce` lives in the old page/session; `confirmed=1` is still required and every other validation runs). `core/Idempotency::fingerprint()` now also ignores `receive_nonce`, so a re-send of a request whose answer was lost still matches the first attempt (otherwise 409).
- Migration **`database/migrations/P3_004_outbox_reports.sql`** (`CREATE TABLE IF NOT EXISTS`, re-runnable). `deploy/migrations.sql` (new in repo) = P3_001…P3_004 concatenated, all re-runnable.

## Security notes
Payloads hold only what the normal form sends; no password, no CSRF token, no nonce. The server re-validates everything with the session at send time (CSRF, role, branch, stock, serials). A user can only ever send items of their own scope; the report endpoint takes user/branch from the session. Anyone with the phone and the signed-in session could see queued text (product names, IMEIs) — same exposure as the page itself.

## Known limits (by design)
- A page cannot be **opened** while offline (the 6b offline page appears); only a page already open can queue. After a refresh offline the badge is not reachable, but the items survive and are sent on the next page load online.
- Product Quick Setup (Products page: new variant + stock) is not queued: creating a variant needs the server first.
- "Review → Open the form" does not refill the identifier boxes (the scanner flow cannot be refilled safely); it opens the right page/product/transfer and the Outbox shows exactly what was saved.
- `idempotency_keys` rows older than ~30 days may be deleted (see [[Migrations]]); an item queued longer than that is protected only by the normal checks (serial already registered, transfer already received).

## Files
New: `assets/js/outbox.js`, `actions/outbox_status.php`, `actions/outbox_report.php`, `actions/outbox_resolve.php`, `pages/needs-action.php`, `database/migrations/P3_004_outbox_reports.sql`, `deploy/migrations.sql`.
Changed: `assets/js/safe-submit.js` (queue hook, `attempt` export, banner extra line + action button), `assets/js/branch-transfers.js`, `assets/js/inventory-forward.js`, `pages/stock-in.php`, `actions/stock_in.php`, `core/Idempotency.php`, `core/helpers.php`, `index.php`, `.htaccess`, `partials/header.php`, `partials/footer.php`, `partials/sidebar.php`, `partials/mobile-nav.php`, `pages/dashboard.php`, `assets/css/app.css`, `DESIGN.md` §15.6.

## Acceptance (real Chrome via puppeteer-core, local Apache/MySQL; DB snapshot restored afterwards)
Offline = requests to the 4 mutation endpoints aborted (`net::ERR_INTERNET_DISCONNECTED`) while pages still load, plus one run with `setOfflineMode(true)`.
- [x] Receive Stock, Branch Transfer send and Receive Transfer each queue offline ("Saved on this device…"), nothing in the DB changes, badge "Queued: 3".
- [x] Items survive page navigation/reload; with two tabs open both online → every item sent **exactly once** (1 unit, 1 transfer, transfer received once, 3 idempotency rows) and **in order** (stock_in → transfer_forward → transfer_receive).
- [x] Same transfer queued twice → refused as duplicate; response-lost replay (server saved, device re-sends the same key) → marked sent, no second movement.
- [x] Server refusal (IMEI already registered) → `needs_action`, not retried, next item still sent, reported to `outbox_reports` (user 2 / branch 1), banner "1 item needs action", badge "Needs action: 1", panel shows reason + Review + Discard (confirmation). Owner: Dashboard card "1 saved stock action needs action", list shows branch/staff/action/reason/time, Mark as resolved + note works; staff Discard flags the owner row and removes the device row.
- [x] Backoff measured 5 → 15 → 60 → 120 → 120 s.
- [x] Expired session → queue pauses ("Please log in again to send your queued items."), nothing lost, server unchanged; after login in another tab it resumes by itself (~4 s) and sends once.
- [x] Sign Out with unsent items warns; Stay signed in keeps the session; Sign out anyway signs out; another user on the same phone sees no queued items and sends nothing; same user signing in again sends them.
- [x] No IndexedDB → old 6a Retry card.
- [x] 360 / 768 / 1280: badge 44px tall and inside the screen, no horizontal scroll (page, banner, hint, panel, owner list), panel + hint + banner + owner list text ≥14px, all buttons/inputs ≥44px.
- Not exercised: real phone airplane mode / Android Chrome, Safari IndexedDB eviction, Web Locks absence (lease fallback is code-reviewed only), Apache `mod_rewrite` on the live host for `/needs-action` (copy the `.htaccess` line).

Related: [[Stock In (Receive Stock)]] · [[Branch Transfers]] · [[Inventory]] · [[Conventions]] · [[Migrations]] · [[Deploy Package]]
