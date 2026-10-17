/* Safe, friendly saving for every state-changing action (Patch 6a).
 *
 *  SafeSubmit.run({...})  — one shared helper used by POS checkout, Receive Stock, Branch Transfer create,
 *                           Transfer receive and stock adjustments.
 *  Connection banner       — slim bar: "Weak connection" / "No internet. Your data is safe." / brief "Back online" (Patch 6b).
 *
 * What it guarantees:
 *   - the button is disabled and shows "Saving..." with a spinner; ~20 s request timeout
 *   - the form / cart is never touched here — callers clear it only after success is confirmed
 *   - one client-generated UUID per intention; Retry resends the SAME key, so the server returns the
 *     original result instead of saving twice (see core/Idempotency.php)
 *   - success card (green) stays until dismissed; failure card (red) has a big Retry button
 *   - plain English messages, no technical words; status text is announced with aria-live
 * Offline outbox (Patch 6c-1): a call may pass `outbox:{type,summary,...}`. When the request cannot be completed because of the
 * network (or an expired login), the intention is saved on the device by assets/js/outbox.js under the SAME key and sent later.
 * Without IndexedDB (or without `outbox`) everything below behaves exactly as in Patch 6a.
 * The service worker (sw.js, Patch 6b) caches static files only and never touches these requests.
 */
