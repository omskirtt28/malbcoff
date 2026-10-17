/* Offline outbox (Patch 6c-1: Receive Stock, Branch Transfer send, Receive Transfer — Patch 6c-2: POS sales).
 *
 * When a save cannot be completed because of the network, SafeSubmit (assets/js/safe-submit.js) hands the intention to
 * Outbox.enqueueFrom(). It is stored in IndexedDB ("outbox" store) under the SAME UUID that Patch 6a uses as the
 * idempotency key, so the server can never count it twice. Items are sent one at a time, oldest first, whenever
 * the browser is online, on page load, on "Send now" and with a backoff timer.
 *
 * Rules (see malbcoff-vault/Conventions.md → "Offline outbox"):
 *   - the device never changes any displayed stock number; the server stays the single source of truth
 *   - payloads hold only what the normal form sends: no password, no CSRF token, no page nonce
 *   - items belong to one user + branch; another user signing in on the same phone never sends or sees them
 *   - a definite business refusal becomes "needs action" (reported to the owner), never an endless retry
 *   - an expired login pauses the queue; nothing is ever discarded automatically
 *   - no IndexedDB → Outbox.ready resolves false and SafeSubmit keeps the Patch 6a Retry card
 *   - a queued SALE (6c-2) also holds its devices / accessory quantities on this device (Outbox.holds()) so the POS can
 *     refuse to add them again; this is only a hint — the server stays the source of truth and re-checks everything
 */
