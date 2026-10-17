(() => {
  const root = document.querySelector('[data-pos-root]');
  if (!root) return;

  const branchId = root.dataset.branchId || '';
  const branchCode = (root.dataset.branchCode || 'BR').replace(/[^A-Za-z0-9]/g, '') || 'BR';
  const searchUrl = root.dataset.searchUrl || 'actions/pos_search.php';
  const storageKey = root.dataset.cartStorage || `malbcoff_pos_cart_${branchId}`;
  const searchInput = root.querySelector('[data-pos-search]');
  const resultsBox = root.querySelector('[data-pos-results]');
  const searchStatus = root.querySelector('[data-pos-search-status]');
  const cartLines = root.querySelector('[data-cart-lines]');
  const cartEmpty = root.querySelector('[data-cart-empty]');
  const cartCount = root.querySelector('[data-cart-count]');
  const clearCartButton = root.querySelector('[data-clear-cart]');
  const cartJson = root.querySelector('[data-cart-json]');
  const subtotalEl = root.querySelector('[data-subtotal]');
  const totalEl = root.querySelector('[data-total]');
  const completeTotal = root.querySelector('[data-complete-total]');
  const completeButton = root.querySelector('[data-complete-sale]');
  const checkoutForm = root.querySelector('[data-pos-checkout]');
  const cashField = root.querySelector('[data-cash-field]');
  const cashInput = root.querySelector('[data-cash-received]');
  const changeBox = root.querySelector('[data-change-box]');
  const changeEl = root.querySelector('[data-change]');
  const referenceField = root.querySelector('[data-reference-field]');
  const confirmModal = document.getElementById('posConfirmModal');
  const confirmItems = confirmModal?.querySelector('[data-confirm-items]');
  const confirmPayment = confirmModal?.querySelector('[data-confirm-payment]');
  const confirmTotal = confirmModal?.querySelector('[data-confirm-total]');
  const confirmSubmit = confirmModal?.querySelector('[data-pos-confirm-submit]');
  // Patch 8: Set Prices sheet
  const lastPricesUrl = root.dataset.lastPricesUrl || 'actions/pos_last_prices.php';
  const sheet = document.getElementById('posPriceSheet');
  const sheetRows = sheet?.querySelector('[data-price-rows]');
  const sheetGrand = sheet?.querySelector('[data-price-grand]');
  const sheetConfirm = sheet?.querySelector('[data-price-confirm]');
  const sameBox = sheet?.querySelector('[data-price-same]');
  const sameToggle = sheet?.querySelector('[data-price-same-toggle]');
  const samePanel = sheet?.querySelector('[data-price-same-panel]');
  const sameInput = sheet?.querySelector('[data-price-same-input]');
  const sameApply = sheet?.querySelector('[data-price-same-apply]');

  let cart = [];
  let lastPrices = {};            // product_id -> cents, THIS branch only (from the server)
  const lastPricesAsked = new Set();
  let skipSheetSig = null;        // set when cash was too low after the prices were confirmed: next Checkout goes straight on
  let searchTimer = null;
  let latestSearch = 0;
  let finalSubmitApproved = false;
  let saleSaving = false; // true from the confirm tap until the cashier dismisses the green "Saved" card

  if (document.querySelector('[data-sale-complete="1"]')) sessionStorage.removeItem(storageKey);
  try {
    const saved = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
    if (Array.isArray(saved)) cart = saved;
  } catch (_) {}

  const money = value => `₱${Number(value || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const selectedPayment = () => root.querySelector('input[name="payment_method"]:checked')?.value || 'cash';
  const paymentLabel = value => ({cash:'Cash',gcash:'GCash',maya:'Maya',card:'Card',bank_transfer:'Bank Transfer'}[value] || 'Cash');
  const persist = () => sessionStorage.setItem(storageKey, JSON.stringify(cart));

  // ---- Queued sales on this device (Patch 6c-2). Items already in an unsent sale cannot be added again here.
  // This is only a safety hint: the server stays the source of truth and re-checks every item when the sale is sent.
  const heldMap = () => (window.Outbox && typeof window.Outbox.holds === 'function') ? window.Outbox.holds() : {};
  const heldQty = item => Number(heldMap()[item.key] || 0);
  const availQty = item => Math.max(0, Number(item.available_qty || 0) - heldQty(item));
  const isHeld = item => item.kind === 'device' ? heldQty(item) > 0 : availQty(item) <= 0;
  let resultItems = new Map();

  // ---- Items this device saw in earlier searches, used only when the internet is down (branch staff with an outbox).
  let outboxScope = null;
  try { outboxScope = JSON.parse(document.querySelector('meta[name="outbox-scope"]')?.content || 'null'); } catch (_) { outboxScope = null; }
  const seenKey = outboxScope && outboxScope.user ? `malbcoff_pos_seen_u${outboxScope.user}b${outboxScope.branch}` : null;
  const loadSeen = () => { try { const list = JSON.parse(localStorage.getItem(seenKey) || '[]'); return Array.isArray(list) ? list : []; } catch (_) { return []; } };
  function rememberSeen(items) {
    if (!seenKey || !items.length) return;
    try {
      const map = new Map(loadSeen().map(row => [row.key, row]));
      items.forEach(item => map.set(item.key, {
        key: item.key, kind: item.kind, product_id: item.product_id, unit_id: item.unit_id, name: item.name, specs: item.specs,
        identifier: item.identifier, identifier_type: item.identifier_type, secondary_identifier: item.secondary_identifier,
        available_qty: item.available_qty, seen: Date.now()
      }));
      localStorage.setItem(seenKey, JSON.stringify([...map.values()].sort((a, b) => b.seen - a.seen).slice(0, 300)));
    } catch (_) { /* storage full or blocked: searching offline just finds less */ }
  }
  function searchSeen(query) {
    const needle = query.toLowerCase();
    return loadSeen().filter(row => [row.name, row.specs, row.identifier, row.secondary_identifier].some(value => String(value || '').toLowerCase().includes(needle))).slice(0, 20);
  }

  // ---- Prices (Patch 8). Everything is kept in whole cents. The catalog/placeholder price is never used.
  // item.entry = { mode: 'piece' | 'total', value: '<what the cashier typed>' }. The LINE TOTAL is the truth:
  //   piece → total = typed × qty;  total → total = typed, per-piece = total ÷ qty (rounded, shown only).
  // The server repeats this maths and adds up the grand total itself; these numbers are for display.
  const MAX_CENTS = 9999999999;
  const parseCents = text => {
    const m = /^(\d{1,8})(?:\.(\d{1,2}))?$/.exec(String(text ?? '').replace(/[,\s₱]/g, ''));
    return m ? Number(m[1]) * 100 + (m[2] ? Number(m[2].padEnd(2, '0')) : 0) : null;
  };
  const plainFromCents = cents => {
    const text = (cents / 100).toFixed(2);
    return text.replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
  };
  const shortMoney = cents => `₱${cents % 100 === 0 ? (cents / 100).toLocaleString('en-PH') : (cents / 100).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
  const qtyOf = item => Math.max(1, Number(item.quantity || 1));
  const entryOf = item => (item.entry && (item.entry.mode === 'piece' || item.entry.mode === 'total')) ? item.entry : null;
  function lineCents(item) {
    const entry = entryOf(item);
    if (!entry) return null;
    const typed = parseCents(entry.value);
    if (typed === null || typed <= 0) return null;
    const total = entry.mode === 'piece' ? typed * qtyOf(item) : typed;
    return total > MAX_CENTS ? null : total;
  }
  function unitCents(item) {
    const line = lineCents(item);
    if (line === null) return null;
    const qty = qtyOf(item);
    return Math.floor((2 * line + qty) / (2 * qty));
  }
  const allPriced = () => cart.length > 0 && cart.every(item => lineCents(item) !== null);
  const centsTotal = () => cart.reduce((sum, item) => sum + (lineCents(item) || 0), 0);
  const subtotal = () => centsTotal() / 100;
  const cartSig = () => JSON.stringify(cart.map(item => [item.key, item.quantity, item.entry?.mode || '', item.entry?.value || '']));

  function serializeCart() {
    return cart.map(item => {
      const entry = entryOf(item);
      return {
        kind: item.kind,
        product_id: Number(item.product_id),
        unit_id: item.unit_id ? Number(item.unit_id) : null,
        quantity: Number(item.quantity || 1),
        // the typed price travels with the sale so a retry (same key) always sends the same prices
        ...(entry ? { price_mode: entry.mode, price: String(entry.value || '') } : {})
      };
    });
  }

  function renderCart() {
    const total = subtotal();
    const priced = allPriced();
    cartJson.value = JSON.stringify(serializeCart());
    cartCount.textContent = `${cart.reduce((s,i)=>s+Number(i.quantity||1),0)} item${cart.reduce((s,i)=>s+Number(i.quantity||1),0)===1?'':'s'}`;
    subtotalEl.textContent = priced ? money(total) : '—';
    totalEl.textContent = priced ? money(total) : '—';
    completeTotal.textContent = priced ? money(total) : '';
    completeButton.disabled = cart.length === 0;
    if (clearCartButton) clearCartButton.disabled = cart.length === 0;
    cartEmpty.hidden = cart.length !== 0;

    cartLines.querySelectorAll('[data-cart-line]').forEach(el => el.remove());
    cart.forEach((item, index) => {
      const row = document.createElement('div');
      row.className = 'pos-cart-line';
      row.dataset.cartLine = '1';
      const qtyUi = item.kind === 'accessory' ? `
        <div class="pos-qty-control">
          <button type="button" data-qty-minus="${index}" aria-label="Decrease quantity">−</button>
          <span>${Number(item.quantity)}</span>
          <button type="button" data-qty-plus="${index}" aria-label="Increase quantity">+</button>
        </div>` : `<span class="mini-chip">1 unit</span>`;
      const identifier = item.identifier ? `<span>${escapeHtml(item.identifier_type || 'Device ID')}: <b>${escapeHtml(item.identifier)}</b>${item.secondary_identifier ? ` • IMEI 2: <b>${escapeHtml(item.secondary_identifier)}</b>` : ''}</span>` : '';
      row.innerHTML = `
        <div class="pos-cart-line-main">
          <div class="pos-cart-line-icon">${item.kind === 'device' ? '▣' : '◇'}</div>
          <div class="pos-cart-line-copy"><strong>${escapeHtml(item.name)}</strong><span>${escapeHtml(item.specs || '—')}</span>${identifier}</div>
          <button type="button" class="pos-remove-line" data-remove-line="${index}" aria-label="Remove item">×</button>
        </div>
        <div class="pos-cart-line-footer">${qtyUi}<div>${lineCents(item) === null ? '<span>Price set at checkout</span><strong>—</strong>' : `<span>${money(unitCents(item) / 100)}${item.kind === 'accessory' ? ' each' : ''}</span><strong>${money(lineCents(item) / 100)}</strong>`}</div></div>`;
      cartLines.appendChild(row);
    });

    persist();
    syncPaymentFields();
    syncResultCards();
  }

  function syncResultCards() {
    resultsBox.querySelectorAll('.pos-result-card').forEach(card => {
      const inCart = cart.find(row => row.key === card.dataset.key);
      const item = resultItems.get(card.dataset.key);
      const label = card.querySelector('.pos-result-add');
      if (!label) return;
      const held = item ? isHeld(item) : false;
      card.classList.toggle('is-added', !!inCart);
      card.classList.toggle('is-queued', held);
      card.classList.toggle('has-queued', held || heldQty(item || {}) > 0);
      card.setAttribute('aria-disabled', held ? 'true' : 'false');
      const stock = card.querySelector('[data-stock]');
      if (stock && item) {
        const queuedQty = heldQty(item);
        if (held) stock.textContent = 'In a queued sale';
        else if (item.kind === 'device') stock.textContent = 'Exact unit';
        else stock.textContent = `${availQty(item)} in stock${queuedQty ? ` · ${queuedQty} in a queued sale` : ''}`;
      }
      if (held) label.textContent = 'In a queued sale';
      else if (!inCart) label.textContent = '+ Add';
      else if (inCart.kind === 'accessory') label.textContent = `✓ ${Number(inCart.quantity)} in cart · + Add`;
      else label.textContent = '✓ In cart';
    });
  }

  // fromScan: scanner/Enter flow clears the box for the next scan; clicking a
  // result keeps the list open so several items can be added in a row.
  function addItem(item, fromScan = false) {
    const existingIndex = cart.findIndex(row => row.key === item.key);
    if (existingIndex >= 0) {
      if (item.kind === 'accessory') {
        const next = Number(cart[existingIndex].quantity || 1) + 1;
        if (next <= availQty(item)) {
          cart[existingIndex].quantity = next;
        } else {
          searchStatus.textContent = `Maximum available stock reached (${availQty(item)}).`;
          return;
        }
      } else {
        searchStatus.textContent = 'That exact serialized unit is already in the cart.';
        return;
      }
    } else {
      if (isHeld(item)) {
        searchStatus.textContent = `${item.name} is in a queued sale on this device. It will be sent when you are back online.`;
        return;
      }
      cart.push({...item, quantity: 1});
    }
    renderCart();
    if (fromScan) {
      searchInput.value = '';
      resultsBox.innerHTML = '';
      searchStatus.textContent = `${item.name} added. Scan or search the next product.`;
      searchInput.focus();
    } else {
      syncResultCards();
      searchStatus.textContent = `${item.name} added. Keep adding, or search for another product.`;
    }
  }

  function renderResults(items) {
    resultsBox.innerHTML = '';
    if (!items.length) {
      searchStatus.textContent = 'No available brand-new stock found in this branch.';
      return;
    }
    searchStatus.textContent = `${items.length} available result${items.length===1?'':'s'}`;
    resultItems = new Map(items.map(item => [item.key, item]));
    items.forEach(item => {
      const card = document.createElement('button');
      card.type = 'button';
      card.className = 'pos-result-card';
      card.dataset.key = item.key;
      card.innerHTML = `
        <div class="pos-result-icon">${item.kind === 'device' ? '▣' : '◇'}</div>
        <div class="pos-result-copy"><strong>${escapeHtml(item.name)}</strong><span>${escapeHtml(item.specs || '—')}</span>${item.identifier ? `<small>${escapeHtml(item.identifier_type)}: ${escapeHtml(item.identifier)}${item.secondary_identifier ? ` • IMEI 2: ${escapeHtml(item.secondary_identifier)}` : ''}</small>` : `<small>${Number(item.available_qty || 0)} available</small>`}</div>
        <div class="pos-result-price"><strong data-stock>${item.kind === 'device' ? 'Exact unit' : `${Number(item.available_qty)} in stock`}</strong><span>Price set at checkout</span></div>
        <span class="pos-result-add">+ Add</span>`;
      card.addEventListener('click', () => addItem(item));
      resultsBox.appendChild(card);
    });
    syncResultCards();
  }

  async function runSearch(query, autoAddSingle=false) {
    const trimmed = query.trim();
    if (!trimmed) {
      resultsBox.innerHTML = '';
      searchStatus.textContent = 'Start typing or scan an item to search this branch.';
      return;
    }
    const searchId = ++latestSearch;
    searchStatus.textContent = 'Searching available stock…';
    try {
      let response;
      try {
        response = await fetch(`${searchUrl}?branch_id=${encodeURIComponent(branchId)}&q=${encodeURIComponent(trimmed)}`, {headers:{Accept:'application/json'}});
      } catch (networkError) {
        if (!seenKey) throw networkError;
        // No internet: search only what this device saw earlier so the cashier can keep selling. The server re-checks every item later.
        if (searchId !== latestSearch) return;
        const found = searchSeen(trimmed);
        resultsBox.innerHTML = '';
        if (!found.length) { searchStatus.textContent = 'No internet. Nothing like that was seen earlier on this device.'; return; }
        renderResults(found);
        searchStatus.textContent = `No internet. Showing ${found.length} item${found.length === 1 ? '' : 's'} seen earlier on this device. The server checks every item when the sale is sent.`;
        if (autoAddSingle && found.length === 1) addItem(found[0], true);
        return;
      }
      let data;
      try { data = await response.json(); }
      catch (_) { throw new Error('POS search returned an invalid response. Please refresh and try again.'); }
      if (searchId !== latestSearch) return;
      if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to search inventory.');
      rememberSeen(data.items || []);
      renderResults(data.items || []);
      if (autoAddSingle && data.items?.length === 1) addItem(data.items[0], true);
    } catch (error) {
      resultsBox.innerHTML = '';
      searchStatus.textContent = error.message || 'Unable to search inventory.';
    }
  }

  searchInput?.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => runSearch(searchInput.value, false), 220);
  });
  searchInput?.addEventListener('keydown', event => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    clearTimeout(searchTimer);
    runSearch(searchInput.value, true);
  });

  // Camera scanner fills the box, then asks us to search and auto-add a single match.
  searchInput?.addEventListener('devicescan', () => {
    clearTimeout(searchTimer);
    runSearch(searchInput.value, true);
  });

  clearCartButton?.addEventListener('click', () => {
    if (!cart.length) return;
    if (!window.confirm('Clear all items from the current cart?')) return;
    cart = [];
    renderCart();
    searchStatus.textContent = 'Cart cleared. Scan or search a product to start a new sale.';
    searchInput?.focus();
  });

  cartLines?.addEventListener('click', event => {
    const remove = event.target.closest('[data-remove-line]');
    if (remove) {
      cart.splice(Number(remove.dataset.removeLine), 1);
      renderCart();
      return;
    }
    const plus = event.target.closest('[data-qty-plus]');
    if (plus) {
      const i = Number(plus.dataset.qtyPlus);
      const item = cart[i];
      if (item && item.kind === 'accessory' && Number(item.quantity) < availQty(item)) item.quantity += 1;
      renderCart();
      return;
    }
    const minus = event.target.closest('[data-qty-minus]');
    if (minus) {
      const i = Number(minus.dataset.qtyMinus);
      const item = cart[i];
      if (item && item.kind === 'accessory') {
        item.quantity = Math.max(1, Number(item.quantity) - 1);
        renderCart();
      }
    }
  });

  function syncPaymentFields() {
    const method = selectedPayment();
    root.querySelectorAll('.payment-method').forEach(label => label.classList.toggle('active', label.querySelector('input')?.checked));
    const isCash = method === 'cash';
    cashField?.classList.toggle('hidden', !isCash);
    changeBox?.classList.toggle('hidden', !isCash);
    referenceField?.classList.toggle('hidden', isCash);
    if (cashInput) cashInput.required = isCash && cart.length > 0;
    syncChange();
  }

  function syncChange() {
    if (!changeEl) return;
    const due = allPriced() ? subtotal() : 0;
    const received = Number(cashInput?.value || 0);
    changeEl.textContent = due > 0 ? money(Math.max(0, received - due)) : money(0);
  }

  root.querySelectorAll('input[name="payment_method"]').forEach(input => input.addEventListener('change', syncPaymentFields));
  cashInput?.addEventListener('input', syncChange);

  function openConfirm() {
    if (!confirmModal) return;
    const count = cart.reduce((s,i)=>s+Number(i.quantity||1),0);
    if (confirmItems) confirmItems.textContent = `${count} item${count===1?'':'s'}`;
    if (confirmPayment) confirmPayment.textContent = paymentLabel(selectedPayment());
    if (confirmTotal) confirmTotal.textContent = money(subtotal());
    confirmModal.hidden = false;
    document.body.classList.add('modal-open');
  }
  function closeConfirm() {
    if (!confirmModal || saleSaving) return;
    confirmModal.hidden = true;
    document.body.classList.remove('modal-open');
  }

  // ------------------------------------------------------------------ Set Prices (Patch 8)
  // Checkout → Set Prices → Confirm Sale → (unchanged) cash check + "Complete this transaction?" → SafeSubmit.
  const cashHint = document.createElement('p');
  cashHint.className = 'pos-cash-hint';
  cashHint.setAttribute('role', 'alert');
  cashHint.hidden = true;
  cashField?.after(cashHint);
  cashInput?.addEventListener('input', () => { cashHint.hidden = true; });

  const sheetOpen = () => !!sheet && !sheet.hidden;
  const rowOf = index => sheetRows.querySelector(`.price-row[data-i="${index}"]`);

  function sanitizePrice(text) {
    const cleaned = String(text).replace(/[^\d.,]/g, '').replace(/,/g, '');
    const parts = cleaned.split('.');
    return parts[0].slice(0, 8) + (parts.length > 1 ? '.' + parts.slice(1).join('').slice(0, 2) : '');
  }

  function updateRow(index) {
    const item = cart[index];
    const row = rowOf(index);
    if (!item || !row) return;
    const entry = entryOf(item) || { mode: 'piece', value: '' };
    const qty = qtyOf(item);
    const line = lineCents(item);
    const typed = parseCents(entry.value);
    row.classList.toggle('is-missing', line === null);
    row.querySelector('[data-error]').textContent = String(entry.value || '').trim() === '' ? 'Enter price' : 'Enter a price above 0';
    row.querySelectorAll('[data-mode]').forEach(button => {
      const active = button.dataset.mode === entry.mode;
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
      button.classList.toggle('is-active', active);
    });
    row.querySelector('[data-label]').textContent = entry.mode === 'piece' ? 'Price per piece' : `Total for all ${qty} ${qty === 1 ? 'piece' : 'pieces'}`;
    const detail = line === null ? '' :
      (entry.mode === 'piece' ? (qty > 1 ? `${qty} × ${money(typed / 100)}` : '') : (qty > 1 ? `${money(unitCents(item) / 100)} each` : ''));
    const detailEl = row.querySelector('[data-calc-detail]');
    detailEl.textContent = detail;
    detailEl.hidden = detail === '';
    const lineEl = row.querySelector('[data-line-total]');
    lineEl.textContent = line === null ? '—' : money(line / 100);
    lineEl.classList.toggle('is-empty', line === null);
    const chip = row.querySelector('[data-last]');
    const last = lastPrices[String(item.product_id)];
    chip.hidden = !last;
    if (last) chip.textContent = `Last: ${shortMoney(last)}${qty > 1 ? ' each' : ''}`;
  }

  function updateSheetFooter() {
    const partial = centsTotal();
    sheetGrand.textContent = money(partial / 100);
    sheetConfirm.disabled = !allPriced();
  }

  function updateAllRows() {
    cart.forEach((_, index) => updateRow(index));
    updateSheetFooter();
  }

  function renderPriceRows() {
    sheetRows.innerHTML = '';
    cart.forEach((item, index) => {
      const row = document.createElement('div');
      row.className = 'price-row';
      row.dataset.i = String(index);
      const qty = qtyOf(item);
      row.innerHTML = `
        <div class="price-row-head">
          <div class="price-row-name"><strong>${escapeHtml(item.name)}</strong><span>${escapeHtml(item.specs || '')}</span></div>
          <span class="price-row-qty">${item.kind === 'device' ? '1 unit' : `Qty ${qty}`}</span>
        </div>
        <div class="price-mode" role="group" aria-label="How do you want to enter the price for ${escapeHtml(item.name)}?">
          <button type="button" data-mode="piece" aria-pressed="true">Per piece</button>
          <button type="button" data-mode="total" aria-pressed="false">Total for all</button>
        </div>
        <div class="price-field">
          <label class="price-input">
            <span class="price-input-label" data-label>Price per piece</span>
            <span class="price-input-box"><b aria-hidden="true">₱</b><input type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" data-price-input aria-label="Price for ${escapeHtml(item.name)}"></span>
          </label>
          <div class="price-line"><span>Line total</span><strong data-line-total>—</strong></div>
          <small class="price-line-detail" data-calc-detail hidden></small>
        </div>
        <button type="button" class="price-chip" data-last hidden></button>
        <p class="price-row-error" data-error role="alert">Enter price</p>`;
      row.querySelector('input').value = entryOf(item)?.value || '';
      sheetRows.appendChild(row);
    });
    sameBox.hidden = cart.length < 2;
    samePanel.hidden = true;
    sameToggle.setAttribute('aria-expanded', 'false');
    updateAllRows();
  }

  function setEntry(index, mode, value) {
    cart[index].entry = { mode, value };
    persist();
    cartJson.value = JSON.stringify(serializeCart());
  }

  function focusNextAfter(index) {
    for (let i = index + 1; i < cart.length; i += 1) {
      const next = rowOf(i)?.querySelector('input');
      if (next) { next.focus(); next.select?.(); return; }
    }
    if (!sheetConfirm.disabled) sheetConfirm.focus();
    else (sheetRows.querySelector('.price-row.is-missing input'))?.focus();
  }

  function focusFirstEmpty() {
    const target = sheetRows.querySelector('.price-row.is-missing input');
    if (target) target.focus();
    else sheetConfirm.focus();
  }

  async function fetchLastPrices() {
    const ids = [...new Set(cart.map(item => Number(item.product_id)))].filter(id => id && !lastPricesAsked.has(id) && !(String(id) in lastPrices));
    if (!ids.length) return;
    ids.forEach(id => lastPricesAsked.add(id));
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = setTimeout(() => controller?.abort(), 5000);
    try {
      const response = await fetch(`${lastPricesUrl}?product_ids=${ids.join(',')}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller?.signal });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error('no history');
      Object.entries(data.prices || {}).forEach(([productId, price]) => {
        const cents = parseCents(price);
        if (cents && cents > 0) lastPrices[productId] = cents;
      });
    } catch (_) {
      ids.forEach(id => lastPricesAsked.delete(id)); // chips are optional: try again next time the sheet opens
    } finally {
      clearTimeout(timer);
    }
    if (sheetOpen()) updateAllRows();
  }

  function openPriceSheet() {
    if (!sheet || !cart.length) return;
    skipSheetSig = null;
    cashHint.hidden = true;
    renderPriceRows();
    sheet.hidden = false;
    document.body.classList.add('modal-open');
    sheet.querySelector('.price-sheet-body').scrollTop = 0;
    focusFirstEmpty();
    fetchLastPrices();
  }

  function closePriceSheet(refocusSearch = true) {
    if (!sheetOpen()) return;
    sheet.hidden = true;
    document.body.classList.remove('modal-open');
    if (refocusSearch) searchInput?.focus(); // keep the barcode box ready, as before
  }

  // After the prices are confirmed: the existing cash check, then the existing confirm dialog.
  function afterPrices() {
    renderCart();
    if (selectedPayment() === 'cash' && Math.round(Number(cashInput?.value || 0) * 100) < centsTotal()) {
      skipSheetSig = cartSig();
      cashHint.textContent = `Enter the cash received (${money(subtotal())} or more), then tap Checkout.`;
      cashHint.hidden = false;
      cashInput?.classList.add('input-error');
      setTimeout(() => cashInput?.classList.remove('input-error'), 1200);
      cashInput?.scrollIntoView({ block: 'center' });
      cashInput?.focus();
      return;
    }
    openConfirm();
  }

  sheetRows?.addEventListener('input', event => {
    const input = event.target.closest('[data-price-input]');
    if (!input) return;
    const index = Number(input.closest('.price-row').dataset.i);
    const clean = sanitizePrice(input.value);
    if (clean !== input.value) input.value = clean;
    setEntry(index, entryOf(cart[index])?.mode || 'piece', clean);
    updateRow(index);
    updateSheetFooter();
  });
  // Select the existing value on focus so typing replaces it (timeout: mobile browsers drop a selection made during the tap).
  const selectOnFocus = event => { const input = event.target; if (input.matches?.('[data-price-input], [data-price-same-input]')) setTimeout(() => input.select?.(), 0); };
  sheetRows?.addEventListener('focusin', selectOnFocus);
  sameInput?.addEventListener('focus', selectOnFocus);
  sheetRows?.addEventListener('keydown', event => {
    const input = event.target.closest('[data-price-input]');
    if (!input || event.key !== 'Enter') return;
    event.preventDefault();
    focusNextAfter(Number(input.closest('.price-row').dataset.i));
  });
  sheetRows?.addEventListener('click', event => {
    const row = event.target.closest('.price-row');
    if (!row) return;
    const index = Number(row.dataset.i);
    const item = cart[index];
    const input = row.querySelector('input');
    const modeButton = event.target.closest('[data-mode]');
    if (modeButton) {
      const entry = entryOf(item) || { mode: 'piece', value: '' };
      const mode = modeButton.dataset.mode;
      if (entry.mode !== mode) {
        // Keep the same money: per piece → total is exact; total → per piece only when it divides evenly.
        const typed = parseCents(entry.value);
        const qty = qtyOf(item);
        let value = '';
        if (typed && typed > 0) {
          if (mode === 'total') value = plainFromCents(typed * qty);
          else if (typed % qty === 0) value = plainFromCents(typed / qty);
        }
        setEntry(index, mode, value);
        input.value = value;
        updateRow(index);
        updateSheetFooter();
      }
      input.focus();
      return;
    }
    if (event.target.closest('[data-last]')) {
      const last = lastPrices[String(item.product_id)];
      if (!last) return;
      const mode = entryOf(item)?.mode || 'piece';
      const value = plainFromCents(mode === 'piece' ? last : last * qtyOf(item));
      setEntry(index, mode, value);
      input.value = value;
      updateRow(index);
      updateSheetFooter();
      input.focus();
    }
  });

  sameToggle?.addEventListener('click', () => {
    const open = samePanel.hidden;
    samePanel.hidden = !open;
    sameToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) sameInput.focus();
  });
  function applySamePrice() {
    const cents = parseCents(sameInput.value);
    if (cents === null || cents <= 0) {
      sameInput.classList.add('input-error');
      setTimeout(() => sameInput.classList.remove('input-error'), 1200);
      sameInput.focus();
      return;
    }
    cart.forEach((_, index) => setEntry(index, 'piece', plainFromCents(cents)));
    sameInput.value = '';
    renderPriceRows();
    focusFirstEmpty();
  }
  sameApply?.addEventListener('click', applySamePrice);
  sameInput?.addEventListener('input', () => { const clean = sanitizePrice(sameInput.value); if (clean !== sameInput.value) sameInput.value = clean; });
  sameInput?.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); applySamePrice(); } });

  sheetConfirm?.addEventListener('click', () => {
    if (!allPriced()) return;
    closePriceSheet(false);
    afterPrices();
  });
  sheet?.querySelectorAll('[data-price-close]').forEach(button => button.addEventListener('click', () => closePriceSheet()));
  sheet?.addEventListener('keydown', event => {
    if (event.key === 'Escape') { event.preventDefault(); closePriceSheet(); return; }
    if (event.key !== 'Tab') return;
    const focusable = [...sheet.querySelectorAll('button, input')].filter(el => !el.disabled && !el.hidden && el.offsetParent !== null);
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });

  if (checkoutForm) checkoutForm.noValidate = true; // cash is checked after the prices are known (afterPrices)
  checkoutForm?.addEventListener('submit', event => {
    if (finalSubmitApproved) return;
    event.preventDefault();
    if (!cart.length) return;
    // Cash was too low after the prices were confirmed and nothing changed since: don't ask for the prices again.
    if (skipSheetSig !== null && skipSheetSig === cartSig() && allPriced()) {
      skipSheetSig = null;
      afterPrices();
      return;
    }
    openPriceSheet();
  });
  // ------------------------------------------------------------------ Sale saved (Patch 8B)
  // Replaces the old green "Saved" card + OK button. saleSaving stays true on purpose: the modal can no longer
  // be closed or re-submitted, the only way on is "New Sale" (clears the cart and reloads the page).
  const doneBox = confirmModal?.querySelector('[data-pos-done]');
  const doneSet = (name, text) => { const node = doneBox?.querySelector(`[data-done-${name}]`); if (node) node.textContent = text; };
  let queuedSaleShown = false; // the sale was saved on this device (offline): New Sale must not reload the page
  function showSaleDone(data) {
    if (!doneBox) return;
    const isCash = String(selectedPayment()) === 'cash';
    const change = Number(data.change || 0);
    const received = Number(cashInput?.value || 0);
    doneSet('title', 'Sale saved');
    doneSet('no', data.sale_no ? `Sale no. ${data.sale_no}` : '');
    doneSet('total', money(data.total));
    doneSet('payment', data.payment || paymentLabel(selectedPayment()));
    doneSet('cash', money(received));
    doneSet('change', money(change));
    doneBox.querySelector('[data-done-note]').hidden = true;
    doneBox.querySelector('[data-done-cash-row]').hidden = !isCash;
    doneBox.querySelector('[data-done-change-row]').hidden = !isCash;
    confirmModal.querySelector('.pos-confirm-dialog').classList.add('is-done');
    doneBox.hidden = false;
    doneBox.focus();
    doneBox.querySelector('[data-pos-new-sale]')?.focus();
  }

  // ------------------------------------------------------------------ Sale saved on this device (Patch 6c-2)
  // The internet dropped at Confirm: the sale is kept in the outbox (assets/js/outbox.js) under the same client key and sent
  // when the connection is back. Everything shown here is for the cashier only; the server recalculates the real sale.
  const askBox = confirmModal?.querySelector('[data-pos-offline-ask]');
  const confirmDialog = confirmModal?.querySelector('.pos-confirm-dialog');
  const stampOf = ms => { const d = new Date(ms); return `${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}`; };
  const seqKeyOf = stamp => `malbcoff_offline_seq_${branchId}_${stamp}`;
  const readSeq = stamp => { try { return Number(localStorage.getItem(seqKeyOf(stamp)) || 0) || 0; } catch (_) { return 0; } };
  // OFFLINE-<branch>-<date>-<n>: temporary, only for this device. The counter moves forward only when a sale was really saved.
  function peekReceipt(madeAt) {
    const stamp = stampOf(madeAt);
    const n = readSeq(stamp) + 1;
    return { stamp, n, no: `OFFLINE-${branchCode}-${stamp}-${n}` };
  }
  function commitReceipt(receipt) {
    try { localStorage.setItem(seqKeyOf(receipt.stamp), String(Math.max(readSeq(receipt.stamp), receipt.n))); } catch (_) { /* the number is temporary anyway */ }
  }

  function describeSale(madeAt, receiptNo) {
    const method = selectedPayment();
    const isCash = method === 'cash';
    const total = subtotal();
    const received = Number(cashInput?.value || 0);
    const change = isCash ? Math.max(0, Math.round(received * 100) - centsTotal()) / 100 : 0;
    const reference = (root.querySelector('input[name="payment_reference"]')?.value || '').trim();
    const count = cart.reduce((sum, item) => sum + qtyOf(item), 0);
    const when = new Date(madeAt).toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    const lines = [
      `Temporary receipt: ${receiptNo}`,
      `Total: ${money(total)}`,
      isCash
        ? `Payment: Cash · received ${money(received)} · change ${money(change)}`
        : `Payment: ${paymentLabel(method)}${reference ? ` · reference ${reference}` : ''} · cashier confirmed it was received while offline`,
      `Sale made on the device: ${when}`,
      ...cart.map(item => {
        const id = item.identifier ? ` · ${item.identifier_type || 'ID'} ${item.identifier}${item.secondary_identifier ? ` / IMEI 2 ${item.secondary_identifier}` : ''}` : '';
        return `${item.name}${item.specs ? ` (${item.specs})` : ''}${id} — ${qtyOf(item)} × ${money(unitCents(item) / 100)} = ${money(lineCents(item) / 100)}`;
      })
    ];
    return {
      summary: `${receiptNo} · ${count} item${count === 1 ? '' : 's'} · ${money(total)} · ${paymentLabel(method)}`,
      lines,
      holds: cart.map(item => ({ key: item.key, qty: item.kind === 'accessory' ? qtyOf(item) : 1 })),
      total, received, change, isCash, method
    };
  }

  // Non-cash money cannot be checked without the internet: the cashier must confirm the payment before the sale is saved.
  function askPaymentReceived() {
    return new Promise(resolve => {
      if (!askBox || !confirmDialog) { resolve(false); return; }
      const check = askBox.querySelector('[data-ask-check]');
      const save = askBox.querySelector('[data-ask-save]');
      const back = askBox.querySelector('[data-ask-back]');
      askBox.querySelector('[data-ask-method]').textContent = paymentLabel(selectedPayment());
      check.checked = false;
      save.disabled = true;
      const finish = answer => {
        askBox.hidden = true;
        confirmDialog.classList.remove('is-asking');
        resolve(answer);
      };
      check.onchange = () => { save.disabled = !check.checked; };
      save.onclick = () => { if (check.checked) finish(true); };
      back.onclick = () => finish(false);
      askBox.onkeydown = event => { if (event.key === 'Escape') { event.preventDefault(); finish(false); } };
      confirmDialog.classList.add('is-asking');
      askBox.hidden = false;
      askBox.focus();
      check.focus();
    });
  }

  function showSaleQueued(info, receipt, needsLogin) {
    if (!doneBox) return;
    queuedSaleShown = true;
    doneSet('title', 'Sale saved on this device');
    doneSet('no', `Temporary receipt no. ${receipt.no}`);
    doneSet('total', money(info.total));
    doneSet('payment', paymentLabel(info.method));
    doneSet('cash', money(info.received));
    doneSet('change', money(info.change));
    const note = doneBox.querySelector('[data-done-note]');
    note.textContent = needsLogin ? 'Log in again and it will be sent. Nothing was lost.' : 'It will be sent when you are back online.';
    note.hidden = false;
    doneBox.querySelector('[data-done-cash-row]').hidden = !info.isCash;
    doneBox.querySelector('[data-done-change-row]').hidden = !info.isCash;
    confirmDialog.classList.add('is-done');
    doneBox.hidden = false;
    doneBox.focus();
    doneBox.querySelector('[data-pos-new-sale]')?.focus();
  }

  // Next customer without reloading: a reload would fail while the phone is offline.
  function startNewSaleHere() {
    cart = [];
    sessionStorage.removeItem(storageKey);
    queuedSaleShown = false;
    saleSaving = false;
    skipSheetSig = null;
    delete checkoutForm.dataset.madeAt;
    if (cashInput) cashInput.value = '';
    const reference = root.querySelector('input[name="payment_reference"]');
    if (reference) reference.value = '';
    const cashRadio = root.querySelector('input[name="payment_method"][value="cash"]');
    if (cashRadio) cashRadio.checked = true;
    cashHint.hidden = true;
    confirmDialog.classList.remove('is-done', 'is-asking');
    doneBox.hidden = true;
    confirmModal.hidden = true;
    document.body.classList.remove('modal-open');
    resultsBox.innerHTML = '';
    resultItems = new Map();
    if (searchInput) searchInput.value = '';
    searchStatus.textContent = 'Sale saved on this device. Scan or search the next product.';
    renderCart();
    searchInput?.focus();
  }

  doneBox?.querySelector('[data-pos-new-sale]')?.addEventListener('click', event => {
    if (queuedSaleShown) { startNewSaleHere(); return; }
    event.currentTarget.disabled = true;
    sessionStorage.removeItem(storageKey);
    window.location.reload();
  });

  confirmSubmit?.addEventListener('click', async () => {
    if (saleSaving) return;
    if (!window.SafeSubmit) {
      // Helper script missing: fall back to the classic form post (same behaviour as before).
      finalSubmitApproved = true;
      closeConfirm();
      completeButton.disabled = true;
      confirmSubmit.disabled = true;
      checkoutForm.submit();
      return;
    }
    saleSaving = true;
    completeButton.disabled = true;
    const csrfField = checkoutForm.querySelector('input[name="_csrf"]');
    // Device time of this sale = the first tap of Confirm for this client key (retries keep it).
    if (!checkoutForm.dataset.madeAt) checkoutForm.dataset.madeAt = String(Date.now());
    const madeAt = Number(checkoutForm.dataset.madeAt);
    let queuedReceipt = null;
    let queuedInfo = null;
    // If the network fails, SafeSubmit asks outbox.js to keep the sale on this device (same key = the sale's client id).
    const outboxCfg = {
      type: 'sale',
      madeAt,
      beforeQueue: async () => {
        queuedReceipt = peekReceipt(madeAt);
        queuedInfo = describeSale(madeAt, queuedReceipt.no);
        Object.assign(outboxCfg, {
          summary: queuedInfo.summary,
          lines: queuedInfo.lines,
          holds: queuedInfo.holds,
          receipt: queuedReceipt.no,
          extra: { offline_device_time: String(madeAt), offline_receipt_no: queuedReceipt.no }
        });
        return queuedInfo.isCash ? true : askPaymentReceived();
      }
    };
    // The cart and the form stay exactly as they are until the server confirms the sale.
    const result = await window.SafeSubmit.run({
      url: checkoutForm.getAttribute('action') || 'actions/complete_sale.php',
      keyHolder: checkoutForm,
      button: confirmSubmit,
      host: confirmModal.querySelector('.pos-confirm-dialog'),
      before: confirmModal.querySelector('.pos-confirm-actions'),
      getBody: () => new FormData(checkoutForm),
      onCsrf: token => { if (csrfField) csrfField.value = token; },
      quietSuccess: true, // Patch 8B: no green "Saved" card, the compact "Sale saved" state below replaces it
      onSuccess: data => { delete checkoutForm.dataset.madeAt; showSaleDone(data); },
      outbox: outboxCfg,
      onQueued: (queued, how) => {
        commitReceipt(queuedReceipt);
        // The sale now lives in the outbox: a reload must not bring the same cart back.
        sessionStorage.removeItem(storageKey);
        delete checkoutForm.dataset.madeAt;
        showSaleQueued(queuedInfo, queuedReceipt, how && how.needsLogin);
      }
    });
    if (result.queued) return; // stays locked until "New Sale"
    if (!result.ok) {
      saleSaving = false;
      completeButton.disabled = cart.length === 0;
    }
  });
  document.querySelectorAll('[data-pos-confirm-close]').forEach(button => button.addEventListener('click', closeConfirm));
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && confirmModal && !confirmModal.hidden) closeConfirm(); });

  // Queued sales changed (queued here or in another tab, sent, refused): refresh "In a queued sale" on the result cards.
  window.addEventListener('outbox:holds', syncResultCards);
  window.Outbox?.loaded?.then(syncResultCards);

  renderCart();
  syncPaymentFields();
  requestAnimationFrame(() => searchInput?.focus());
})();
