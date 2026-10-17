/* Shared right-side drawer for owner KPI cards (Stock Monitoring + Dashboard).
 * Cards opt in with data-kpi-drawer="overall|stock_in|transfer|sold" plus data-date / data-branch /
 * data-brand / data-q / data-basis. Data comes from actions/stock_monitoring_drawer.php (read-only). */
(function () {
  'use strict';

  var ENDPOINT = 'actions/stock_monitoring_drawer.php';
  var cards = document.querySelectorAll('[data-kpi-drawer]');
  if (!cards.length) return;

  var peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
  var num = new Intl.NumberFormat('en-PH');
  var els = null;
  var state = null;
  var trigger = null;
  var request = null;
  var lastData = null;

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }

  function build() {
    if (els) return;
    var backdrop = el('div', 'kpi-drawer-backdrop');
    backdrop.hidden = true;

    var panel = el('aside', 'kpi-drawer');
    panel.hidden = true;
    panel.tabIndex = -1;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-labelledby', 'kpiDrawerTitle');

    var head = el('header', 'kpi-drawer-head');
    var titles = el('div');
    var eyebrow = el('span', 'eyebrow');
    var title = el('h2', '', '');
    title.id = 'kpiDrawerTitle';
    titles.appendChild(eyebrow);
    titles.appendChild(title);
    var close = el('button', 'kpi-drawer-close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', 'Close details');
    head.appendChild(titles);
    head.appendChild(close);

    var totalBox = el('div', 'kpi-drawer-total');
    var totalValue = el('strong', '', '0');
    var totalLabel = el('span', '', 'units');
    totalBox.appendChild(totalValue);
    totalBox.appendChild(totalLabel);
    var viewLink = el('a', 'kpi-drawer-viewlink', 'View in Sales Records');
    viewLink.hidden = true;

    var tabs = el('div', 'kpi-drawer-tabs');
    tabs.setAttribute('role', 'tablist');
    tabs.hidden = true;

    var chips = el('div', 'kpi-drawer-chips');
    chips.setAttribute('role', 'group');
    chips.setAttribute('aria-label', 'Branch');

    var searchWrap = el('label', 'kpi-drawer-search');
    var searchInput = el('input');
    searchInput.type = 'search';
    searchInput.placeholder = 'Search model, IMEI, serial, reference…';
    searchInput.setAttribute('aria-label', 'Search in this list');
    searchInput.autocomplete = 'off';
    searchWrap.appendChild(searchInput);

    var meta = el('p', 'kpi-drawer-meta');
    meta.setAttribute('aria-live', 'polite');
    var body = el('div', 'kpi-drawer-body');

    [head, totalBox, viewLink, tabs, chips, searchWrap, meta, body].forEach(function (n) { panel.appendChild(n); });
    document.body.appendChild(backdrop);
    document.body.appendChild(panel);

    els = { backdrop: backdrop, panel: panel, eyebrow: eyebrow, title: title, close: close, totalValue: totalValue,
            totalLabel: totalLabel, viewLink: viewLink, tabs: tabs, chips: chips, search: searchInput, meta: meta, body: body };

    close.addEventListener('click', closeDrawer);
    backdrop.addEventListener('click', closeDrawer);
    searchInput.addEventListener('input', function () { state.q = searchInput.value; renderList(); });
    panel.addEventListener('keydown', onPanelKey);
  }

  function onPanelKey(event) {
    if (event.key === 'Escape') { event.stopPropagation(); closeDrawer(); return; }
    if (event.key !== 'Tab') return;
    var focusable = els.panel.querySelectorAll('button:not([disabled]), a[href], input:not([disabled])');
    var visible = [];
    for (var i = 0; i < focusable.length; i++) if (focusable[i].offsetParent !== null) visible.push(focusable[i]);
    if (!visible.length) return;
    var first = visible[0], last = visible[visible.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  }

  function openFor(card) {
    build();
    trigger = card;
    state = {
      kind: card.getAttribute('data-kpi-drawer'),
      date: card.getAttribute('data-date') || '',
      branch: card.getAttribute('data-branch') || '',
      brand: card.getAttribute('data-brand') || '',
      q: '',
      pageQ: card.getAttribute('data-q') || '',
      basis: card.getAttribute('data-basis') || '',
      type: card.getAttribute('data-type') || '',
      tab: 'out'
    };
    els.search.value = '';
    els.backdrop.hidden = false;
    els.panel.hidden = false;
    document.body.classList.add('kpi-drawer-open');
    // Next frame so the slide-in transition runs.
    requestAnimationFrame(function () {
      els.backdrop.classList.add('is-open');
      els.panel.classList.add('is-open');
    });
    els.close.focus();
    card.setAttribute('aria-expanded', 'true');
    load();
  }

  function closeDrawer() {
    if (!els || els.panel.hidden) return;
    if (request) { request.abort(); request = null; }
    els.backdrop.classList.remove('is-open');
    els.panel.classList.remove('is-open');
    els.backdrop.hidden = true;
    els.panel.hidden = true;
    document.body.classList.remove('kpi-drawer-open');
    if (trigger) {
      trigger.setAttribute('aria-expanded', 'false');
      trigger.focus();
    }
  }

  function setBusy(message) {
    els.body.textContent = '';
    els.body.appendChild(el('p', 'kpi-drawer-empty', message));
  }

  function load() {
    if (request) request.abort();
    request = typeof AbortController === 'function' ? new AbortController() : null;
    var params = new URLSearchParams();
    params.set('kind', state.kind);
    params.set('date', state.date);
    if (state.branch) params.set('branch', state.branch);
    if (state.brand) params.set('brand', state.brand);
    var pageQ = state.pageQ;
    if (pageQ) params.set('q', pageQ);
    if (state.basis) params.set('basis', state.basis);
    if (state.type) params.set('type', state.type);
    if (state.kind === 'transfer') params.set('tab', state.tab);

    els.panel.setAttribute('aria-busy', 'true');
    setBusy('Loading…');
    var thisRequest = request;
    fetch(ENDPOINT + '?' + params.toString(), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      signal: request ? request.signal : undefined
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (json) {
        if (!response.ok) throw new Error(json.error || 'The details could not be loaded.');
        return json;
      });
    }).then(function (data) {
      if (thisRequest !== request) return;
      lastData = data;
      render(data);
    }).catch(function (error) {
      if (error && error.name === 'AbortError') return;
      setBusy(error && error.message ? error.message : 'The details could not be loaded.');
    }).then(function () {
      els.panel.removeAttribute('aria-busy');
    });
  }

  function render(data) {
    var titles = { overall: 'Overall Units', stock_in: 'Stock In', transfer: 'Transfer', sold: 'Sold' };
    els.eyebrow.textContent = data.basis === 'live' ? 'Available now' : data.date_label;
    els.title.textContent = data.title || titles[data.kind] || 'Details';
    els.totalValue.textContent = data.money ? peso.format(data.total || 0) : num.format(data.total || 0);
    els.totalLabel.textContent = data.total_label ||
      (data.kind === 'sold' ? 'units sold' : (data.kind === 'overall' ? (data.basis === 'live' ? 'units available' : 'units in stock') : 'units'));
    if (data.view_url) {
      els.viewLink.href = data.view_url;
      els.viewLink.hidden = false;
    } else {
      els.viewLink.hidden = true;
      els.viewLink.removeAttribute('href');
    }

    // Tabs (Transfer: In / Out)
    els.tabs.textContent = '';
    els.tabs.hidden = data.kind !== 'transfer';
    if (data.kind === 'transfer') {
      [['out', 'Out'], ['in', 'In']].forEach(function (t) {
        var b = el('button', 'kpi-drawer-tab' + (state.tab === t[0] ? ' is-active' : ''),
          t[1] + ' (' + num.format((data.tab_totals || {})[t[0]] || 0) + ')');
        b.type = 'button';
        b.setAttribute('role', 'tab');
        b.setAttribute('aria-selected', state.tab === t[0] ? 'true' : 'false');
        b.addEventListener('click', function () {
          if (state.tab === t[0]) return;
          state.tab = t[0];
          load();
        });
        els.tabs.appendChild(b);
      });
    }

    // Branch chips
    els.chips.textContent = '';
    var selected = data.branch_id ? String(data.branch_id) : '';
    var options = [{ id: '', name: 'All' }].concat((data.branches || []).map(function (b) { return { id: String(b.id), name: b.name }; }));
    options.forEach(function (o) {
      var chip = el('button', 'kpi-drawer-chip' + (o.id === selected ? ' is-active' : ''), o.name);
      chip.type = 'button';
      chip.setAttribute('aria-pressed', o.id === selected ? 'true' : 'false');
      chip.addEventListener('click', function () {
        if (state.branch === o.id) return;
        state.branch = o.id;
        load();
      });
      els.chips.appendChild(chip);
    });

    renderList();
  }

  function entryText(kind, entry) {
    return [entry.branch, entry.ref, entry.note, entry.actor, entry.from, entry.to, entry.status, entry.cashier, entry.sale_no, entry.payment]
      .concat(entry.units || []).join(' ').toLowerCase();
  }

  function renderList() {
    var data = lastData;
    if (!data) return;
    var needle = (state.q || '').trim().toLowerCase();
    var groups = (data.groups || []).filter(function (g) {
      if (!needle) return true;
      if ((g.label + ' ' + g.brand).toLowerCase().indexOf(needle) !== -1) return true;
      return g.entries.some(function (e) { return entryText(data.kind, e).indexOf(needle) !== -1; });
    });

    els.body.textContent = '';
    var noun = data.kind === 'sales_month' ? 'day(s)' : (data.kind === 'sales_today' ? 'transaction(s)' : 'model(s)');
    els.meta.textContent = (data.truncated ? 'Showing the latest records only. ' : '') +
      (needle ? groups.length + ' of ' + (data.groups || []).length + ' ' + noun + ' match.' : (data.groups || []).length + ' ' + noun + '.');

    if (!groups.length) {
      els.body.appendChild(el('p', 'kpi-drawer-empty', needle ? 'No matches for your search.' : 'No records for this date and branch.'));
      return;
    }

    var list = el('ul', 'kpi-drawer-list');
    groups.forEach(function (g, index) {
      var li = el('li', 'kpi-drawer-item');
      var detailId = 'kpiDrawerDetail' + index;
      var row = el('button', 'kpi-drawer-row');
      row.type = 'button';
      row.setAttribute('aria-expanded', needle ? 'true' : 'false');
      row.setAttribute('aria-controls', detailId);
      var name = el('span', 'kpi-drawer-name');
      name.appendChild(el('strong', '', g.label));
      name.appendChild(el('small', '', g.brand));
      row.appendChild(name);
      row.appendChild(el('span', 'kpi-drawer-qty', g.value !== undefined ? peso.format(g.value) : num.format(g.qty)));
      row.appendChild(el('span', 'kpi-drawer-chevron', '›'));

      var detail = el('div', 'kpi-drawer-detail');
      detail.id = detailId;
      detail.hidden = !needle;
      g.entries.forEach(function (e) { detail.appendChild(entryNode(data.kind, e)); });

      row.addEventListener('click', function () {
        var open = row.getAttribute('aria-expanded') === 'true';
        row.setAttribute('aria-expanded', open ? 'false' : 'true');
        detail.hidden = open;
      });
      li.appendChild(row);
      li.appendChild(detail);
      list.appendChild(li);
    });
    els.body.appendChild(list);
  }

  function entryNode(kind, e) {
    var box = el('div', 'kpi-drawer-entry');
    var top = el('div', 'kpi-drawer-entry-top');
    var lines = [];

    if (kind === 'overall') {
      top.appendChild(el('strong', '', e.branch));
      top.appendChild(el('span', 'kpi-drawer-entry-qty', num.format(e.qty) + ' unit(s)'));
    } else if (kind === 'transfer') {
      top.appendChild(el('strong', '', (e.from || '?') + ' → ' + (e.to || '?')));
      var badge = el('span', 'kpi-drawer-badge is-' + (e.status || 'pending'), e.status === 'received' ? 'Received' : (e.status === 'cancelled' ? 'Cancelled' : 'Pending'));
      top.appendChild(badge);
      lines.push(num.format(e.qty) + ' unit(s) · ' + e.time);
      if (e.ref) lines.push('Ref ' + e.ref);
    } else if (kind === 'stock_in') {
      top.appendChild(el('strong', '', e.branch));
      top.appendChild(el('span', 'kpi-drawer-entry-qty', '+' + num.format(e.qty)));
      lines.push(e.time + (e.actor ? ' · Received by ' + e.actor : ''));
      if (e.ref) lines.push('Ref ' + e.ref);
      if (e.note) lines.push(e.note);
    } else if (kind === 'sales_today' || kind === 'sales_month') {
      top.appendChild(el('strong', '', e.sale_no || e.branch));
      top.appendChild(el('span', 'kpi-drawer-entry-qty', peso.format(e.amount || 0)));
      lines.push(e.branch + ' · ' + e.payment + ' · ' + e.cashier);
      lines.push(e.time);
    } else if (kind === 'sold') {
      top.appendChild(el('strong', '', e.branch));
      top.appendChild(el('span', 'kpi-drawer-entry-qty', peso.format(e.amount || 0)));
      lines.push(num.format(e.qty) + ' unit(s) · ' + e.time + (e.cashier ? ' · ' + e.cashier : ''));
    }
    box.appendChild(top);
    lines.forEach(function (text) { box.appendChild(el('p', 'kpi-drawer-line', text)); });

    if ((kind === 'sold' || kind === 'sales_today' || kind === 'sales_month') && e.sale_no) {
      var p = el('p', 'kpi-drawer-line');
      p.appendChild(document.createTextNode('Receipt '));
      if (e.sale_url) {
        var a = el('a', 'kpi-drawer-link', e.sale_no);
        a.href = e.sale_url;
        p.appendChild(a);
      } else {
        p.appendChild(document.createTextNode(e.sale_no));
      }
      box.appendChild(p);
    }

    if (e.units && e.units.length) {
      var ul = el('ul', 'kpi-drawer-units');
      e.units.forEach(function (u) { ul.appendChild(el('li', 'mono', u)); });
      box.appendChild(ul);
    }
    if (e.missing > 0) {
      box.appendChild(el('p', 'kpi-drawer-line is-muted', e.missing + ' unit(s) without a recorded IMEI/serial for this record.'));
    }
    return box;
  }

  for (var i = 0; i < cards.length; i++) {
    (function (card) {
      card.setAttribute('role', 'button');
      card.setAttribute('tabindex', '0');
      card.setAttribute('aria-haspopup', 'dialog');
      card.setAttribute('aria-expanded', 'false');
      card.classList.add('is-clickable');
      card.addEventListener('click', function () { openFor(card); });
      card.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
          event.preventDefault();
          openFor(card);
        }
      });
    })(cards[i]);
  }

  // Esc closes even when focus has not moved into the panel yet.
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && els && !els.panel.hidden) closeDrawer();
  });
})();
