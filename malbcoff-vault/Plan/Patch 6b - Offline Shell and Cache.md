---
tags: [plan, patch, network]
---
# Patch 6b — Fast loading on weak internet (service worker, static files only)
**Status: DONE 2026-10-17.** No offline queue (that is [[Patch 6c - Offline Sale Queue]]). Feature note: [[Service Worker and Offline Page]]. Emergency switch: [[How to turn off the service worker]].

## What was built
- **`sw.js`** (web root, scope = app folder): caches **static files under `assets/` only** — CSS, JS, fonts, images/icons, scanner files. CSS/JS/images = stale-while-revalidate. `assets/vendor/**` (scanner/OCR, ~14 MB, never edited in place) = cache-first, so it is never downloaded twice. Cache name = `malbcoff-static-<APP_VERSION>`; on `activate` every other `malbcoff-static-*` cache is deleted.
- **Never cached:** PHP pages, `actions/*`, `ping`, login/session/CSRF, any POST (non-GET requests are not even intercepted). HTML responses are never stored, even if one is returned for a missing asset.
- **Page request fails (no internet)** → `offline.html` (branded, English: "No internet. Your data is safe. Reconnect and try again." + 48px **Retry**; reloads itself when the phone is back online). Nothing else is ever served for a page.
- **APP_VERSION** is the one version constant: config `version` / env `APP_VERSION` (default `1.0.0`). It is passed as `sw.js?v=<version>`; `sw.js` reads it. Change it → new worker + new cache, old cache deleted. (Asset URLs also carry `?v=filemtime`, so a forgotten bump still never serves stale JS/CSS.)
- **`assets/js/sw-register.js`** + `partials/pwa-register.php` (on every page incl. login, change-password, maintenance) registers the worker and sends it the list of static files the page already loaded, so the 2nd page load is served from the cache.
- **Switch:** config `enable_service_worker` (env `ENABLE_SERVICE_WORKER`, default **true**). `false` → `sw-register.js` tells the worker to stop, unregisters it and deletes its caches on the next page load. See [[How to turn off the service worker]].
- **Install on phone/tablet:** `manifest.webmanifest` ("Malbcoff POS", standalone, start `./`) + PNG icons made from the existing "M" logo in `assets/icons/` (192, 512, maskable 512, Apple 180) via `partials/pwa-head.php`.
- **Connection banner (extends 6a, `assets/js/safe-submit.js` + `app.css`)**: slim (14px, ~36px high), non-blocking. **Weak connection** (amber; ping > 3 s or no answer in 6 s) · **No internet. Your data is safe.** (red; `navigator.onLine` false or ping fails at once) · **Back online** (green, 3 s). Checks every 15 s, every 5 s while bad.
- **`.htaccess`**: `Cache-Control: public, max-age=31536000, immutable` only for `assets/**/*.css|js` requested with `?v=<digits>`; `no-cache` for `sw.js`, `offline.html`, `manifest.webmanifest`; MIME for `.webmanifest`/`.wasm`; `mod_deflate` for CSS/JS/SVG/manifest/wasm (not HTML, not JSON answers — they can carry tokens). All blocks are wrapped in `IfModule`, so a host without the module simply skips them.
- **Deferred JS:** every `<script src>` in `partials/footer.php` has `defer` (same order as before). Honest note: they were already at the end of `<body>`, so the gain is small; the big wins are the cache + far-future headers + gzip. Scanner/OCR files are still loaded on pos / inventory / add-item (not lazy-loaded — would need a behaviour change).

## Files
New: `sw.js`, `offline.html`, `manifest.webmanifest`, `assets/icons/{icon-192,icon-512,icon-maskable-512,apple-touch-icon}.png`, `assets/js/sw-register.js`, `partials/pwa-head.php`, `partials/pwa-register.php`.
Changed: `config/app.php` (+ `app.production.example.php`), `partials/header.php`, `partials/footer.php`, `login.php`, `change-password.php`, `maintenance.php`, `assets/js/safe-submit.js`, `assets/css/app.css` (net-banner block), `.htaccess`, `DESIGN.md` §15.6.

## Acceptance (real Chrome via DevTools protocol, local; DB snapshot restored afterwards)
- [x] 1st load registers the worker (scope = site root, `sw.js?v=1.0.0`); cache `malbcoff-static-1.0.0` created and warmed with app.css / app.js / safe-submit.js.
- [x] 2nd load: every CSS/JS request is answered by the worker.
- [x] Cache contains only `/assets/**` and `/offline.html` — no PHP page, action, ping or token.
- [x] Owner: two page requests give two different server request ids; a branch rename in the DB shows on the very next load (page not cached). POST/ping untouched.
- [x] Server stopped (real outage): `/pos` shows the offline page (English text, Retry 48px; 360/768/1280 no h-scroll); starting the server again → the page reloads itself. Banner shows "No internet. Your data is safe." → "Back online" → hides.
- [x] APP_VERSION `1.0.0` → `2.0.0`: old cache deleted, only `malbcoff-static-2.0.0` left, new worker active.
- [x] `enable_service_worker=false`: worker unregistered and all caches gone on the next load.
- [x] Login page registers the worker and links the manifest (name "Malbcoff POS", 512px icon).
- [x] Branch user (uid 2): POS sale (offline → Retry → exactly one sale), Receive Stock, Branch Transfer create + receive all save exactly once with the worker active; no JS errors. (The old 6a test script's Tagalog text assertions fail by design since Patch 7; all count/stock assertions pass.)
- Not exercised: real Android/iOS "Add to Home screen", mod_deflate/mod_expires (not loaded in local XAMPP; `.htaccess` cache headers verified with curl), HTTPS (localhost counts as secure for service workers).

Related: [[Patch 6a - Weak Internet Safe Retry]] · [[Conventions]] · [[Owner Updates Plan]]