(function () {
  'use strict';
  if (window.Outbox) return;

  var ENDPOINTS = {
    stock_in: 'actions/stock_in.php',
    transfer_forward: 'actions/forward_inventory.php',
    transfer_receive: 'actions/receive_transfer.php',
    sale: 'actions/complete_sale.php'
  };
  var TYPE_LABEL = {
    stock_in: 'Receive Stock',
    transfer_forward: 'Branch Transfer',
    transfer_receive: 'Receive Transfer',
    sale: 'Sale'
  };
  var REVIEW_PATH = { 'stock-in': 'receive-stock', inventory: 'inventory', 'branch-transfers': 'branch-transfers' };
  var HINT_PAGES = ['dashboard', 'inventory', 'stock-monitoring', 'stock-movement', 'branch-transfers', 'stock-in', 'pos', 'sales-records'];
  var STRIP_FIELDS = ['_csrf', 'client_key', 'receive_nonce'];
  // Patch 6c-2: warn (never block selling) when too many sales wait, or the oldest one waited too long.
  var WARN_QUEUED_SALES = 50;
  var WARN_AGE_MS = 24 * 60 * 60 * 1000;
  var WARN_TEXT = 'Please connect to the internet to send your sales.';

  var DB_NAME = 'malbcoff-outbox';
  var STORE = 'outbox';
  var BACKOFF_S = [5, 15, 60, 120];
  var SENT_SHOW_MS = 4000;
  var SALE_SENT_SHOW_MS = 15000; // a sent sale stays visible longer so the cashier can read the real sale number
  var ALL_SENT_MS = 6000;
  var REQUEST_TIMEOUT_MS = 25000;
  var LEASE_MS = 30000;

  /* ------------------------------------------------------------------ who owns this outbox */
  var scope = null;
  try {
    var meta = document.querySelector('meta[name="outbox-scope"]');
    scope = meta ? JSON.parse(meta.content) : null;
  } catch (error) { scope = null; }

  if (!scope || !scope.user || !scope.branch) {
    // Owner, System Admin, impersonation or logged out: no outbox, everything behaves like Patch 6a.
    window.Outbox = {
      enabled: false,
      ready: Promise.resolve(false),
      enqueueFrom: function () { return Promise.resolve(null); },
      loaded: Promise.resolve(false),
      openPanel: function () {},
      unsentCount: function () { return 0; },
      holds: function () { return {}; }
    };
    return;
  }

  var scopeKey = 'u' + scope.user + 'b' + scope.branch;
  var tabId = Math.random().toString(36).slice(2);

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }
  function plural(n, one, many) { return n === 1 ? one : many; }

  /* ------------------------------------------------------------------ IndexedDB */
  var dbPromise = null;
  function openDb() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise(function (resolve, reject) {
      if (!window.indexedDB) { reject(new Error('IndexedDB is not available')); return; }
      var request;
      try { request = window.indexedDB.open(DB_NAME, 1); } catch (error) { reject(error); return; }
      request.onupgradeneeded = function () {
        var db = request.result;
        if (!db.objectStoreNames.contains(STORE)) {
          var store = db.createObjectStore(STORE, { keyPath: 'id' });
          store.createIndex('scope', 'scope', { unique: false });
        }
      };
      request.onsuccess = function () {
        var db = request.result;
        db.onversionchange = function () { db.close(); dbPromise = null; };
        resolve(db);
      };
      request.onerror = function () { reject(request.error || new Error('IndexedDB error')); };
      request.onblocked = function () { reject(new Error('IndexedDB blocked')); };
    });
    dbPromise.catch(function () { dbPromise = null; });
    return dbPromise;
  }

  /** Runs fn(store, resolve) inside one transaction and resolves with whatever fn passes to resolve. */
  function withStore(mode, fn) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(STORE, mode);
        var result;
        tx.oncomplete = function () { resolve(result); };
        tx.onerror = function () { reject(tx.error); };
        tx.onabort = function () { reject(tx.error || new Error('Transaction aborted')); };
        try { fn(tx.objectStore(STORE), function (value) { result = value; }); } catch (error) { try { tx.abort(); } catch (e) { /* ignore */ } reject(error); }
      });
    });
  }

  function loadAll() {
    return withStore('readonly', function (store, done) {
      var request = store.index('scope').getAll(scopeKey);
      request.onsuccess = function () { done(request.result || []); };
    }).then(function (items) {
      return items.sort(function (a, b) { return (a.created_at - b.created_at) || (a.id < b.id ? -1 : 1); });
    });
  }

  /** Read-modify-write on one item inside one transaction. mutate(item) returns the new item, or null to delete it. */
  function updateItem(id, mutate) {
    return withStore('readwrite', function (store, done) {
      var get = store.get(id);
      get.onsuccess = function () {
        var item = get.result;
        if (!item || item.scope !== scopeKey) { done(null); return; }
        var next = mutate(item);
        if (next === null) { store.delete(id); done(null); } else { store.put(next); done(next); }
      };
    });
  }

  function addItem(item) {
    return withStore('readwrite', function (store, done) {
      var run = function (existing) {
        if (item.dedupe) {
          for (var i = 0; i < existing.length; i++) {
            var e = existing[i];
            if (e.dedupe === item.dedupe && (e.status === 'queued' || e.status === 'sending')) { done({ added: false, duplicate: true, item: e }); return; }
          }
        }
        var add = store.add(item);
        add.onsuccess = function () { done({ added: true, item: item }); };
        add.onerror = function (event) {
          // Same id already stored (the same intention queued twice): it is already safe on the device.
          event.preventDefault();
          done({ added: false, duplicate: true, item: item });
        };
      };
      if (item.dedupe) {
        var all = store.index('scope').getAll(scopeKey);
        all.onsuccess = function () { run(all.result || []); };
      } else {
        run([]);
      }
    });
  }

  var ready = openDb().then(function () {
    return withStore('readonly', function (store, done) { var c = store.count(); c.onsuccess = function () { done(true); }; });
  }).then(function () { return true; }, function () { return false; });

  /* ------------------------------------------------------------------ state shared with the UI */
  var items = [];          // this scope's items, oldest first
  var running = false;     // a send cycle is running in this tab
  var sendingNow = false;  // a request is in flight in this tab
  var pausedReason = null; // 'auth' | 'other-user'
  var failStreak = 0;
  var allSentUntil = 0;
  var lastHoldsSig = '{}';
  var resolveLoaded = null;
  var loaded = new Promise(function (resolve) { resolveLoaded = resolve; }); // resolves after the first read of this device's items

  function visible() { return items.filter(function (i) { return i.status !== 'discarded'; }); }
  function sentKeepMs(item) { return item.type === 'sale' ? SALE_SENT_SHOW_MS : SENT_SHOW_MS; }
  function isUnsent(item) { return item.status === 'queued' || item.status === 'sending'; }
  function countBy() {
    var c = { queued: 0, sending: 0, sent: 0, needs: 0, unsentSales: 0, oldestSale: 0 };
    items.forEach(function (i) {
      if (i.status === 'queued') c.queued++;
      else if (i.status === 'sending') c.sending++;
      else if (i.status === 'sent') c.sent++;
      else if (i.status === 'needs_action') c.needs++;
      if (i.type === 'sale' && isUnsent(i)) {
        c.unsentSales++;
        if (!c.oldestSale || i.created_at < c.oldestSale) c.oldestSale = i.created_at;
      }
    });
    c.unsent = c.queued + c.sending;
    c.warn = c.unsentSales > WARN_QUEUED_SALES || (c.oldestSale > 0 && Date.now() - c.oldestSale > WARN_AGE_MS);
    return c;
  }

  /** What the unsent sales of this device use: 'unit:<id>' → 1, 'product:<id>' → total quantity. Only a hint for the POS. */
  function holdsMap() {
    var map = {};
    items.forEach(function (i) {
      if (i.type !== 'sale' || !isUnsent(i)) return;
      (i.holds || []).forEach(function (h) { map[h.key] = (map[h.key] || 0) + (Number(h.qty) || 1); });
    });
    return map;
  }

  /* ------------------------------------------------------------------ other tabs */
  var channel = null;
  try { if (typeof BroadcastChannel === 'function') channel = new BroadcastChannel('malbcoff-outbox-' + scopeKey); } catch (error) { channel = null; }
  function announce() {
    try {
      if (channel) channel.postMessage({ t: 'changed', from: tabId });
      else window.localStorage.setItem('malbcoff-outbox-ping-' + scopeKey, String(Date.now()) + tabId);
    } catch (error) { /* ignore */ }
  }
  if (channel) channel.onmessage = function (event) { if (event.data && event.data.from !== tabId) refresh(false); };
  else window.addEventListener('storage', function (event) { if (event.key === 'malbcoff-outbox-ping-' + scopeKey) refresh(false); });

  /* ------------------------------------------------------------------ lock: only one tab sends */
  function withLock(fn) {
    if (navigator.locks && typeof navigator.locks.request === 'function') {
      return navigator.locks.request('malbcoff-outbox-' + scopeKey, { ifAvailable: true }, function (lock) {
        if (!lock) return false;
        return fn().then(function () { return true; });
      });
    }
    // Fallback lease in localStorage (old browsers): good enough because every send is also idempotent on the server.
    var key = 'malbcoff-outbox-lease-' + scopeKey;
    function read() { try { return JSON.parse(window.localStorage.getItem(key) || 'null'); } catch (error) { return null; } }
    function write() { try { window.localStorage.setItem(key, JSON.stringify({ tab: tabId, until: Date.now() + LEASE_MS })); } catch (error) { /* ignore */ } }
    var current = read();
    if (current && current.tab !== tabId && current.until > Date.now()) return Promise.resolve(false);
    write();
    var again = read();
    if (!again || again.tab !== tabId) return Promise.resolve(false);
    var renew = setInterval(write, LEASE_MS / 3);
    return fn().then(function () { return true; }).then(function (v) { release(); return v; }, function (e) { release(); throw e; });
    function release() { clearInterval(renew); var cur = read(); if (cur && cur.tab === tabId) { try { window.localStorage.removeItem(key); } catch (error) { /* ignore */ } } }
  }

  /* ------------------------------------------------------------------ payloads */
  function serialize(body) {
    if ((typeof FormData !== 'undefined' && body instanceof FormData) || (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams)) {
      var fields = [];
      body.forEach(function (value, name) {
        if (typeof value !== 'string' || STRIP_FIELDS.indexOf(name) !== -1) return;
        fields.push([name, value]);
      });
      return { kind: 'form', fields: fields };
    }
    var copy = JSON.parse(JSON.stringify(body || {}));
    STRIP_FIELDS.forEach(function (name) { delete copy[name]; });
    return { kind: 'json', body: copy };
  }

  /** Adds/replaces fields that were not part of the form itself (POS: device time + temporary receipt number). */
  function mergeExtra(payload, extra) {
    if (!extra) return;
    Object.keys(extra).forEach(function (name) {
      var value = String(extra[name]);
      if (payload.kind === 'form') {
        payload.fields = payload.fields.filter(function (pair) { return pair[0] !== name; });
        payload.fields.push([name, value]);
      } else {
        payload.body[name] = value;
      }
    });
  }

  function buildBody(item, csrf) {
    var payload = item.payload || {};
    if (payload.kind === 'form') {
      var params = new URLSearchParams();
      (payload.fields || []).forEach(function (pair) { params.append(pair[0], pair[1]); });
      params.set('_csrf', csrf);
      return params;
    }
    return Object.assign({}, payload.body || {}, { _csrf: csrf });
  }

  /* ------------------------------------------------------------------ enqueue (called by SafeSubmit) */
  async function enqueueFrom(opts, key, reason) {
    if (!(await ready)) return null;
    var cfg = opts.outbox;
    if (!cfg || !ENDPOINTS[cfg.type]) return null;
    var payload = serialize(opts.getBody());
    mergeExtra(payload, typeof cfg.extra === 'function' ? cfg.extra() : cfg.extra);
    var holds = (cfg.holds || []).filter(function (h) { return h && h.key; }).map(function (h) { return { key: String(h.key), qty: Math.max(1, Number(h.qty) || 1) }; }).slice(0, 100);
    var item = {
      id: key,
      scope: scopeKey,
      userId: scope.user,
      branchId: scope.branch,
      type: cfg.type,
      payload: payload,
      summary: String(cfg.summary || TYPE_LABEL[cfg.type] || '').slice(0, 255),
      lines: (cfg.lines || []).map(String).slice(0, 120),
      review: cfg.review || null,
      dedupe: cfg.dedupe || '',
      holds: holds,                               // POS sale: what this sale uses, so it cannot be added to another cart here
      receipt: cfg.receipt ? String(cfg.receipt).slice(0, 60) : '',
      result_no: '',
      created_at: Number(cfg.madeAt) > 0 ? Number(cfg.madeAt) : Date.now(),
      status: 'queued',
      attempts: 0,
      last_error: reason === 'auth' ? 'Waiting for you to log in again.' : '',
      next_try_at: 0,
      reported: false,
      sent_at: 0
    };
    var result = await addItem(item);
    await refresh(true);
    if (result && (result.added || result.duplicate)) {
      schedule(reason === 'auth' ? 500 : 4000);
      return { ok: true, duplicate: !!result.duplicate, item: result.item };
    }
    return null;
  }

  /* ------------------------------------------------------------------ sending */
  async function fetchStatus() {
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var timer = controller ? setTimeout(function () { controller.abort(); }, 10000) : null;
    try {
      var response = await fetch('actions/outbox_status.php?t=' + Date.now(), {
        cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller ? controller.signal : undefined
      });
      if (response.status === 401) return { kind: 'auth' };
      var data = null;
      try { data = await response.json(); } catch (error) { data = null; }
      if (!response.ok || !data || data.ok !== true || !data.csrf) return { kind: 'network' };
      return { kind: 'ok', userId: Number(data.user_id), csrf: String(data.csrf) };
    } catch (error) {
      return { kind: 'network' };
    } finally {
      if (timer) clearTimeout(timer);
    }
  }

  function backoffMs() {
    return BACKOFF_S[Math.min(Math.max(failStreak - 1, 0), BACKOFF_S.length - 1)] * 1000;
  }

  var timer = null;
  var timerAt = 0;
  function schedule(ms) {
    var at = Date.now() + ms;
    if (timer && timerAt <= at) return;
    clearTimeout(timer);
    timerAt = at;
    timer = setTimeout(function () { timer = null; timerAt = 0; processCycle(false); }, ms);
  }

  async function processCycle(force) {
    if (running || !(await ready)) return;
    running = true;
    try {
      var got = await withLock(function () { return runLocked(force); });
      if (got === false) schedule(2500); // another tab is sending right now; look again shortly
    } catch (error) {
      schedule(30000);
    } finally {
      running = false;
      sendingNow = false;
      await refresh(true);
    }
  }

  async function runLocked(force) {
    await refresh(false);
    // We hold the lock: anything still marked "sending" was left behind by a closed tab.
    var stale = items.filter(function (i) { return i.status === 'sending'; });
    for (var s = 0; s < stale.length; s++) await updateItem(stale[s].id, function (it) { it.status = 'queued'; return it; });
    // Items already shown as "Sent" earlier are cleaned up here.
    var done = items.filter(function (i) { return i.status === 'sent' && Date.now() - i.sent_at > sentKeepMs(i); });
    for (var d = 0; d < done.length; d++) await updateItem(done[d].id, function () { return null; });
    await refresh(false);

    var queued = items.filter(function (i) { return i.status === 'queued'; });
    var toReport = items.filter(function (i) { return (i.status === 'needs_action' && !i.reported) || i.status === 'discarded'; });
    if (!queued.length && !toReport.length) { pausedReason = null; return; }

    var status = await fetchStatus();
    if (status.kind === 'auth') { pausedReason = 'auth'; await refresh(false); schedule(15000); return; }
    if (status.kind === 'network') {
      failStreak++;
      if (queued.length) {
        await updateItem(queued[0].id, function (it) { it.attempts++; it.last_error = 'Could not reach the server.'; it.next_try_at = Date.now() + backoffMs(); return it; });
      }
      await refresh(false);
      schedule(backoffMs());
      return;
    }
    if (status.userId !== scope.user) { pausedReason = 'other-user'; await refresh(false); schedule(30000); return; }
    pausedReason = null;
    var csrf = status.csrf;
    var sentAny = false;

    // 1) send queued items, strictly oldest first
    for (;;) {
      await refresh(false);
      var head = items.filter(function (i) { return i.status === 'queued'; })[0];
      if (!head) break;
      if (!force && head.next_try_at > Date.now()) { schedule(Math.max(head.next_try_at - Date.now(), 1000)); break; }

      var claimed = await updateItem(head.id, function (it) { if (it.status !== 'queued') return it; it.status = 'sending'; return it; });
      if (!claimed || claimed.status !== 'sending') continue;
      sendingNow = true;
      await refresh(false);

      var result = null;
      var csrfRetried = false;
      for (;;) {
        result = await window.SafeSubmit.attempt({
          url: ENDPOINTS[head.type],
          timeout: REQUEST_TIMEOUT_MS,
          fromOutbox: true,
          getBody: (function (csrfNow) { return function () { return buildBody(head, csrfNow); }; })(csrf)
        }, head.id);
        if (result.kind === 'csrf' && result.token && !csrfRetried) { csrf = result.token; csrfRetried = true; continue; }
        break;
      }
      sendingNow = false;

      if (result.kind === 'success') {
        failStreak = 0;
        sentAny = true;
        var realNo = result.data && result.data.sale_no ? String(result.data.sale_no) : '';
        await updateItem(head.id, function (it) { it.status = 'sent'; it.sent_at = Date.now(); it.last_error = ''; if (realNo) it.result_no = realNo; return it; });
        setTimeout(function () { refresh(true); }, sentKeepMs(head) + 200);
      } else if (result.kind === 'business') {
        failStreak = 0;
        await updateItem(head.id, function (it) { it.status = 'needs_action'; it.attempts++; it.last_error = result.message || 'The server refused this action.'; return it; });
      } else if (result.kind === 'auth' || result.kind === 'csrf') {
        await updateItem(head.id, function (it) { it.status = 'queued'; it.last_error = 'Waiting for you to log in again.'; return it; });
        pausedReason = 'auth';
        await refresh(false);
        schedule(15000);
        break;
      } else {
        failStreak++;
        await updateItem(head.id, function (it) { it.status = 'queued'; it.attempts++; it.last_error = 'Could not reach the server.'; it.next_try_at = Date.now() + backoffMs(); return it; });
        await refresh(false);
        schedule(backoffMs());
        break;
      }
      await refresh(false);
    }

    if (sentAny && !items.some(function (i) { return i.status === 'queued' || i.status === 'sending'; })) {
      allSentUntil = Date.now() + ALL_SENT_MS;
      setTimeout(function () { refresh(false); }, ALL_SENT_MS + 200);
    }

    // 2) tell the owner about items that need action (and about items the staff discarded)
    if (!pausedReason) await syncReports(csrf);
  }

  async function syncReports(csrf) {
    var pending = items.filter(function (i) { return (i.status === 'needs_action' && !i.reported) || i.status === 'discarded'; });
    var failed = false;
    for (var n = 0; n < pending.length; n++) {
      var item = pending[n];
      var reportBody = {
        op: 'report', _csrf: csrf, action_type: item.type, summary: item.summary,
        details: (item.lines || []).join('\n').slice(0, item.type === 'sale' ? 1900 : 600), reason: item.last_error || 'The server refused this action.', queued_at: item.created_at
      };
      if (!item.reported) {
        var report = await window.SafeSubmit.attempt({ url: 'actions/outbox_report.php', timeout: 15000, getBody: function () { return reportBody; } }, item.id);
        if (report.kind !== 'success') { failed = true; if (report.kind === 'auth' || report.kind === 'csrf') break; continue; }
        await updateItem(item.id, function (it) { it.reported = true; return it; });
      }
      if (item.status === 'discarded') {
        var discard = await window.SafeSubmit.attempt({ url: 'actions/outbox_report.php', timeout: 15000, getBody: function () { return { op: 'discard', _csrf: csrf }; } }, item.id);
        if (discard.kind !== 'success') { failed = true; continue; }
        await updateItem(item.id, function () { return null; });
      }
    }
    await refresh(false);
    if (failed) schedule(60000);
  }

  /* ------------------------------------------------------------------ UI: badge, hint, banner */
  var badge = null;
  var hint = null;

  function buildBadge() {
    if (badge) return;
    var host = document.querySelector('.topbar-actions');
    if (!host) return;
    badge = el('button', 'outbox-badge');
    badge.type = 'button';
    badge.hidden = true;
    badge.setAttribute('aria-label', 'Open Outbox');
    badge.appendChild(el('span', 'outbox-badge-label', ''));
    badge.appendChild(el('span', 'outbox-badge-alert', ''));
    badge.addEventListener('click', function () { openPanel(); });
    host.insertBefore(badge, host.firstChild);
  }

  function renderBadge(c) {
    buildBadge();
    if (!badge) return;
    var total = c.unsent + c.needs + c.sent;
    badge.hidden = total === 0 && !pausedReason;
    var label = c.unsent > 0 ? 'Queued: ' + c.unsent : (c.needs > 0 ? 'Needs action: ' + c.needs : (c.sent > 0 ? 'Sent ✓' : 'Outbox'));
    badge.querySelector('.outbox-badge-label').textContent = label;
    var alertNode = badge.querySelector('.outbox-badge-alert');
    alertNode.hidden = !(c.unsent > 0 && c.needs > 0);
    alertNode.textContent = c.needs > 0 ? String(c.needs) : '';
    badge.classList.toggle('is-needs', c.unsent === 0 && c.needs > 0);
    badge.classList.toggle('is-paused', !!pausedReason);
    badge.setAttribute('aria-label', 'Open Outbox. ' + label + (alertNode.hidden ? '' : ', ' + c.needs + ' need action'));
  }

  function renderHint(c) {
    var onHintPage = HINT_PAGES.some(function (p) { return document.body.classList.contains('page-' + p); });
    if (!onHintPage) return;
    if (!hint) {
      var host = document.querySelector('.page-content');
      if (!host) return;
      hint = el('div', 'outbox-hint');
      hint.setAttribute('role', 'status');
      hint.hidden = true;
      var copy = el('span', 'outbox-hint-copy');
      copy.appendChild(el('span', 'outbox-hint-text', ''));
      copy.appendChild(el('strong', 'outbox-hint-warn', WARN_TEXT));
      hint.appendChild(copy);
      var open = el('button', 'outbox-hint-link', 'View Outbox');
      open.type = 'button';
      open.addEventListener('click', function () { openPanel(); });
      hint.appendChild(open);
      host.insertBefore(hint, host.firstChild);
    }
    hint.hidden = c.unsent === 0;
    hint.querySelector('.outbox-hint-text').textContent = unsentText(c);
    hint.querySelector('.outbox-hint-warn').hidden = !c.warn;
    hint.classList.toggle('is-warn', !!c.warn);
  }

  /** "2 queued sales are not counted yet." — sales and other queued actions are named separately. */
  function unsentText(c) {
    var s = c.unsentSales;
    var o = c.unsent - s;
    if (s && !o) return s + ' queued ' + plural(s, 'sale is', 'sales are') + ' not counted yet.';
    if (o && !s) return o + ' queued ' + plural(o, 'action is', 'actions are') + ' not counted yet.';
    return s + ' queued ' + plural(s, 'sale', 'sales') + ' and ' + o + ' queued ' + plural(o, 'action', 'actions') + ' are not counted yet.';
  }

  function renderBanner(c) {
    if (!window.SafeSubmit || typeof window.SafeSubmit.setBannerExtra !== 'function') return;
    var needsText = c.needs > 0 ? c.needs + ' ' + plural(c.needs, 'item needs', 'items need') + ' action' : '';
    var extra = null;
    if (pausedReason === 'auth' && (c.unsent > 0 || c.needs > 0)) {
      extra = { kind: 'paused', text: 'Please log in again to send your queued items.', label: 'Log in', onClick: function () { window.open('login', '_blank', 'noopener'); } };
    } else if (pausedReason === 'other-user' && c.unsent > 0) {
      extra = { kind: 'paused', text: 'Another user is signed in. Queued items stay on this device for their owner.', label: 'Open Outbox', onClick: openPanel };
    } else if (sendingNow || c.sending > 0) {
      var n = c.unsent;
      extra = { kind: 'sending', text: 'Sending ' + n + ' queued ' + plural(n, 'item', 'items') + '...' + (needsText ? ' · ' + needsText : '') };
    } else if (c.needs > 0) {
      extra = { kind: 'needs', text: needsText, label: 'Open Outbox', onClick: openPanel };
    } else if (Date.now() < allSentUntil) {
      extra = { kind: 'sent', text: 'All queued items were sent' };
    }
    window.SafeSubmit.setBannerExtra(extra);
  }

  async function refresh(announceToTabs) {
    if (!(await ready)) { if (resolveLoaded) { resolveLoaded(false); resolveLoaded = null; } return; }
    try { items = await loadAll(); } catch (error) { return; }
    // "Sent" rows stay visible for a few seconds, then disappear (any tab may clean them up; deleting twice is harmless).
    var expired = items.filter(function (i) { return i.status === 'sent' && Date.now() - i.sent_at > sentKeepMs(i); });
    if (expired.length) {
      for (var x = 0; x < expired.length; x++) { try { await updateItem(expired[x].id, function () { return null; }); } catch (error) { /* ignore */ } }
      items = items.filter(function (i) { return expired.indexOf(i) === -1; });
    }
    var c = countBy();
    if (c.unsent === 0 && c.needs === 0) pausedReason = null;
    renderBadge(c);
    renderHint(c);
    renderBanner(c);
    if (panel && !panel.hidden) renderPanel();
    if (announceToTabs) announce();
    // The POS listens to this to mark / unmark "In a queued sale" (only fired when the held items changed).
    var holdsSig = JSON.stringify(holdsMap());
    if (holdsSig !== lastHoldsSig) {
      lastHoldsSig = holdsSig;
      try { window.dispatchEvent(new CustomEvent('outbox:holds')); } catch (error) { /* ignore */ }
    }
    if (resolveLoaded) { resolveLoaded(true); resolveLoaded = null; }
  }

  /* ------------------------------------------------------------------ UI: Outbox panel */
  var panel = null;
  var sheet = null;
  var listNode = null;
  var statusNode = null;
  var warnNode = null;
  var emptyNode = null;
  var sendButton = null;
  var closeButton = null;
  var openerFocus = null;
  var reviewId = null;
  var confirmDiscardId = null;
  var lastSignature = '';

  function buildPanel() {
    if (panel) return;
    panel = el('div', 'outbox-panel');
    panel.hidden = true;
    var backdrop = el('button', 'outbox-backdrop');
    backdrop.type = 'button';
    backdrop.setAttribute('aria-label', 'Close Outbox');
    backdrop.addEventListener('click', closePanel);
    sheet = el('section', 'outbox-sheet');
    sheet.setAttribute('role', 'dialog');
    sheet.setAttribute('aria-modal', 'true');
    sheet.setAttribute('aria-labelledby', 'outboxTitle');

    var head = el('header', 'outbox-head');
    var titleBox = el('div', 'outbox-title-box');
    var title = el('h2', 'outbox-title', 'Outbox');
    title.id = 'outboxTitle';
    titleBox.appendChild(title);
    titleBox.appendChild(el('p', 'outbox-sub', 'Sales and actions saved on this device while your internet was weak or off.'));
    closeButton = el('button', 'outbox-close', 'Close');
    closeButton.type = 'button';
    closeButton.addEventListener('click', closePanel);
    head.appendChild(titleBox);
    head.appendChild(closeButton);

    statusNode = el('p', 'outbox-status');
    statusNode.setAttribute('role', 'status');
    var actions = el('div', 'outbox-actions');
    sendButton = el('button', 'outbox-send', 'Send now');
    sendButton.type = 'button';
    sendButton.addEventListener('click', function () { processCycle(true); });
    actions.appendChild(sendButton);

    listNode = el('ul', 'outbox-list');
    emptyNode = el('p', 'outbox-empty', 'Nothing is waiting. Everything you saved was sent.');
    warnNode = el('p', 'outbox-warn', WARN_TEXT);
    warnNode.setAttribute('role', 'alert');
    warnNode.hidden = true;
    var note = el('p', 'outbox-note', 'Queued sales and actions do not change your stock or sales numbers until they are sent.');

    sheet.appendChild(head);
    sheet.appendChild(statusNode);
    sheet.appendChild(warnNode);
    sheet.appendChild(actions);
    sheet.appendChild(listNode);
    sheet.appendChild(emptyNode);
    sheet.appendChild(note);
    panel.appendChild(backdrop);
    panel.appendChild(sheet);
    document.body.appendChild(panel);

    document.addEventListener('keydown', function (event) {
      if (panel.hidden) return;
      if (event.key === 'Escape') { event.preventDefault(); closePanel(); return; }
      if (event.key === 'Tab') {
        var focusable = Array.prototype.slice.call(sheet.querySelectorAll('button:not([disabled]), a[href]')).filter(function (n) { return n.offsetParent !== null; });
        if (!focusable.length) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    });
    setInterval(tickCountdown, 1000);
  }

  function openPanel() {
    buildPanel();
    if (!panel.hidden) return;
    openerFocus = document.activeElement;
    reviewId = null;
    confirmDiscardId = null;
    lastSignature = '';
    panel.hidden = false;
    document.body.classList.add('modal-open');
    refresh(false).then(function () { renderPanel(); if (closeButton) closeButton.focus(); });
  }

  function closePanel() {
    if (!panel || panel.hidden) return;
    panel.hidden = true;
    document.body.classList.remove('modal-open');
    if (openerFocus && typeof openerFocus.focus === 'function') { try { openerFocus.focus(); } catch (error) { /* ignore */ } }
  }

  function fmtTime(ms) {
    var d = new Date(ms);
    var today = new Date();
    var sameDay = d.toDateString() === today.toDateString();
    return sameDay
      ? d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
      : d.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  }

  function statusLabel(item) {
    if (item.status === 'sending') return 'Sending...';
    if (item.status === 'sent') return 'Sent ✓';
    if (item.status === 'needs_action') return 'Needs action';
    return 'Waiting';
  }

  function tickCountdown() {
    if (!panel || panel.hidden) return;
    var nodes = listNode.querySelectorAll('[data-next-at]');
    for (var i = 0; i < nodes.length; i++) {
      var at = Number(nodes[i].getAttribute('data-next-at'));
      var s = Math.max(0, Math.round((at - Date.now()) / 1000));
      nodes[i].textContent = s > 0 ? 'Next try in ' + s + ' s' : 'Trying again soon';
    }
  }

  function button(label, cls, onClick) {
    var b = el('button', cls, label);
    b.type = 'button';
    b.addEventListener('click', onClick);
    return b;
  }

  function reviewUrl(item) {
    if (!item.review || !REVIEW_PATH[item.review.page]) return '';
    var url = REVIEW_PATH[item.review.page];
    var params = item.review.params || {};
    var pairs = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); });
    return pairs.length ? url + '?' + pairs.join('&') : url;
  }

  function renderPanel() {
    if (!panel || panel.hidden) return;
    var list = visible();
    var c = countBy();
    var signature = JSON.stringify([list.map(function (i) { return [i.id, i.status, i.attempts, i.last_error, i.next_try_at > Date.now(), i.result_no]; }), reviewId, confirmDiscardId, pausedReason, !!c.warn]);
    if (signature === lastSignature) return;
    lastSignature = signature;

    var message = '';
    if (pausedReason === 'auth') message = 'Please log in again to send your queued items.';
    else if (pausedReason === 'other-user') message = 'Another user is signed in. These items stay on this device for their owner.';
    else if (sendingNow || c.sending > 0) message = 'Sending ' + c.unsent + ' queued ' + plural(c.unsent, 'item', 'items') + '...';
    else if (c.unsent > 0) message = c.unsent + ' queued ' + plural(c.unsent, 'item', 'items') + ' will be sent when you are back online.';
    else if (c.needs > 0) message = c.needs + ' ' + plural(c.needs, 'item needs', 'items need') + ' action.';
    statusNode.textContent = message;
    statusNode.hidden = !message;
    warnNode.hidden = !c.warn;
    sendButton.hidden = c.unsent === 0 || pausedReason === 'other-user';
    sendButton.disabled = sendingNow;
    emptyNode.hidden = list.length > 0;

    listNode.textContent = '';
    var review = reviewId ? list.filter(function (i) { return i.id === reviewId; })[0] : null;
    if (reviewId && !review) reviewId = null;
    if (review) { listNode.appendChild(reviewCard(review)); sendButton.hidden = true; return; }

    list.forEach(function (item) { listNode.appendChild(itemCard(item)); });
    tickCountdown();
  }

  function itemCard(item) {
    var li = el('li', 'outbox-item is-' + item.status);
    var top = el('div', 'outbox-item-top');
    top.appendChild(el('strong', 'outbox-item-type', TYPE_LABEL[item.type] || 'Stock action'));
    top.appendChild(el('span', 'outbox-chip is-' + item.status, statusLabel(item)));
    li.appendChild(top);
    li.appendChild(el('p', 'outbox-item-summary', item.summary));
    li.appendChild(el('p', 'outbox-item-time', (item.type === 'sale' ? 'Sale made ' : 'Saved ') + fmtTime(item.created_at)));
    if (item.type === 'sale' && item.status === 'sent' && item.result_no) {
      // The server created the sale: the temporary receipt number is replaced by the real sale number.
      var real = el('p', 'outbox-item-real');
      real.appendChild(el('b', null, 'Sale no. ' + item.result_no));
      if (item.receipt) real.appendChild(document.createTextNode(' (temporary receipt ' + item.receipt + ' replaced)'));
      li.appendChild(real);
    }
    if (item.status === 'queued') {
      if (item.attempts > 0 && item.next_try_at > Date.now()) {
        var next = el('p', 'outbox-item-note', '');
        next.setAttribute('data-next-at', String(item.next_try_at));
        li.appendChild(next);
      } else if (item.last_error) {
        li.appendChild(el('p', 'outbox-item-note', item.last_error));
      }
    }
    if (item.status === 'needs_action') {
      var reason = el('p', 'outbox-item-reason');
      reason.appendChild(el('b', null, 'Why it was not sent: '));
      reason.appendChild(document.createTextNode(item.last_error || 'The server refused this action.'));
      li.appendChild(reason);
      if (confirmDiscardId === item.id) {
        li.appendChild(el('p', 'outbox-item-confirm', item.type === 'sale'
          ? 'Remove this sale from this device? It was not recorded. The owner will still see it in Needs Action.'
          : 'Discard this item? It will not be sent and cannot be brought back.'));
        var row = el('div', 'outbox-item-actions');
        row.appendChild(button('Yes, discard', 'outbox-btn is-danger', function () { discardItem(item.id); }));
        row.appendChild(button('Keep it', 'outbox-btn', function () { confirmDiscardId = null; renderPanel(); }));
        li.appendChild(row);
      } else {
        var actions = el('div', 'outbox-item-actions');
        actions.appendChild(button('Review', 'outbox-btn is-primary', function () { reviewId = item.id; renderPanel(); }));
        actions.appendChild(button('Discard', 'outbox-btn', function () { confirmDiscardId = item.id; renderPanel(); }));
        li.appendChild(actions);
      }
    }
    return li;
  }

  function reviewCard(item) {
    var li = el('li', 'outbox-item is-needs_action is-review');
    var top = el('div', 'outbox-item-top');
    top.appendChild(el('strong', 'outbox-item-type', TYPE_LABEL[item.type] || 'Stock action'));
    top.appendChild(el('span', 'outbox-chip is-needs_action', 'Needs action'));
    li.appendChild(top);
    li.appendChild(el('p', 'outbox-item-summary', item.summary));
    li.appendChild(el('p', 'outbox-item-time', (item.type === 'sale' ? 'Sale made ' : 'Saved ') + fmtTime(item.created_at)));
    var reason = el('p', 'outbox-item-reason');
    reason.appendChild(el('b', null, 'Why it was not sent: '));
    reason.appendChild(document.createTextNode(item.last_error || 'The server refused this action.'));
    li.appendChild(reason);
    if (item.type === 'sale') li.appendChild(el('p', 'outbox-item-time', 'No sale was recorded and no stock changed. The owner has been told and will contact you.'));
    if (item.lines && item.lines.length) {
      li.appendChild(el('p', 'outbox-item-time', 'What you saved:'));
      var ul = el('ul', 'outbox-lines');
      item.lines.forEach(function (line) { ul.appendChild(el('li', null, line)); });
      li.appendChild(ul);
    }
    var actions = el('div', 'outbox-item-actions');
    var url = reviewUrl(item);
    if (url) {
      var open = el('a', 'outbox-btn is-primary', 'Open the form');
      open.href = url;
      actions.appendChild(open);
    }
    actions.appendChild(button('Back', 'outbox-btn', function () { reviewId = null; renderPanel(); }));
    li.appendChild(actions);
    return li;
  }

  async function discardItem(id) {
    confirmDiscardId = null;
    reviewId = null;
    // Kept as "discarded" (hidden) until the owner's list has been told; the sync step then removes it.
    await updateItem(id, function (it) { it.status = 'discarded'; return it; });
    await refresh(true);
    schedule(500);
  }

  /* ------------------------------------------------------------------ sign out guard */
  function askConfirm(title, text, stayLabel, goLabel) {
    return new Promise(function (resolve) {
      var wrap = el('div', 'outbox-panel is-confirm');
      var backdrop = el('button', 'outbox-backdrop');
      backdrop.type = 'button';
      backdrop.setAttribute('aria-label', stayLabel);
      var box = el('section', 'outbox-sheet is-small');
      box.setAttribute('role', 'alertdialog');
      box.setAttribute('aria-modal', 'true');
      box.setAttribute('aria-labelledby', 'outboxConfirmTitle');
      var h = el('h2', 'outbox-title', title);
      h.id = 'outboxConfirmTitle';
      box.appendChild(h);
      box.appendChild(el('p', 'outbox-confirm-text', text));
      var row = el('div', 'outbox-item-actions');
      var stay = button(stayLabel, 'outbox-btn is-primary', function () { finish(false); });
      var go = button(goLabel, 'outbox-btn', function () { finish(true); });
      row.appendChild(stay);
      row.appendChild(go);
      box.appendChild(row);
      wrap.appendChild(backdrop);
      wrap.appendChild(box);
      backdrop.addEventListener('click', function () { finish(false); });
      var onKey = function (event) { if (event.key === 'Escape') finish(false); };
      function finish(answer) {
        document.removeEventListener('keydown', onKey);
        wrap.remove();
        if (!panel || panel.hidden) document.body.classList.remove('modal-open');
        resolve(answer);
      }
      document.addEventListener('keydown', onKey);
      document.body.appendChild(wrap);
      document.body.classList.add('modal-open');
      stay.focus();
    });
  }

  document.addEventListener('click', function (event) {
    var link = event.target.closest ? event.target.closest('a[href]') : null;
    if (!link) return;
    var href = link.getAttribute('href') || '';
    if (!/(^|\/)logout(\.php)?(\?|$)/.test(href)) return;
    var c = countBy();
    if (c.unsent === 0) return;
    event.preventDefault();
    event.stopPropagation();
    askConfirm(
      'Sign out?',
      'You have ' + c.unsent + ' unsent ' + plural(c.unsent, 'item', 'items') + '. Stay signed in until ' + plural(c.unsent, 'it is', 'they are') + ' sent. If you sign out, ' + plural(c.unsent, 'it stays', 'they stay') + ' on this device and will be sent the next time you sign in.',
      'Stay signed in',
      'Sign out anyway'
    ).then(function (leave) { if (leave) window.location.href = link.href; });
  }, true);

  /* ------------------------------------------------------------------ triggers */
  window.addEventListener('online', function () { failStreak = 0; processCycle(true); });
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { refresh(false); processCycle(false); } });

  function start() {
    ready.then(function (ok) {
      if (!ok) { window.Outbox.enabled = false; return; }
      window.Outbox.enabled = true;
      return refresh(false).then(function () { return processCycle(false); });
    });
  }

  window.Outbox = {
    enabled: false,
    ready: ready,
    enqueueFrom: enqueueFrom,
    openPanel: openPanel,
    sendNow: function () { return processCycle(true); },
    unsentCount: function () { return countBy().unsent; },
    loaded: loaded,
    holds: holdsMap
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
