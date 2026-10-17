/* Malbcoff POS service worker (Patch 6b) — STATIC FILES ONLY.
 *
 *  - Caches CSS, JS, fonts, images/icons under assets/ (stale-while-revalidate; vendor files cache-first).
 *  - NEVER caches PHP pages, actions/*, ping, login/session/CSRF, or any non-GET request. Those always
 *    go to the network, so stock, sales and tokens are never stale.
 *  - If a page (navigation) request fails because there is no internet, it shows offline.html.
 *  - APP_VERSION comes from the registration URL (sw.js?v=<config version>), so the version lives in ONE place
 *    (config 'version' / APP_VERSION). Changing it creates a new cache and deletes the old ones on activate.
 *  - Turn off: config 'enable_service_worker' => false (sw-register.js then unregisters it and clears caches).
 */
'use strict';

const APP_VERSION = new URL(self.location.href).searchParams.get('v') || '0';
const CACHE_PREFIX = 'malbcoff-static-';
const CACHE_NAME = CACHE_PREFIX + APP_VERSION;

// Everything is relative to where sw.js lives, so it works at the web root and in a sub-folder.
const BASE = new URL('./', self.location.href);
const BASE_PATH = BASE.pathname;
const ASSETS_PATH = BASE_PATH + 'assets/';
const VENDOR_PATH = ASSETS_PATH + 'vendor/';
const OFFLINE_URL = new URL('offline.html', BASE).href;
const PRECACHE = [OFFLINE_URL, new URL('assets/favicon.svg', BASE).href, new URL('assets/icons/icon-192.png', BASE).href];
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
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    await Promise.all(PRECACHE.map(async (url) => {
      try {
        const response = await fetch(new Request(url, { cache: 'reload' }));
        if (response.ok) await cache.put(url, response);
      } catch (error) { /* offline during install: the page still works, offline page just isn't ready yet */ }
    }));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names
      .filter((name) => name.startsWith(CACHE_PREFIX) && name !== CACHE_NAME)
      .map((name) => caches.delete(name)));
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
  return (await refresh) || Response.error();
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

async function offlinePage() {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(OFFLINE_URL);
  if (cached) return cached;
  return new Response('No internet. Your data is safe. Reconnect and try again.', {
    status: 503,
    headers: { 'Content-Type': 'text/plain; charset=utf-8' }
  });
}

self.addEventListener('fetch', (event) => {
  if (disabled) return;
  const request = event.request;
  // Anything but GET (every save, login, logout) is left completely alone → straight to the network.
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (request.mode === 'navigate') {
    // PHP pages: network only. Only a failed connection shows the offline page; nothing is stored.
    event.respondWith(fetch(request).catch(() => offlinePage()));
    return;
  }

  if (!isStaticAsset(url)) return;       // actions/*, ping, JSON, anything else → network, untouched
  if (request.headers.has('range')) return;

  event.respondWith(url.pathname.startsWith(VENDOR_PATH) ? cacheFirst(request) : staleWhileRevalidate(request, event));
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
    event.waitUntil(deleteAllCaches());
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
