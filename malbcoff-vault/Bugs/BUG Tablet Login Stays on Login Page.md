---
tags: [bug, auth, service-worker, tablet]
status: fixed (local-tested; confirm on a real tablet after deploy)
fixed: 2026-10-18
---
# BUG: On tablets the correct email + password returns to the login page

**Symptom:** Tablets cannot sign in. Email and password are right, the page just comes back to the login form (no message). Phones, laptops and PCs are fine.

## Root cause
Two things together:

1. **Server rule** (`core/Security.php` → `guardAuthenticatedSession`): at login the session stores a hash of the browser's `User-Agent`. If a later request has a different User-Agent, the session is ended (`auth.session_rejected`, `reason: user_agent_changed`) and `index.php` sends the user to `/login` — with **no message**.
2. **Service worker (Patch 6b)**: `sw.js` answered every **page request** with `fetch(request)`. A request sent by a worker does not carry the page's User-Agent override. Tablets (Chrome on Android tablets and iPadOS Safari) open sites in **"desktop site" mode by default**, which is a User-Agent override. So:
   - login **POST** → not touched by the worker → sent with the tablet's *desktop* UA → session stores that UA;
   - the redirect to `/` is a **page request** → answered by the worker → sent with the worker's *own* UA → hash differs → session killed → back to `/login`.
   Phones use their normal UA (no override) and PCs have no override, so the two UAs match there.

**Reproduced locally in real Chrome** (CDP `Emulation.setUserAgentOverride` = tablet-like UA, worker active): login POST UA `Macintosh…`, next GET UA `Windows…`, audit row `auth.session_rejected / user_agent_changed`, browser ends on `/login`. Also reproduced with curl (login with one UA, GET with another).

Not the cause (checked): CSRF (token creation/check fine), cookie flags (`HttpOnly`, `SameSite=Lax`, `Secure` only on HTTPS, no domain), redirect target, `.htaccess` cache rules (PHP already sends `no-store`), login-page JavaScript (the page only loads `sw-register.js`; the form is a plain POST), CSS at 768–1024 px (nothing covers the form).

## Fix
- `sw.js` — **never intercepts a page, a form POST, `actions/*`, login, session or CSRF**; handles only GET of static files under `assets/`. A failure inside the worker falls back to a plain network request. New cache name `malbcoff-static-g2-<version>`; on `activate` every other `malbcoff-*` cache is deleted and the worker claims all pages (self-heal for old workers). The old "offline page for pages" behaviour is **gone on purpose** (it was the cause); `offline.html` stays in the folder but is unused.
- `assets/js/sw-register.js` — everything in `try/catch`; new `data-mode="auth"`: on login / change-password / maintenance pages it **never registers** a worker, it only calls `update()` on an existing one so an old worker is replaced. Kill switch kept (`enable_service_worker=false` → unregister + clear `malbcoff-*` caches on the next page load, any page).
- `login.php` — clear English messages: wrong password; **"Your security token expired. Please refresh the page and try again."**; **"Your browser did not keep its session. Please enable cookies, then refresh the page and try again."** (POST arrived with no session cookie); plus a one-time message after the server ended a session (idle/absolute timeout, browser changed, account revoked) via a short-lived `malbcoff_notice` cookie that holds only a whitelisted code. Form: no `autofocus`, `inputmode=email`, `autocapitalize/autocorrect/spellcheck` off, explicit `action`.
- `core/Security.php` — `loginDebug()` helpers, notice cookie, UA-mismatch audit row now also stores two 8-char hash prefixes (`stored`, `now`). **The UA check itself is unchanged (still strict).**
- `config/app.php` — `login_debug` (env `LOGIN_DEBUG`), **off by default**. When on: PHP error log lines `[Malbcoff login-debug][<request id>] …` (request, POST/CSRF result, session created, redirect target, UA-mismatch with both hashes, `index.no_session`) and a small "Login debug" box on the login page. Never logs a password, token or CSRF value.
- `.htaccess` — `no-store` (+ `Pragma`) forced for `login|logout|change-password|maintenance|ping|outbox_status`.php.
- `assets/css/app.css` — login inputs 16 px (no iPad zoom) / 48 px, button 48 px, form stacked above any overlay.
- `assets/js/safe-submit.js` — UUID fallback no longer throws when `crypto` is missing entirely.
- `index.php` — one debug line when there is no session. `maintenance.php`, `change-password.php` — auth mode for the worker script.

## Newer JS (checked)
`crypto.randomUUID`, `indexedDB`, `navigator.locks`, `BroadcastChannel`, `AbortController`, `serviceWorker` were already feature-guarded in `safe-submit.js` / `outbox.js` / `sw-register.js`; the login page loads none of them. The rest of the app uses optional chaining (`?.`) and `??` widely (Chrome 80+/Safari 13.1+, 2020). Not rewritten — an older tablet browser than that needs a browser update, but **login itself does not depend on any of it**.

## How to test on a real tablet
1. Deploy the package ([[Deploy Package]] #3). On the tablet: clear site data (see the package README) or just open the site twice.
2. Optional: set `'login_debug' => true` in `config/app.local.php`, sign in, look at the "Login debug" box and the PHP error log; **set it back to false**.
3. Sign in with a correct account → you must land on the Dashboard and stay signed in after pull-to-refresh and after opening POS / Inventory.
4. Rotate to portrait and landscape; the Sign In button and fields must be tappable and the page must not zoom when you tap a field.
5. Wrong password → red "Invalid email or password." Leave the login page open 40 min, then sign in → "Your security token expired…" or sign-in works (session file may still exist).
6. Server check: `security_audit_logs` must show **no** `auth.session_rejected` with `user_agent_changed` for the tablet after the fix (before the fix those rows are the proof of this bug).
7. Chrome on the tablet → `chrome://serviceworker-internals` (or desktop Chrome remote debugging → Application): the worker must be `g2`; Cache storage only `malbcoff-static-g2-*`.

## Local test results (real Chrome, DevTools protocol, tablet UA override, local DB)
- Before: login → back to login (reproduced). After: lands on Dashboard, stays signed in across 3 reloads with the worker active; POS, Inventory, Receive Stock open; logout works.
- Stale old worker (old `sw.js` installed, it then bounced the session as above) → deploy the fix → login page asks it to update → new worker active, old cache deleted, login works.
- Old-browser profile (no `randomUUID`, `indexedDB`, `serviceWorker`, `navigator.locks`, `BroadcastChannel`): login, 3 reloads, POS / Inventory / Receive Stock and logout all work, no uncaught JS errors.
- Tablet sizes 768×1024, 1024×768, 820×1180, 1180×820 (touch emulation): inputs and button hit-tested at their centres (nothing on top), button 48 px, inputs 16 px, not disabled, no horizontal scroll, no `autofocus`.
- Messages: wrong password, bad token, cookies cleared → each shows its English text. Kill switch off → worker unregistered and caches empty on the next load, user stays signed in.
- Not tested: a physical iPad / Android tablet, HTTPS + Cloudflare, POS sale / stock actions / outbox end-to-end after this fix (their JS was not changed except the UUID fallback; pages load and `SafeSubmit` / `Outbox` are present).

Related: [[Auth and Session]] · [[Service Worker and Offline Page]] · [[Patch 6b - Offline Shell and Cache]] · [[How to turn off the service worker]] · [[Conventions]]
