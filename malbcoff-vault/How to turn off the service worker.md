---
tags: [runbook, network]
---
# How to turn off the service worker

Use this if the live site shows old files, odd screens after a deploy, or any problem that might come from the cache. It takes effect for each phone/computer **the next time it loads any page** (login page included). No data is lost: the worker only caches CSS/JS/images.

## Option 1 — config switch (normal way)
1. On the server open `config/app.local.php` (create it from `config/app.production.example.php` if it does not exist).
2. Set `'enable_service_worker' => false,`  
   (or set the environment variable `ENABLE_SERVICE_WORKER=false`).
3. Save. Reload the app once on a device: it unregisters the worker and deletes the `malbcoff-static-*` caches by itself.
4. To turn it back on, set it to `true` (or remove the line — default is on).

## Option 2 — one force-refresh after a deploy
Change `'version'` (or `APP_VERSION`) in the same file, e.g. `1.0.0` → `1.0.1`. The next page load installs a new worker and deletes the old cache. Use this after a deploy when you only want everyone to get fresh static files.

## Option 3 — emergency (the app itself is down or the config cannot be reached)
Replace `sw.js` in the web root with this self-removing worker. Browsers re-check `sw.js` on every visit (it is served with `Cache-Control: no-cache`):
```js
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil((async () => {
  for (const name of await caches.keys()) await caches.delete(name);
  await self.registration.unregister();
  for (const client of await self.clients.matchAll()) client.navigate(client.url);
})()));
```
Then restore the real `sw.js` from the project when the problem is fixed.

## Check it worked
Chrome → F12 → **Application** → **Service workers**: none listed (or "deleted"); **Cache storage**: no `malbcoff-static-*`. Pages and saving work exactly as before — the worker is only an extra layer.

Related: [[Service Worker and Offline Page]] · [[Patch 6b - Offline Shell and Cache]]
