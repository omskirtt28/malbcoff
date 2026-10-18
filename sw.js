/* Malbcoff POS service worker (Patch 6b, hardened by BUG Tablet Login) — STATIC FILES ONLY.
 *
 *  - Handles ONLY GET requests for CSS, JS, fonts, images/icons and scanner files under assets/.
 *  - NEVER touches a page (navigation), a form POST, actions/*, ping, login/session/CSRF or anything else:
 *    those requests are not intercepted at all, so the browser sends them itself, with its own cookies,
 *    headers and User-Agent. (A request re-issued by a worker can carry a different User-Agent than the
 *    page — on tablets using "desktop site" the server then ended the login session. See the vault note
 *    "BUG Tablet Login Stays on Login Page".)
 *  - A failure inside the worker falls back to a plain network request, so the worker can never block a file.
 *  - APP_VERSION comes from the registration URL (sw.js?v=<config version>). CACHE_NAME also carries SW_GENERATION,
 *    so this worker always uses a new cache and deletes every older malbcoff cache on activate (self-heal).
 *  - Turn off: config 'enable_service_worker' => false (sw-register.js then unregisters it and clears caches).
 */
'use strict';

const APP_VERSION = new URL(self.location.href).searchParams.get('v') || '0';
const SW_GENERATION = 'g2';
const CACHE_PREFIX = 'malbcoff-';              // every cache this app ever made starts with this
const CACHE_NAME = CACHE_PREFIX + 'static-' + SW_GENERATION + '-' + APP_VERSION;

// Everything is relative to where sw.js lives, so it works at the web root and in a sub-folder.
const BASE = new URL('./', self.location.href);
const ASSETS_PATH = BASE.pathname + 'assets/';
const VENDOR_PATH = ASSETS_PATH + 'vendor/';
let disabled = false; // set by the kill switch message; this worker then leaves every request alone
const STATIC_EXT = /\.(?:css|js|mjs|woff2?|ttf|otf|svg|png|jpe?g|gif|webp|ico|wasm|gz)$/i;

function isStaticAsset(url) {
  return url.origin === self.location.origin
    && url.pathname.startsWith(ASSETS_PATH)
    && !url.pathname.includes('..')
    && STATIC_EXT.test(url.pathname);
}

function isCacheable(response) {
  if (!response || response.status !== 200 || response.type !== 'basic') return false;
  const type = (response.headers.get('Content-Type') || '').toLowerCase();
  if (type.startsWith('text/html')) return false; // never keep a page that was served in place of a missing file
  return true;
}

self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting()); // nothing to pre-cache: a new worker takes over at once
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    try {
      const names = await caches.keys();
      await Promise.all(names
        .filter((name) => name.startsWith(CACHE_PREFIX) && name !== CACHE_NAME)
        .map((name) => caches.delete(name)));
    } catch (error) { /* cache storage unavailable: nothing to clean */ }
    await self.clients.claim();
  })());
});

async function staleWhileRevalidate(request, event) {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(request);
  const refresh = fetch(request).then((response) => {
    if (isCacheable(response)) cache.put(request, response.clone());
    return response;
  }).catch(() => null);
  if (cached) {
    event.waitUntil(refresh);
    return cached;
  }
  return (await refresh) || fetch(request);
}

// Large vendor files (scanner / OCR, ~14 MB) never change in place → cache-first, no background re-download.
async function cacheFirst(request) {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(request);
  if (cached) return cached;
  const response = await fetch(request);
  if (isCacheable(response)) cache.put(request, response.clone());
  return response;
}

self.addEventListener('fetch', (event) => {
  if (disabled) return;
  const request = event.request;
  // Only GET of a static file. Pages, form POSTs, actions, login, session, CSRF = not intercepted.
  if (request.method !== 'GET') return;
  if (request.mode === 'navigate' || request.destination === 'document' || request.destination === 'iframe') return;
  if (request.headers.has('range')) return;
  let url;
  try { url = new URL(request.url); } catch (error) { return; }
  if (!isStaticAsset(url)) return;

  const answer = url.pathname.startsWith(VENDOR_PATH) ? cacheFirst(request) : staleWhileRevalidate(request, event);
  event.respondWith(answer.catch(() => fetch(request)));
});

async function deleteAllCaches() {
  const names = await caches.keys();
  await Promise.all(names.filter((name) => name.startsWith(CACHE_PREFIX)).map((name) => caches.delete(name)));
}

self.addEventListener('message', (event) => {
  const data = event.data;
  // Kill switch (config enable_service_worker = false): stop touching requests and drop every cache.
  if (data && data.type === 'disable') {
    disabled = true;
    event.waitUntil(deleteAllCaches().catch(() => {}));
    return;
  }
  // The page lists the static files it already loaded, so the 2nd page load is served from the cache.
  if (disabled || !data || data.type !== 'warm' || !Array.isArray(data.urls)) return;
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    for (const raw of data.urls.slice(0, 80)) {
      try {
        const url = new URL(raw, self.location.href);
        if (!isStaticAsset(url) || url.pathname.startsWith(VENDOR_PATH)) continue;
        const request = new Request(url.href);
        if (await cache.match(request)) continue;
        const response = await fetch(request); // normally answered by the browser's own HTTP cache
        if (isCacheable(response)) await cache.put(request, response);
      } catch (error) { /* skip this file */ }
    }
  })());
});
