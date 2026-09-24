(() => {
  const root = document.querySelector('[data-pos-root]');
  if (!root) return;

  const branchId = root.dataset.branchId || '';
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

  let cart = [];
  let searchTimer = null;
  let latestSearch = 0;
  let finalSubmitApproved = false;

  if (document.querySelector('[data-sale-complete="1"]')) sessionStorage.removeItem(storageKey);
  try {
    const saved = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
    if (Array.isArray(saved)) cart = saved;
  } catch (_) {}

  const money = value => `₱${Number(value || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const selectedPayment = () => root.querySelector('input[name="payment_method"]:checked')?.value || 'cash';
  const paymentLabel = value => ({cash:'Cash',gcash:'GCash',maya:'Maya',card:'Card',bank_transfer:'Bank Transfer'}[value] || 'Cash');
  const subtotal = () => cart.reduce((sum, item) => sum + Number(item.price || 0) * Number(item.quantity || 1), 0);
  const persist = () => sessionStorage.setItem(storageKey, JSON.stringify(cart));

  function serializeCart() {
    return cart.map(item => ({
      kind: item.kind,
      product_id: Number(item.product_id),
      unit_id: item.unit_id ? Number(item.unit_id) : null,
      quantity: Number(item.quantity || 1)
    }));
  }

  function renderCart() {
    const total = subtotal();
    cartJson.value = JSON.stringify(serializeCart());
    cartCount.textContent = `${cart.reduce((s,i)=>s+Number(i.quantity||1),0)} item${cart.reduce((s,i)=>s+Number(i.quantity||1),0)===1?'':'s'}`;
    subtotalEl.textContent = money(total);
    totalEl.textContent = money(total);
    completeTotal.textContent = money(total);
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
        <div class="pos-cart-line-footer">${qtyUi}<div><span>${money(item.price)}${item.kind === 'accessory' ? ' each' : ''}</span><strong>${money(Number(item.price) * Number(item.quantity || 1))}</strong></div></div>`;
      cartLines.appendChild(row);
    });

    persist();
    syncPaymentFields();
  }

  function addItem(item) {
    const existingIndex = cart.findIndex(row => row.key === item.key);
    if (existingIndex >= 0) {
      if (item.kind === 'accessory') {
        const next = Number(cart[existingIndex].quantity || 1) + 1;
        if (next <= Number(item.available_qty || 0)) {
          cart[existingIndex].quantity = next;
        } else {
          searchStatus.textContent = `Maximum available stock reached (${Number(item.available_qty || 0)}).`;
          return;
        }
      } else {
        searchStatus.textContent = 'That exact serialized unit is already in the cart.';
        return;
      }
    } else {
      cart.push({...item, quantity: 1});
    }
    renderCart();
    searchInput.value = '';
    resultsBox.innerHTML = '';
    searchStatus.textContent = 'Item added. Scan or search the next product.';
    searchInput.focus();
  }

  function renderResults(items) {
    resultsBox.innerHTML = '';
    if (!items.length) {
      searchStatus.textContent = 'No available brand-new stock found in this branch.';
      return;
    }
    searchStatus.textContent = `${items.length} available result${items.length===1?'':'s'}`;
    items.forEach(item => {
      const card = document.createElement('button');
      card.type = 'button';
      card.className = 'pos-result-card';
      card.innerHTML = `
        <div class="pos-result-icon">${item.kind === 'device' ? '▣' : '◇'}</div>
        <div class="pos-result-copy"><strong>${escapeHtml(item.name)}</strong><span>${escapeHtml(item.specs || '—')}</span>${item.identifier ? `<small>${escapeHtml(item.identifier_type)}: ${escapeHtml(item.identifier)}${item.secondary_identifier ? ` • IMEI 2: ${escapeHtml(item.secondary_identifier)}` : ''}</small>` : `<small>${Number(item.available_qty || 0)} available</small>`}</div>
        <div class="pos-result-price"><strong>${money(item.price)}</strong><span>${item.kind === 'device' ? 'Exact unit' : `${Number(item.available_qty)} in stock`}</span></div>
        <span class="pos-result-add">+ Add</span>`;
      card.addEventListener('click', () => addItem(item));
      resultsBox.appendChild(card);
    });
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
      const response = await fetch(`${searchUrl}?branch_id=${encodeURIComponent(branchId)}&q=${encodeURIComponent(trimmed)}`, {headers:{Accept:'application/json'}});
      let data;
      try { data = await response.json(); }
      catch (_) { throw new Error('POS search returned an invalid response. Please refresh and try again.'); }
      if (searchId !== latestSearch) return;
      if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to search inventory.');
      renderResults(data.items || []);
      if (autoAddSingle && data.items?.length === 1) addItem(data.items[0]);
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
      if (item && item.kind === 'accessory' && Number(item.quantity) < Number(item.available_qty)) item.quantity += 1;
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
    const due = subtotal();
    const received = Number(cashInput?.value || 0);
    changeEl.textContent = money(Math.max(0, received - due));
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
    if (!confirmModal) return;
    confirmModal.hidden = true;
    document.body.classList.remove('modal-open');
  }

  checkoutForm?.addEventListener('submit', event => {
    if (finalSubmitApproved) return;
    event.preventDefault();
    if (!cart.length) return;
    if (selectedPayment() === 'cash' && Number(cashInput?.value || 0) < subtotal()) {
      cashInput?.focus();
      cashInput?.classList.add('input-error');
      setTimeout(() => cashInput?.classList.remove('input-error'), 1200);
      return;
    }
    openConfirm();
  });
  confirmSubmit?.addEventListener('click', () => {
    finalSubmitApproved = true;
    closeConfirm();
    completeButton.disabled = true;
    confirmSubmit.disabled = true;
    checkoutForm.submit();
  });
  document.querySelectorAll('[data-pos-confirm-close]').forEach(button => button.addEventListener('click', closeConfirm));
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && confirmModal && !confirmModal.hidden) closeConfirm(); });

  renderCart();
  syncPaymentFields();
  requestAnimationFrame(() => searchInput?.focus());
})();
