---
tags: [plan, patch, network]
---
# Patch 6b — Fast loading on weak net (service worker)

Pages are server-rendered, so only static files can be cached. Heavy assets: `app.css` 205 KB, scanner/OCR vendor ~15 MB (only on pos/inventory/add-item).

## Files
- `sw.js` (new, root so scope = app): cache-first for `assets/**` (versioned by `?v=`), network-first with 6 s timeout for HTML, `offline.html` fallback.
- `offline.html` (new): "Walang internet — bumabalik kapag may net" + pending-actions count.
- `manifest.webmanifest` (new) + icons from `assets/favicon.svg` (installable to phone home screen).
- `partials/header.php`: link manifest; `partials/footer.php`: register SW.
- Lazy-load OCR/zxing only when scanner button pressed (check `partials/footer.php` L12-20 that loads them on page load for pos/inventory/add-item).

## Acceptance
- [ ] 2nd load of any page transfers < 100 KB of static (DevTools Network).
- [ ] Airplane mode → opening app shows offline page, not browser error.
- [ ] After deploy, new asset `?v=` busts cache (no stale JS).
- [ ] No caching of `actions/*` or authenticated JSON.
