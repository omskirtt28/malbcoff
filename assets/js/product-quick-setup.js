(() => {
  'use strict';

  const modal = document.getElementById('quickVariantStockModal');
  if (!modal) return;

  const form = document.getElementById('quickVariantStockForm');
  const modelCreatedModal = document.getElementById('modelCreatedModal');
  const successModal = document.getElementById('quickStockSuccessModal');
  const unitList = modal.querySelector('[data-quick-unit-list]');
  const quantityInput = modal.querySelector('[data-quick-quantity]');
  const submitButton = modal.querySelector('[data-quick-submit]');
  const errorBox = modal.querySelector('[data-quick-error]');
  const progress = modal.querySelector('[data-scan-progress]');
  const connectivity = modal.querySelector('[data-quick-connectivity]');
  const modelId = Number(modal.dataset.modelId || 0);
  const modelLabel = modal.dataset.modelLabel || 'Selected Model';
  const productType = modal.dataset.productType || 'phone';
  const isApple = modal.dataset.isApple === '1';
  let identifierMode = isApple ? 'serial' : 'imei';
  let submitting = false;

  const clampQuantity = raw => Math.max(1, Math.min(100, Number(raw) || 1));
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));


  function clearFlowQuery(keys) {
    try {
      const url = new URL(window.location.href);
      let changed = false;
      for (const key of keys) {
        if (url.searchParams.has(key)) { url.searchParams.delete(key); changed = true; }
      }
      if (changed) history.replaceState(history.state, '', url.pathname + (url.search ? url.search : '') + url.hash);
    } catch (_) { /* URL cleanup is optional; never block the workflow. */ }
  }

  function openModal(target) {
    if (!target) return;
    target.hidden = false;
    document.body.classList.add('modal-open');
    const dialog = target.querySelector('.modal-dialog');
    requestAnimationFrame(() => dialog?.focus?.({preventScroll:true}));
  }

  function closeModal(target) {
    if (!target) return;
    target.hidden = true;
    if (!document.querySelector('.modal:not([hidden])')) document.body.classList.remove('modal-open');
  }

  function setError(message = '') {
    if (!errorBox) return;
    errorBox.textContent = message;
    errorBox.hidden = !message;
    if (message) errorBox.scrollIntoView({block:'nearest', behavior:'smooth'});
  }

  function setSubmitting(active, label = '') {
    submitting = active;
    if (!submitButton) return;
    submitButton.disabled = active;
    submitButton.classList.toggle('is-loading', active);
    if (!submitButton.dataset.defaultLabel) submitButton.dataset.defaultLabel = submitButton.innerHTML;
    submitButton.innerHTML = active ? `<span class="button-spinner" aria-hidden="true"></span>${esc(label || 'Saving…')}` : submitButton.dataset.defaultLabel;
  }

  function rowValues() {
    return [...unitList.querySelectorAll('[data-quick-unit-row]')].map(row => ({
      primary: row.querySelector('[data-primary-identifier]')?.value.trim().toUpperCase() || '',
      secondary: row.querySelector('[data-secondary-identifier]')?.value.trim().toUpperCase() || ''
    }));
  }

  function updateProgress() {
    const rows = rowValues();
    const complete = rows.filter(row => row.primary !== '').length;
    if (progress) progress.textContent = `${complete} / ${rows.length} scanned`;
  }

  function primaryLabel() {
    if (isApple) return 'Serial Number';
    return identifierMode === 'barcode' ? 'Serial / Barcode' : 'IMEI 1';
  }

  function scanMode() {
    if (isApple) return 'serial';
    return identifierMode === 'barcode' ? 'auto' : 'imei';
  }

  function renderRows() {
    const quantity = clampQuantity(quantityInput?.value);
    if (quantityInput) quantityInput.value = String(quantity);
    const previous = rowValues();
    const dualImei = !isApple && productType === 'phone' && identifierMode === 'imei';
    const label = primaryLabel();
    const mode = scanMode();
    unitList.innerHTML = '';

    for (let i = 0; i < quantity; i++) {
      const primaryId = `quickIdentifier${i + 1}`;
      const secondaryId = `quickSecondaryIdentifier${i + 1}`;
      const row = document.createElement('article');
      row.className = 'quick-unit-row';
      row.dataset.quickUnitRow = String(i + 1);
      const primaryValue = previous[i]?.primary || '';
      const secondaryValue = previous[i]?.secondary || '';
      row.innerHTML = `
        <div class="quick-unit-index">${i + 1}</div>
        <div class="quick-unit-fields">
          <label class="field quick-identifier-field">
            <span>${esc(label)} <b>*</b></span>
            <div class="identifier-input-row">
              <input id="${primaryId}" name="identifiers[]" data-primary-identifier autocomplete="off" spellcheck="false" ${identifierMode === 'imei' && !isApple ? 'inputmode="numeric" maxlength="15"' : 'maxlength="120"'} value="${esc(primaryValue)}" placeholder="SCAN OR ENTER ${esc(label.toUpperCase())}" required>
              <button class="btn btn-outline quick-scan-btn" type="button" data-device-scan data-scan-target="#${primaryId}" data-scan-mode="${mode}" data-scan-label="Scan ${esc(label)}">Scan</button>
            </div>
          </label>
          ${dualImei ? `
          <label class="field quick-identifier-field quick-secondary-field">
            <span>IMEI 2 <small>(Optional)</small></span>
            <div class="identifier-input-row">
              <input id="${secondaryId}" name="secondary_identifiers[]" data-secondary-identifier inputmode="numeric" maxlength="15" autocomplete="off" spellcheck="false" value="${esc(secondaryValue)}" placeholder="SCAN OR ENTER IMEI 2">
              <button class="btn btn-outline quick-scan-btn" type="button" data-device-scan data-scan-target="#${secondaryId}" data-scan-mode="imei" data-scan-label="Scan IMEI 2">Scan</button>
            </div>
          </label>` : '<input type="hidden" name="secondary_identifiers[]" value="">'}
          <input type="hidden" name="identifier_types[]" value="${isApple ? 'serial' : identifierMode === 'barcode' ? 'barcode' : 'imei'}">
        </div>`;
      unitList.appendChild(row);
    }
    updateProgress();
  }

  function setIdentifierMode(mode) {
    if (isApple) return;
    identifierMode = mode === 'barcode' ? 'barcode' : 'imei';
    modal.querySelectorAll('[data-quick-id-mode]').forEach(button => {
      const active = button.dataset.quickIdMode === identifierMode;
      button.classList.toggle('active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    renderRows();
  }

  function validateBeforeSubmit() {
    setError('');
    const required = [...form.querySelectorAll('[required]')].filter(field => !field.closest('[hidden]'));
    for (const field of required) {
      if (!field.checkValidity()) {
        field.reportValidity();
        field.focus({preventScroll:false});
        return false;
      }
    }

    const rows = rowValues();
    if (rows.length !== clampQuantity(quantityInput.value)) {
      setError('Quantity and identifier slots do not match.');
      return false;
    }
    const all = [];
    for (let i = 0; i < rows.length; i++) {
      const {primary, secondary} = rows[i];
      if (!primary) {
        setError(`Scan or enter ${primaryLabel()} for unit ${i + 1}.`);
        unitList.querySelectorAll('[data-primary-identifier]')[i]?.focus();
        return false;
      }
      if (isApple) {
        if (!/^[A-Z0-9]{1,80}$/.test(primary) || !/[A-Z]/.test(primary)) {
          setError(`Unit ${i + 1}: enter the Apple Serial Number (S/N), not the retail barcode or IMEI.`);
          return false;
        }
      } else if (identifierMode === 'imei') {
        if (!/^\d{15}$/.test(primary) || (window.MalbcoffImeiReader?.valid && !window.MalbcoffImeiReader.valid(primary))) {
          setError(`Unit ${i + 1}: IMEI 1 must be a valid 15-digit IMEI.`);
          return false;
        }
        if (secondary && (!/^\d{15}$/.test(secondary) || (window.MalbcoffImeiReader?.valid && !window.MalbcoffImeiReader.valid(secondary)))) {
          setError(`Unit ${i + 1}: IMEI 2 must be a valid 15-digit IMEI.`);
          return false;
        }
        if (secondary && secondary === primary) {
          setError(`Unit ${i + 1}: IMEI 1 and IMEI 2 cannot be the same.`);
          return false;
        }
      } else if (!/^[\x21-\x7e]{1,120}$/.test(primary)) {
        setError(`Unit ${i + 1}: Serial / Barcode must be 1 to 120 printable characters without spaces.`);
        return false;
      }
      all.push(primary);
      if (secondary) all.push(secondary);
    }
    if (new Set(all).size !== all.length) {
      setError('Duplicate IMEI / Serial / Barcode values were found in this list.');
      return false;
    }
    return true;
  }

  async function postForm(url, data) {
    const response = await fetch(url, {
      method: 'POST',
      body: data,
      headers: {'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'}
    });
    let payload = null;
    try { payload = await response.json(); }
    catch (_) { throw new Error('The server returned an invalid response. Refresh and try again.'); }
    if (!response.ok || !payload?.ok) throw new Error(payload?.error || 'Unable to complete this action.');
    return payload;
  }

  async function submitQuickSetup(event) {
    event.preventDefault();
    if (submitting || !validateBeforeSubmit()) return;
    const original = new FormData(form);
    const createData = new FormData();
    createData.set('_csrf', original.get('_csrf'));
    createData.set('ajax_action', 'create_variant');
    createData.set('model_id', String(modelId));
    createData.set('ram', String(original.get('ram') || ''));
    createData.set('storage', String(original.get('storage') || ''));
    createData.set('color', String(original.get('color') || ''));
    createData.set('connectivity', String(original.get('connectivity') || ''));
    createData.set('selling_price', String(original.get('selling_price') || ''));
    createData.set('branch_id', String(original.get('branch_id') || modal.dataset.defaultBranchId || ''));

    try {
      setSubmitting(true, 'Creating variant…');
      const created = await postForm('actions/stock_in.php', createData);
      const productId = Number(created.variant?.id || 0);
      if (!productId) throw new Error('Variant was created without a valid product record. Refresh and try again.');
      modal.querySelector('[data-quick-product-id]').value = String(productId);

      setSubmitting(true, 'Adding stock…');
      const receiveData = new FormData(form);
      receiveData.set('ajax_action', 'receive_stock');
      receiveData.set('product_id', String(productId));
      receiveData.set('branch_id', String(receiveData.get('branch_id') || modal.dataset.defaultBranchId || ''));
      const received = await postForm('actions/stock_in.php', receiveData);
      const stock = received.stock || {};
      const params = new URLSearchParams({page:'products', model:String(modelId), stock_done:'1', variant:String(productId)});
      if (stock.reference) params.set('stock_ref', String(stock.reference));
      window.location.assign(`index.php?${params.toString()}#variants`);
    } catch (error) {
      setError(error?.message || 'Unable to save the variant and stock. Nothing was hidden; correct the issue and try again.');
      setSubmitting(false);
    }
  }

  function resetForm() {
    form.reset();
    identifierMode = isApple ? 'serial' : 'imei';
    if (quantityInput) quantityInput.value = '1';
    modal.querySelector('[data-quick-product-id]').value = '';
    setError('');
    setSubmitting(false);
    modal.querySelectorAll('[data-quick-id-mode]').forEach(button => button.classList.toggle('active', button.dataset.quickIdMode === identifierMode));
    renderRows();
  }

  function openQuickVariant() {
    resetForm();
    openModal(modal);
    setTimeout(() => modal.querySelector('select[name="ram"], select[name="storage"]')?.focus(), 60);
  }

  document.addEventListener('click', event => {
    const opener = event.target.closest('[data-quick-variant-open]');
    if (opener) {
      event.preventDefault();
      if (modelCreatedModal) closeModal(modelCreatedModal);
      if (successModal) closeModal(successModal);
      openQuickVariant();
      return;
    }
    const closeQuick = event.target.closest('[data-quick-variant-close]');
    if (closeQuick) { event.preventDefault(); if (!submitting) closeModal(modal); return; }
    const closeCreated = event.target.closest('[data-model-created-close]');
    if (closeCreated) { event.preventDefault(); closeModal(modelCreatedModal); return; }
    const closeSuccess = event.target.closest('[data-quick-success-close]');
    if (closeSuccess) { event.preventDefault(); closeModal(successModal); return; }
    const modeButton = event.target.closest('[data-quick-id-mode]');
    if (modeButton) { event.preventDefault(); setIdentifierMode(modeButton.dataset.quickIdMode); return; }
    const qtyButton = event.target.closest('[data-qty-step]');
    if (qtyButton) {
      event.preventDefault();
      quantityInput.value = String(clampQuantity(Number(quantityInput.value || 1) + Number(qtyButton.dataset.qtyStep || 0)));
      renderRows();
    }
  });

  form.addEventListener('submit', submitQuickSetup);
  quantityInput?.addEventListener('input', () => {
    quantityInput.value = String(clampQuantity(quantityInput.value));
    renderRows();
  });
  connectivity?.addEventListener('change', () => {
    if (!isApple && productType === 'tablet' && connectivity.value === 'Wi-Fi' && identifierMode === 'imei') setIdentifierMode('barcode');
  });
  unitList.addEventListener('input', event => {
    const input = event.target.closest('[data-primary-identifier],[data-secondary-identifier]');
    if (input) {
      input.value = identifierMode === 'imei' || input.hasAttribute('data-secondary-identifier') ? input.value.replace(/\s+/g, '') : input.value.trimStart();
      if (input.hasAttribute('data-primary-identifier') && (isApple || identifierMode !== 'imei')) input.value = input.value.toUpperCase();
      updateProgress();
    }
  });
  unitList.addEventListener('change', updateProgress);

  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape' || submitting) return;
    if (!modal.hidden) closeModal(modal);
    else if (modelCreatedModal && !modelCreatedModal.hidden) closeModal(modelCreatedModal);
    else if (successModal && !successModal.hidden) closeModal(successModal);
  });

  // Render the first identifier slot before the shared scanner script loads.
  renderRows();
  if (modelCreatedModal?.dataset.autoOpen === '1') { openModal(modelCreatedModal); clearFlowQuery(['setup']); }
  if (successModal?.dataset.autoOpen === '1') { openModal(successModal); clearFlowQuery(['stock_done','variant','stock_ref']); }
})();
