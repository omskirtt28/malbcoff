/*
 * Malbcoff Inventory Forwarding — dedicated controller
 * Kept separate from app.js so a failure in another page-wide UI module
 * cannot disable the Forward button or Branch Transfer workflow.
 */
(() => {
  'use strict';

  window.__malbcoffInventoryForwardDedicated = true;

  const modal = document.getElementById('forwardInventoryModal');
  const form = document.getElementById('forwardInventoryForm');
  if (!modal || !form) {
    console.warn('Forward Inventory UI not found on this page.');
    return;
  }

  const q = (selector, root = modal) => root.querySelector(selector);
  const qa = (selector, root = modal) => Array.from(root.querySelectorAll(selector));
  let current = null;
  let opening = false;

  const escapeHtml = (value = '') => String(value).replace(/[&<>'"]/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  }[char]));

  const setInlineError = (selector, message = '') => {
    const box = q(selector);
    if (!box) return;
    box.textContent = message;
    box.hidden = !message;
  };

  const clearErrors = () => {
    setInlineError('[data-forward-destination-error]');
    setInlineError('[data-forward-quantity-error]');
    setInlineError('[data-forward-units-error]');
    const generic = q('[data-forward-error]');
    if (generic) {
      generic.textContent = '';
      generic.hidden = true;
    }
    qa('.forward-field input, .forward-field select').forEach(control => {
      control.classList.remove('input-error');
    });
  };

  const showGeneralError = message => {
    const box = q('[data-forward-error]');
    if (!box) {
      window.alert(message || 'Unable to continue.');
      return;
    }
    box.textContent = message || '';
    box.hidden = !message;
  };

  const selectedUnits = () => qa('input[name="unit_ids[]"]:checked');

  const syncSelectedQuantity = () => {
    if (!current || current.productType === 'accessory') return;
    const quantity = q('input[name="quantity"]');
    if (quantity) quantity.value = String(selectedUnits().length);

    const selectAll = q('[data-forward-select-all]');
    const checks = qa('input[name="unit_ids[]"]');
    if (selectAll) {
      const allChecked = checks.length > 0 && checks.every(input => input.checked);
      selectAll.textContent = allChecked ? 'Clear All' : 'Select All';
    }
    setInlineError('[data-forward-units-error]');
  };

  const closeModal = () => {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
    form.reset();
    const unitList = q('[data-forward-units]');
    if (unitList) unitList.innerHTML = '';
    clearErrors();
    current = null;
    opening = false;
  };

  const formatCondition = row => row.condition_type === 'preloved' ? 'Pre-Loved' : 'Brand New';

  const unitLine = row => {
    const identifierType = escapeHtml(row.identifier_type || 'Device ID');
    const identifier = escapeHtml(row.identifier || row.serial_no || row.imei || '—');
    const imei2 = row.imei2 ? `<span class="forward-unit-segment"><b>IMEI 2:</b> ${escapeHtml(row.imei2)}</span>` : '';
    const brand = current?.brand ? `<span class="forward-unit-segment"><b>Brand:</b> ${escapeHtml(current.brand)}</span>` : '';
    const model = current?.model ? `<span class="forward-unit-segment"><b>Model:</b> ${escapeHtml(current.model)}</span>` : '';
    const specs = current?.specs && current.specs !== '—' ? `<span class="forward-unit-segment"><b>Unit:</b> ${escapeHtml(current.specs)}</span>` : '';

    return `
      <label class="forward-unit-option">
        <input type="checkbox" name="unit_ids[]" value="${Number(row.unit_id || 0)}">
        <span class="forward-unit-check" aria-hidden="true"></span>
        <span class="forward-unit-line">
          <span class="forward-unit-segment forward-unit-identifier"><b>${identifierType}:</b> ${identifier}</span>
          ${imei2}${brand}${model}${specs}
          <span class="forward-unit-condition">${escapeHtml(formatCondition(row))}</span>
        </span>
      </label>`;
  };

  const loadUnits = async () => {
    const list = q('[data-forward-units]');
    if (!list || !current) return;

    list.innerHTML = '<div class="loading-state">Loading available units…</div>';
    try {
      const url = `actions/product_units.php?product_id=${encodeURIComponent(current.productId)}&branch_id=${encodeURIComponent(current.sourceBranchId)}`;
      const response = await fetch(url, {
        method: 'GET',
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      });

      let data;
      try {
        data = await response.json();
      } catch (_) {
        throw new Error('The unit list returned an invalid response.');
      }

      if (!response.ok) throw new Error(data.error || 'Unable to load available units.');
      const rows = Array.isArray(data.rows) ? data.rows : [];

      if (!rows.length) {
        list.innerHTML = '<div class="empty-state small"><strong>No available units</strong><span>Refresh the inventory and try again.</span></div>';
        return;
      }

      list.innerHTML = rows.map(unitLine).join('');
      qa('input[name="unit_ids[]"]').forEach(input => {
        input.addEventListener('change', syncSelectedQuantity);
      });

      if (rows.length === 1) {
        const only = q('input[name="unit_ids[]"]');
        if (only) only.checked = true;
      }
      syncSelectedQuantity();
    } catch (error) {
      list.innerHTML = `<div class="alert alert-error">${escapeHtml(error.message || 'Unable to load available units.')}</div>`;
    }
  };

  const readButtonData = button => ({
    productId: Number(button.dataset.productId || 0),
    productType: String(button.dataset.productType || ''),
    sourceBranchId: Number(button.dataset.sourceBranchId || 0),
    sourceBranch: String(button.dataset.sourceBranch || ''),
    available: Number(button.dataset.available || 0),
    product: String(button.dataset.product || 'Product'),
    productName: String(button.dataset.productName || button.dataset.product || 'Product'),
    brand: String(button.dataset.brand || ''),
    model: String(button.dataset.model || ''),
    specs: String(button.dataset.specs || '—')
  });

  const openModal = async button => {
    if (!button || button.disabled || opening) return;
    opening = true;

    try {
      current = readButtonData(button);
      if (!current.productId || !current.sourceBranchId) {
        throw new Error('This inventory row is missing transfer information. Refresh the page and try again.');
      }

      form.reset();
      clearErrors();

      const productIdField = q('input[name="product_id"]');
      const productName = q('[data-forward-product-name], [data-forward-product]');
      const productSpecs = q('[data-forward-product-specs]');
      const source = q('[data-forward-source]');
      const available = q('[data-forward-available]');
      const quantity = q('input[name="quantity"]');
      const unitsWrap = q('[data-forward-units-wrap]');

      if (!productIdField || !productName || !source || !quantity) {
        throw new Error('The Branch Transfer modal is missing a required field. Refresh the page and try again.');
      }

      productIdField.value = String(current.productId);
      productName.textContent = current.productName;
      if (productSpecs) productSpecs.textContent = [current.brand, current.specs].filter(Boolean).join(' • ') || current.product;
      source.textContent = current.sourceBranch;
      if (available) available.textContent = `${current.available} available in ${current.sourceBranch}`;

      quantity.max = String(Math.max(1, current.available));
      quantity.readOnly = current.productType !== 'accessory';
      quantity.value = current.productType === 'accessory' ? '1' : '0';
      quantity.classList.toggle('forward-quantity-readonly', current.productType !== 'accessory');
      if (unitsWrap) unitsWrap.hidden = current.productType === 'accessory';
      const quantityField = q('[data-forward-quantity-field]');
      if (quantityField) quantityField.hidden = false;

      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('modal-open');

      requestAnimationFrame(() => {
        q('select[name="destination_branch_id"]')?.focus();
      });

      if (current.productType !== 'accessory') {
        if (!q('[data-forward-units]')) {
          throw new Error('The unit selection area is missing. Replace pages/inventory.php with the matching patch file.');
        }
        await loadUnits();
      }
    } catch (error) {
      console.error('Unable to open Forward Inventory:', error);
      if (!modal.hidden) showGeneralError(error.message || 'Unable to open Forward Inventory.');
      else window.alert(error.message || 'Unable to open Forward Inventory.');
    } finally {
      opening = false;
    }
  };

  /*
   * Capture phase intentionally runs before page/table click handlers.
   * This prevents another UI script from swallowing the Forward click.
   */
  document.addEventListener('click', event => {
    const button = event.target.closest?.('[data-forward-inventory]');
    if (!button) return;
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
    void openModal(button);
  }, true);

  document.addEventListener('keydown', event => {
    if ((event.key === 'Enter' || event.key === ' ') && event.target.closest?.('[data-forward-inventory]')) {
      event.preventDefault();
      void openModal(event.target.closest('[data-forward-inventory]'));
      return;
    }
    if (event.key === 'Escape' && !modal.hidden) closeModal();
  }, true);

  qa('[data-forward-close]').forEach(button => {
    button.addEventListener('click', event => {
      event.preventDefault();
      closeModal();
    });
  });

  q('[data-forward-select-all]')?.addEventListener('click', event => {
    event.preventDefault();
    const checks = qa('input[name="unit_ids[]"]');
    const allChecked = checks.length > 0 && checks.every(input => input.checked);
    checks.forEach(input => { input.checked = !allChecked; });
    syncSelectedQuantity();
  });

  q('select[name="destination_branch_id"]')?.addEventListener('change', () => {
    setInlineError('[data-forward-destination-error]');
    q('select[name="destination_branch_id"]')?.classList.remove('input-error');
  });

  q('input[name="quantity"]')?.addEventListener('input', () => {
    setInlineError('[data-forward-quantity-error]');
    q('input[name="quantity"]')?.classList.remove('input-error');
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!current) return;

    clearErrors();
    const destinationControl = q('select[name="destination_branch_id"]');
    const quantityControl = q('input[name="quantity"]');
    const csrf = q('input[name="_csrf"]');
    const notes = q('textarea[name="notes"], input[name="notes"]');
    const submit = q('[data-forward-submit]');

    if (!destinationControl || !quantityControl || !csrf || !submit) {
      showGeneralError('The transfer form is incomplete. Refresh the page and try again.');
      return;
    }

    const destination = destinationControl.value;
    const unitIds = selectedUnits().map(input => Number(input.value)).filter(Boolean);
    const quantity = current.productType === 'accessory' ? Number(quantityControl.value || 0) : unitIds.length;
    let invalid = false;

    if (!destination) {
      setInlineError('[data-forward-destination-error]', 'Select the destination branch.');
      destinationControl.classList.add('input-error');
      invalid = true;
    }

    if (current.productType === 'accessory') {
      if (quantity < 1 || quantity > current.available) {
        setInlineError('[data-forward-quantity-error]', `Enter a quantity from 1 to ${current.available}.`);
        quantityControl.classList.add('input-error');
        invalid = true;
      }
    } else if (!unitIds.length) {
      setInlineError('[data-forward-units-error]', 'Select at least one available unit to forward.');
      invalid = true;
    }

    if (invalid) return;

    const payload = {
      _csrf: csrf.value,
      product_id: current.productId,
      destination_branch_id: Number(destination),
      notes: notes ? notes.value.trim() : '',
      quantity,
      unit_ids: unitIds
    };

    const originalText = submit.textContent;
    submit.disabled = true;
    submit.textContent = 'Forwarding…';

    try {
      const response = await fetch('actions/forward_inventory.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      });

      let data;
      try {
        data = await response.json();
      } catch (_) {
        throw new Error('The server returned an invalid transfer response.');
      }

      if (!response.ok) throw new Error(data.error || 'Unable to forward inventory.');
      submit.textContent = 'Forwarded';
      window.setTimeout(() => window.location.reload(), 450);
    } catch (error) {
      showGeneralError(error.message || 'Unable to forward inventory. Please try again.');
      submit.disabled = false;
      submit.textContent = originalText;
    }
  });
})();
