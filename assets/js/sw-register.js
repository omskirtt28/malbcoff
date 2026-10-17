/* Service worker registration (Patch 6b).
 *
 * data-enabled="1"  → register sw.js?v=<APP_VERSION> and let it cache the static files already loaded.
 * data-enabled="0"  → the kill switch: unregister every service worker and delete every cache this app made.
 * Never throws; the app works the same without a service worker.
 */
(function () {
  'use strict';
  var script = document.currentScript;
  var enabled = !script || script.getAttribute('data-enabled') !== '0';
  var version = (script && script.getAttribute('data-version')) || '0';
  if (!('serviceWorker' in navigator)) return;

  function clearCaches() {
    if (!window.caches || !caches.keys) return;
    caches.keys().then(function (names) {
      return Promise.all(names.filter(function (n) { return n.indexOf('malbcoff-') === 0; }).map(function (n) { return caches.delete(n); }));
    }).catch(function () {});
  }

  function turnOff() {
    navigator.serviceWorker.getRegistrations().then(function (list) {
      return Promise.all(list.map(function (reg) {
        var worker = reg.active || reg.waiting || reg.installing;
        if (worker) worker.postMessage({ type: 'disable' }); // it stops caching and clears its own caches first
        return reg.unregister();
      }));
    }).catch(function () {});
    clearCaches();
    setTimeout(clearCaches, 1500); // in case the old worker wrote one last file while this page was closing down
  }

  function staticFilesLoaded() {
    try {
      var base = new URL('assets/', document.baseURI).pathname;
      return performance.getEntriesByType('resource').map(function (e) { return e.name; }).filter(function (name) {
        try {
          var u = new URL(name);
          return u.origin === location.origin && u.pathname.indexOf(base) === 0;
        } catch (error) { return false; }
      });
    } catch (error) { return []; }
  }

  function turnOn() {
    navigator.serviceWorker.register('sw.js?v=' + encodeURIComponent(version), { updateViaCache: 'none' })
      .then(function () { return navigator.serviceWorker.ready; })
      .then(function (reg) {
        var worker = reg.active || navigator.serviceWorker.controller;
        if (worker) worker.postMessage({ type: 'warm', urls: staticFilesLoaded() });
      })
      .catch(function () { /* HTTPS/permission problem: run without it */ });
  }

  function start() { if (enabled) turnOn(); else turnOff(); }
  if (document.readyState === 'complete') start();
  else window.addEventListener('load', start);
})();
