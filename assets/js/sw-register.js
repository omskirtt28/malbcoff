/* Service worker registration (Patch 6b, hardened by BUG Tablet Login).
 *
 * data-enabled="1"  → register sw.js?v=<APP_VERSION> and let it cache the static files already loaded.
 * data-enabled="0"  → the kill switch: unregister every service worker and delete every cache this app made.
 * data-mode="auth"  → login / change-password / maintenance pages: never register anything new here; only ask an
 *                     already installed worker to update itself, so an old worker is replaced (self-heal).
 * Never throws; the page (and the login form) work the same without a service worker.
 */
(function () {
  'use strict';
  try {
    var script = document.currentScript;
    var enabled = !script || script.getAttribute('data-enabled') !== '0';
    var authMode = !!script && script.getAttribute('data-mode') === 'auth';
    var version = (script && script.getAttribute('data-version')) || '0';
    if (!('serviceWorker' in navigator) || !navigator.serviceWorker) return;

    var clearCaches = function () {
      try {
        if (!window.caches || !caches.keys) return;
        caches.keys().then(function (names) {
          return Promise.all(names.filter(function (n) { return n.indexOf('malbcoff-') === 0; }).map(function (n) { return caches.delete(n); }));
        }).catch(function () {});
      } catch (error) { /* ignore */ }
    };

    var turnOff = function () {
      navigator.serviceWorker.getRegistrations().then(function (list) {
        return Promise.all(list.map(function (reg) {
          var worker = reg.active || reg.waiting || reg.installing;
          try { if (worker) worker.postMessage({ type: 'disable' }); } catch (error) { /* ignore */ } // it stops caching and clears its own caches first
          return reg.unregister();
        }));
      }).catch(function () {});
      clearCaches();
      setTimeout(clearCaches, 1500); // in case the old worker wrote one last file while this page was closing down
    };

    var staticFilesLoaded = function () {
      try {
        var base = new URL('assets/', document.baseURI).pathname;
        return performance.getEntriesByType('resource').map(function (e) { return e.name; }).filter(function (name) {
          try {
            var u = new URL(name);
            return u.origin === location.origin && u.pathname.indexOf(base) === 0;
          } catch (error) { return false; }
        });
      } catch (error) { return []; }
    };

    // Auth pages: an old worker may still be installed from a previous version → make it update now.
    var healExisting = function () {
      navigator.serviceWorker.getRegistrations().then(function (list) {
        list.forEach(function (reg) { try { reg.update().catch(function () {}); } catch (error) { /* ignore */ } });
      }).catch(function () {});
    };

    var turnOn = function () {
      navigator.serviceWorker.register('sw.js?v=' + encodeURIComponent(version), { updateViaCache: 'none' })
        .then(function (reg) {
          try { reg.update().catch(function () {}); } catch (error) { /* ignore */ }
          return navigator.serviceWorker.ready;
        })
        .then(function (reg) {
          var worker = reg.active || navigator.serviceWorker.controller;
          if (worker) worker.postMessage({ type: 'warm', urls: staticFilesLoaded() });
        })
        .catch(function () { /* HTTPS/permission problem: run without it */ });
    };

    var start = function () {
      try {
        if (!enabled) turnOff();
        else if (authMode) healExisting();
        else turnOn();
      } catch (error) { /* never break the page */ }
    };
    if (document.readyState === 'complete') start();
    else window.addEventListener('load', start);
  } catch (error) { /* never break the page */ }
})();