(function () {
  'use strict';
  if (window.SafeSubmit) return;

  var TIMEOUT_MS = 20000;
  var PING_URL = 'actions/ping.php';

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }

  function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    var bytes = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    var h = [];
    for (var i = 0; i < 16; i++) h.push((bytes[i] + 256).toString(16).slice(1));
    return h.slice(0, 4).join('') + '-' + h.slice(4, 6).join('') + '-' + h.slice(6, 8).join('') + '-' + h.slice(8, 10).join('') + '-' + h.slice(10).join('');
  }

  /* ------------------------------------------------------------------ status card */
  function statusCard(opts) {
    var host = opts.host || (opts.button && opts.button.parentElement) || document.body;
    var card = host.querySelector(':scope > .save-status');
    if (!card) {
      card = el('div', 'save-status');
      card.setAttribute('role', 'status');
      card.setAttribute('aria-live', 'polite');
      card.setAttribute('aria-atomic', 'true');
      card.tabIndex = -1;
      if (opts.before && opts.before.parentNode === host) host.insertBefore(card, opts.before);
      else host.appendChild(card);
    }
    return card;
  }

  function paint(card, state, title, detail, buttons) {
    card.className = 'save-status is-' + state;
    card.hidden = false;
    card.setAttribute('role', state === 'error' ? 'alert' : 'status');
    card.textContent = '';
    var icon = el('span', 'save-status-icon');
    icon.setAttribute('aria-hidden', 'true');
    if (state === 'saving') icon.appendChild(el('span', 'save-spinner'));
    else icon.textContent = state === 'success' || state === 'queued' ? '✓' : '!';
    var body = el('div', 'save-status-body');
    body.appendChild(el('strong', 'save-status-title', title));
    if (detail) body.appendChild(el('p', 'save-status-detail', detail));
    card.appendChild(icon);
    card.appendChild(body);
    if (buttons && buttons.length) {
      var row = el('div', 'save-status-actions');
      buttons.forEach(function (b) { row.appendChild(b); });
      card.appendChild(row);
    }
  }

  function actionButton(label, cls, onClick) {
    var b = el('button', 'save-status-btn ' + cls, label);
    b.type = 'button';
    b.addEventListener('click', onClick);
    return b;
  }

  /* ------------------------------------------------------------------ one request */
  function buildInit(body, key, fromOutbox) {
    var init = {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Idempotency-Key': key }
    };
    if (fromOutbox) init.headers['X-Outbox-Send'] = '1';
    if (typeof FormData !== 'undefined' && body instanceof FormData) {
      body.set('client_key', key);
      init.body = body;
    } else if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
      body.set('client_key', key);
      init.body = body;
    } else {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(Object.assign({}, body, { client_key: key }));
    }
    return init;
  }

  /** @returns {Promise<{kind:'success'|'business'|'csrf'|'auth'|'network', data?:object, message?:string, token?:string, status?:number, code?:string}>} */
  async function attempt(opts, key) {
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var timer = null;
    var init = buildInit(opts.getBody(), key, !!opts.fromOutbox);
    if (controller) {
      init.signal = controller.signal;
      timer = setTimeout(function () { controller.abort(); }, opts.timeout || TIMEOUT_MS);
    }
    var response, text;
    try {
      response = await fetch(opts.url, init);
      text = await response.text();
    } catch (error) {
      return { kind: 'network' };
    } finally {
      if (timer) clearTimeout(timer);
    }

    var data = null;
    try { data = JSON.parse(text); } catch (error) { data = null; }
    // Server trouble, a captive-portal/offline page or an unreadable answer: nothing was confirmed → safe to retry.
    if (response.status >= 500 || !data || typeof data !== 'object') return { kind: 'network' };
    if (response.ok && (data.ok === true || data.success === true)) return { kind: 'success', data: data, status: response.status };
    if (response.status === 419 || data.code === 'csrf') return { kind: 'csrf', token: data.csrf || null, status: response.status };
    if (response.status === 401 || data.code === 'auth') return { kind: 'auth', status: response.status };
    // Same key still being saved by another request: nothing is decided yet → treat like a network problem (retry later).
    if (data.code === 'in_progress') return { kind: 'network', status: response.status, code: 'in_progress' };
    return { kind: 'business', message: String(data.error || data.message || 'This request could not be saved.'), status: response.status, code: data.code || '' };
  }

  /* ------------------------------------------------------------------ public run() */
  async function run(opts) {
    var holder = opts.keyHolder || opts.form;
    if (!holder || typeof opts.getBody !== 'function' || !opts.url) throw new Error('SafeSubmit: url, getBody and a form/keyHolder are required.');
    if (holder.dataset.ssBusy === '1') return { ok: false, busy: true };
    holder.dataset.ssBusy = '1';

    var key = holder.dataset.clientKey || (holder.dataset.clientKey = uuid());
    var card = statusCard(opts);
    var button = opts.button || null;
    var originalHtml = button ? button.innerHTML : '';
    var originalDisabled = button ? button.disabled : false;
    var succeeded = false;
    var csrfRetried = false;

    function lockButton() {
      if (!button) return;
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      button.textContent = '';
      button.appendChild(el('span', 'save-spinner is-inline'));
      button.appendChild(document.createTextNode(' ' + (opts.pendingText || 'Saving...')));
    }
    function unlockButton() {
      if (!button) return;
      button.innerHTML = originalHtml;
      button.disabled = originalDisabled;
      button.removeAttribute('aria-busy');
    }

    try {
      for (;;) {
        lockButton();
        paint(card, 'saving', opts.pendingText || 'Saving...', 'Please do not refresh or close this page.');
        var result = await attempt(opts, key);

        if (result.kind === 'success') {
          succeeded = true;
          delete holder.dataset.clientKey;
          var detail = typeof opts.successDetail === 'function' ? opts.successDetail(result.data) : (opts.successDetail || '');
          if (opts.quietSuccess) {
            // Caller draws its own success state (POS "Sale saved"): no green card, no OK button.
            card.hidden = true;
            card.textContent = '';
            if (button) button.removeAttribute('aria-busy');
            if (typeof opts.onSuccess === 'function') opts.onSuccess(result.data);
            return { ok: true, data: result.data };
          }
          var okBtn = actionButton(opts.dismissLabel || 'OK', 'is-primary', function () {
            card.hidden = true;
            card.textContent = '';
            if (typeof opts.onDismiss === 'function') opts.onDismiss(result.data);
          });
          if (button) { button.textContent = 'Saved ✓'; button.removeAttribute('aria-busy'); }
          paint(card, 'success', opts.successTitle || 'Saved ✓', detail, [okBtn]);
          okBtn.focus();
          if (typeof opts.onSuccess === 'function') opts.onSuccess(result.data);
          return { ok: true, data: result.data };
        }

        if (result.kind === 'csrf' && result.token && !csrfRetried) {
          // Our token went stale (e.g. re-login in another tab): take the fresh one and resend the same key once.
          csrfRetried = true;
          if (typeof opts.onCsrf === 'function') opts.onCsrf(result.token);
          continue;
        }

        if (result.kind === 'business') {
          unlockButton();
          var closeBtn = actionButton('OK', 'is-primary', function () { card.hidden = true; card.textContent = ''; });
          paint(card, 'error', 'Not saved', result.message, [closeBtn]);
          closeBtn.focus();
          return { ok: false, business: true, message: result.message };
        }

        var needsLogin = result.kind === 'auth' || result.kind === 'csrf';

        // Patch 6c-1: keep the intention on this device and send it later (same key → the server can never double-count it).
        if (opts.outbox && window.Outbox) {
          var queued = null;
          var allowQueue = true;
          // Patch 6c-2: the caller may ask something first (POS: "Payment received" for non-cash sales). false = do not queue → Retry card.
          if (typeof opts.outbox.beforeQueue === 'function') {
            var usable = false;
            try { usable = !!(await window.Outbox.ready); } catch (error) { usable = false; }
            if (usable) {
              unlockButton();
              try { allowQueue = (await opts.outbox.beforeQueue({ needsLogin: needsLogin })) !== false; } catch (error) { allowQueue = false; }
              lockButton();
            }
          }
          if (allowQueue) {
            try { queued = await window.Outbox.enqueueFrom(opts, key, needsLogin ? 'auth' : 'network'); } catch (error) { queued = null; }
          }
          if (queued && queued.ok && typeof opts.onQueued === 'function') {
            // The caller draws its own "saved on this device" state (POS sale): no card here.
            succeeded = true;
            delete holder.dataset.clientKey;
            card.hidden = true;
            card.textContent = '';
            if (button) button.removeAttribute('aria-busy');
            opts.onQueued(queued, { needsLogin: needsLogin });
            return { ok: false, queued: true, duplicate: !!queued.duplicate, item: queued.item };
          }
          if (queued && queued.ok) {
            succeeded = true; // keep the form locked: this intention now lives in the outbox
            delete holder.dataset.clientKey;
            if (button) { button.textContent = 'Saved on this device'; button.removeAttribute('aria-busy'); }
            var queuedOk = actionButton('OK', 'is-primary', function () {
              card.hidden = true;
              card.textContent = '';
              if (typeof opts.onDismiss === 'function') opts.onDismiss(null);
            });
            var viewBtn = actionButton('View Outbox', 'is-secondary', function () { window.Outbox.openPanel(); });
            paint(
              card,
              'queued',
              queued.duplicate ? 'Already saved on this device.' : 'Saved on this device.',
              needsLogin ? 'Log in again and it will be sent. Nothing was lost.' : 'It will be sent when you are back online.',
              [queuedOk, viewBtn]
            );
            queuedOk.focus();
            return { ok: false, queued: true, duplicate: !!queued.duplicate };
          }
        }

        // network trouble, timeout, or expired login → offer Retry with the SAME key
        unlockButton();
        Banner.check();
        var choice = await new Promise(function (resolve) {
          var retry = actionButton('Retry', 'is-primary is-big', function () { resolve('retry'); });
          var cancel = actionButton('Go back and edit', 'is-secondary', function () { card.hidden = true; card.textContent = ''; resolve('cancel'); });
          var buttons = [retry, cancel];
          var detailText = needsLogin
            ? 'Your login expired. Open the login page in a new tab, sign in, then come back here and tap Retry. Nothing was lost.'
            : 'Your internet is weak or offline. Nothing was lost.';
          paint(card, 'error', 'Not saved yet.', detailText, buttons);
          if (needsLogin) {
            var login = el('a', 'save-status-btn is-secondary', 'Log in (new tab)');
            login.href = 'login';
            login.target = '_blank';
            login.rel = 'noopener';
            card.querySelector('.save-status-actions').appendChild(login);
          }
          retry.focus();
        });
        if (choice === 'cancel') return { ok: false, cancelled: true };
        csrfRetried = false;
      }
    } finally {
      holder.dataset.ssBusy = '';
      if (!succeeded) unlockButton();
    }
  }

  /* ------------------------------------------------------------------ connection banner */
  // States: ok (hidden) · weak ("Weak connection") · offline ("No internet. Your data is safe.") · back ("Back online", ~3 s).
  var Banner = (function () {
    var TEXT = {
      weak: 'Weak connection',
      offline: 'No internet. Your data is safe.',
      back: 'Back online'
    };
    var node = null;
    var textNode = null;
    var timer = null;
    var backTimer = null;
    var state = 'ok';
    var checking = false;
    // Patch 6c-1: the offline outbox adds a second message to the same bar ("Sending 2 queued items...", "N items need action"…).
    var extra = null; // { text, kind: 'sending'|'sent'|'needs'|'paused', label?, onClick? }
    var actionNode = null;

    function build() {
      if (node) return;
      node = el('div', 'net-banner');
      node.setAttribute('role', 'status');
      node.setAttribute('aria-live', 'polite');
      node.hidden = true;
      node.appendChild(el('span', 'net-banner-dot'));
      textNode = el('span', 'net-banner-text', '');
      node.appendChild(textNode);
      actionNode = el('button', 'net-banner-action', '');
      actionNode.type = 'button';
      actionNode.hidden = true;
      actionNode.addEventListener('click', function () { if (extra && typeof extra.onClick === 'function') extra.onClick(); });
      node.appendChild(actionNode);
      var main = document.querySelector('.app-main');
      if (main) main.insertBefore(node, main.firstChild);
      else document.body.insertBefore(node, document.body.firstChild);
    }

    function setExtra(next) {
      build();
      extra = next || null;
      render();
    }

    function render() {
      var parts = [];
      if (state !== 'ok') parts.push(TEXT[state]);
      if (extra && extra.text) parts.push(extra.text);
      var visible = parts.length > 0;
      node.hidden = !visible;
      if (visible) {
        node.className = 'net-banner is-' + (state !== 'ok' ? state : extra.kind);
        textNode.textContent = parts.join(' · ');
        actionNode.hidden = !(extra && extra.label);
        actionNode.textContent = extra && extra.label ? extra.label : '';
      }
      document.body.classList.toggle('net-weak', visible);
      document.documentElement.style.setProperty('--net-banner-h', visible ? node.offsetHeight + 'px' : '0px');
    }

    function set(next) {
      build();
      if (state === next) return;
      var previous = state;
      if (next === 'ok' && previous === 'back') return; // let "Back online" finish its few seconds
      clearTimeout(backTimer);
      if (next === 'ok' && previous !== 'ok' && previous !== 'back') {
        state = 'back';
        render();
        backTimer = setTimeout(function () { state = 'ok'; render(); schedule(); }, 3000);
      } else {
        state = next;
        render();
      }
      schedule();
    }

    async function check() {
      if (checking) return;
      checking = true;
      if (navigator.onLine === false) {
        set('offline');
        checking = false;
        return;
      }
      var controller = typeof AbortController === 'function' ? new AbortController() : null;
      var timedOut = false;
      var timeout = setTimeout(function () { timedOut = true; if (controller) controller.abort(); }, 6000);
      var started = Date.now();
      try {
        var response = await fetch(PING_URL + '?t=' + started, { cache: 'no-store', credentials: 'omit', signal: controller ? controller.signal : undefined });
        var elapsed = Date.now() - started;
        set(!response.ok || elapsed > 3000 ? 'weak' : 'ok');
      } catch (error) {
        // No answer within 6 s = weak. An immediate failure = nothing is reachable = no internet.
        set(timedOut ? 'weak' : 'offline');
      } finally {
        clearTimeout(timeout);
        checking = false;
      }
    }

    // Look more often while the connection is bad so "Back online" shows soon after it returns.
    function schedule() {
      clearInterval(timer);
      timer = setInterval(function () { if (document.visibilityState !== 'hidden') check(); }, state === 'weak' || state === 'offline' ? 5000 : 15000);
    }

    function start() {
      build();
      window.addEventListener('online', check);
      window.addEventListener('offline', function () { set('offline'); });
      document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') check(); });
      schedule();
      check();
    }

    return { start: start, check: check, setExtra: setExtra };
  })();

  // attempt = one request with the shared answer sorting; used by the offline outbox (assets/js/outbox.js) to send queued items.
  window.SafeSubmit = { run: run, uuid: uuid, checkConnection: Banner.check, attempt: attempt, setBannerExtra: Banner.setExtra };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', Banner.start);
  else Banner.start();
})();
