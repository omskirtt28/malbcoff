---
tags: [feature, network, pwa]
---
# Service Worker and Offline Page (Patch 6b)

The app is server-rendered PHP, so the service worker only speeds up **static files**. It never touches data, pages or logins.

> **Changed by [[BUG Tablet Login Stays on Login Page]] (2026-10-18):** the worker no longer answers page requests at all, so the old "offline page when there is no internet" is gone (a worker-sent request lost the browser User-Agent and logged tablets out). `offline.html` is still in the folder but nothing uses it. The connection banner and the outbox still work (they are page JavaScript).

## Rules (do not break)
- Cache only `assets/**` static files. **Never** PHP pages, `actions/*`, `ping`, login/session/CSRF, or any non-GET.
- One version constant: config `version` / env `APP_VERSION` → `sw.js?v=<version>` → cache `malbcoff-static-g2-<version>` (`g2` = worker generation; on activate every other `malbcoff-*` cache is deleted). Bump it on every deploy that changes `sw.js` behaviour or static files you do not want served stale.
- Switch: config `enable_service_worker` (default true). See [[How to turn off the service worker]].
- Register/clean-up script is `assets/js/sw-register.js`; it is included by `partials/pwa-register.php` on **every** page (also login). New standalone pages must include `partials/pwa-head.php` in `<head>` and `partials/pwa-register.php` before `</body>`.
- New static folders outside `assets/` are not cached on purpose.

## How a request is handled (`sw.js`)
| Request | Handling |
|---|---|
| Non-GET (saves, login, logout) | not intercepted → network |
| Page (navigation / document / iframe) | **not intercepted** → the browser asks the network itself |
| `assets/vendor/**` | cache-first (large scanner/OCR files) |
| other `assets/**` (css/js/fonts/images/wasm) | stale-while-revalidate |
| everything else (`actions/*`, `ping`, JSON) | not intercepted → network |

## Connection banner
`assets/js/safe-submit.js` (`Banner`): **Weak connection** / **No internet. Your data is safe.** / **Back online** (3 s). Uses `navigator.onLine` + `actions/ping.php` (6a). Styles: `.net-banner`, `.is-offline`, `.is-back` in `assets/css/app.css`.

## Install on a phone/tablet
`manifest.webmanifest` + `assets/icons/*.png` (made from `assets/favicon.svg`). Name "Malbcoff POS", opens the app root (login or home).

## Server (`.htaccess`)
Far-future cache only for versioned CSS/JS (`?v=<digits>`); `no-cache` for `sw.js`, `offline.html`, `manifest.webmanifest`; `no-store` for login / logout / change-password / maintenance / ping / outbox_status; gzip for text assets if `mod_deflate` exists.

Related: [[Patch 6b - Offline Shell and Cache]] · [[Patch 6a - Weak Internet Safe Retry]] · [[Conventions]]
