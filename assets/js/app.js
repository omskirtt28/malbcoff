(() => {
  const qs = (s, r=document) => r.querySelector(s);
  const qsa = (s, r=document) => [...r.querySelectorAll(s)];

  const sidebar = qs('#sidebar');
  const openSidebar = () => { sidebar?.classList.add('open'); document.body.classList.add('nav-open'); };
  const closeSidebar = () => { sidebar?.classList.remove('open'); document.body.classList.remove('nav-open'); };
  qs('[data-sidebar-toggle]')?.addEventListener('click', () => sidebar?.classList.contains('open') ? closeSidebar() : openSidebar());
  qs('[data-sidebar-close]')?.addEventListener('click', closeSidebar);
  qsa('.sidebar .nav-link').forEach(link => link.addEventListener('click', () => {
    if (window.matchMedia('(max-width: 1100px)').matches) closeSidebar();
  }));

  const typeInputs = qsa('input[name="product_type"]');
  function syncProductType() {
    const value = qs('input[name="product_type"]:checked')?.value || 'phone';
    qsa('.type-option').forEach(el => el.classList.toggle('active', el.querySelector('input')?.checked));
    qsa('.phone-field').forEach(el => el.classList.toggle('hidden', value === 'accessory'));
    qsa('.preloved-field').forEach(el => el.classList.toggle('hidden', value !== 'preloved' && el.classList.contains('preloved-field') && !el.classList.contains('phone-field')));
    qsa('.accessory-field').forEach(el => el.classList.toggle('hidden', value !== 'accessory'));
  }
  typeInputs.forEach(input => input.addEventListener('change', syncProductType));
  if (typeInputs.length) syncProductType();

  const brandSelect = qs('#brandSelect');
  const modelSelect = qs('#modelSelect');
  if (brandSelect && modelSelect) {
    const options = [...modelSelect.options].map(o => ({html:o.outerHTML, brand:o.dataset.brand || ''}));
    brandSelect.addEventListener('change', () => {
      const brand = brandSelect.value;
      modelSelect.innerHTML = options.filter((o,i) => i===0 || !brand || o.brand===brand).map(o => o.html).join('');
    });
  }

  qsa('[data-global-search]').forEach(input => input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && input.value.trim()) {
      const params = new URLSearchParams({page:'inventory', q:input.value.trim()});
      const current = new URL(window.location.href);
      const branch = current.searchParams.get('branch');
      if (branch) params.set('branch', branch);
      window.location = 'index.php?' + params.toString();
    }
  }));

  const modal = qs('#unitModal');
  async function openUnits(button) {
    if (!modal) return;
    modal.hidden = false;
    document.body.classList.add('modal-open');
    qs('[data-modal-title]', modal).textContent = button.dataset.product || 'Product Units';
    const body = qs('[data-modal-body]', modal);
    body.innerHTML = '<div class="loading-state">Loading units…</div>';
    try {
      const response = await fetch('actions/product_units.php?product_id=' + encodeURIComponent(button.dataset.productId), {headers:{'Accept':'application/json'}});
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Unable to load units');
      if (!data.rows?.length) {
        body.innerHTML = '<div class="empty-state small"><strong>No available units</strong><span>There are no available units for this product right now.</span></div>';
        return;
      }
      body.innerHTML = '<div class="unit-list">' + data.rows.map(row => `
        <div class="unit-row"><div><strong>${escapeHtml(row.identifier_type || 'Device ID')}: ${escapeHtml(row.identifier || row.serial_no || row.imei || '—')}</strong>${row.imei2 ? `<small>IMEI 2: ${escapeHtml(row.imei2)}</small>` : ''}<span>${escapeHtml(row.branch_name || '')}${row.condition_type==='preloved' ? ' • Pre-Loved' : ''}${row.condition_grade ? ' • '+escapeHtml(row.condition_grade) : ''}</span></div><div><span class="status-pill ${row.status==='available'?'available':'low'}">${escapeHtml(capitalize(row.status))}</span>${row.battery_health ? `<small>${row.battery_health}% battery</small>` : ''}</div></div>
      `).join('') + '</div>';
    } catch (err) {
      body.innerHTML = '<div class="alert alert-error">' + escapeHtml(err.message) + '</div>';
    }
  }
  qsa('[data-unit-modal]').forEach(btn => btn.addEventListener('click', () => openUnits(btn)));
  qsa('[data-modal-close]').forEach(btn => btn.addEventListener('click', () => { if(modal){ modal.hidden=true; document.body.classList.remove('modal-open'); }}));

  // Responsive navigation sheet used on tablets and phones.
  const mobileMore = qs('[data-mobile-more]');
  const openMobileMore = () => {
    if (!mobileMore) return;
    mobileMore.hidden = false;
    requestAnimationFrame(() => mobileMore.classList.add('open'));
    document.body.classList.add('mobile-sheet-open');
  };
  const closeMobileMore = () => {
    if (!mobileMore) return;
    mobileMore.classList.remove('open');
    document.body.classList.remove('mobile-sheet-open');
    window.setTimeout(() => { if (!mobileMore.classList.contains('open')) mobileMore.hidden = true; }, 180);
  };
  qs('[data-mobile-more-open]')?.addEventListener('click', openMobileMore);
  qsa('[data-mobile-more-close]').forEach(button => button.addEventListener('click', closeMobileMore));

  // Convert wide data tables into readable labelled cards on phones without touching backend markup.
  qsa('table.data-table').forEach(table => {
    const headers = qsa('thead th', table).map(th => th.textContent.trim());
    if (!headers.length) return;
    table.classList.add('is-responsive-table');
    qsa('tbody tr', table).forEach(row => {
      qsa(':scope > td', row).forEach((cell, index) => {
        if (!cell.hasAttribute('colspan') && !cell.dataset.label) cell.dataset.label = headers[index] || '';
      });
    });
  });

  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    closeSidebar();
    closeMobileMore();
  });

  function escapeHtml(v='') { return String(v).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c])); }
  function capitalize(v='') { return String(v).replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase()); }
})();

/* P1-007 Product Master management */
(() => {
  const modalMap = {
    brand: document.getElementById('masterBrandModal'),
    model: document.getElementById('masterModelModal'),
    category: document.getElementById('masterCategoryModal'),
    configuration: document.getElementById('masterConfigurationModal')
  };

  const capitalize = (value='') => String(value).charAt(0).toUpperCase() + String(value).slice(1);

  function openModal(button) {
    const entity = button.dataset.masterOpen;
    const modal = modalMap[entity];
    if (!modal) return;

    const form = modal.querySelector('form');
    if (!form) return;
    const mode = button.dataset.mode || 'add';
    const title = modal.querySelector('[data-master-title]');
    const actionField = form.querySelector('[data-action-field]');
    const idField = form.querySelector('[data-id-field]');
    const nameField = form.querySelector('[data-name-field]');

    form.reset();
    form.querySelectorAll('[data-master-mirror]').forEach(el => el.remove());

    if (title) title.textContent = (mode === 'edit' ? 'Edit ' : 'Add ') + capitalize(entity);
    if (actionField) actionField.value = mode === 'edit' ? 'edit' : 'add';
    if (idField) idField.value = mode === 'edit' ? (button.dataset.id || '') : '';
    if (nameField) nameField.value = mode === 'edit' ? (button.dataset.name || '') : '';

    if (entity === 'configuration') {
      const sellingField = form.querySelector('[data-selling-field]');
      const costField = form.querySelector('[data-cost-field]');
      const label = modal.querySelector('[data-config-modal-label]');
      const ramField = form.querySelector('[data-variant-ram-field]');
      const storageField = form.querySelector('[data-variant-storage-field]');
      const colorField = form.querySelector('[data-variant-color-field]');
      const connectivityField = form.querySelector('[data-variant-connectivity-field]');
      const specNote = form.querySelector('[data-variant-spec-note]');
      const specsLocked = button.dataset.specsLocked === '1';

      const setSelectValue = (field, value='') => {
        if (!field) return;
        if (value && ![...field.options].some(option => option.value === value)) {
          const option = document.createElement('option');
          option.value = value;
          option.textContent = value;
          field.appendChild(option);
        }
        field.value = value || '';
      };

      if (title) title.textContent = 'Edit Variant';
      if (sellingField) sellingField.value = button.dataset.sellingPrice || '0';
      if (costField) costField.value = button.dataset.costPrice || '0';
      setSelectValue(ramField, button.dataset.ram || '');
      setSelectValue(storageField, button.dataset.storage || '');
      if (colorField) colorField.value = button.dataset.color || '';
      setSelectValue(connectivityField, button.dataset.connectivity || '');

      [ramField, storageField, colorField, connectivityField].forEach(field => {
        if (!field) return;
        field.disabled = specsLocked;
        field.closest('.field')?.classList.toggle('field-locked', specsLocked);
      });
      if (specNote) {
        specNote.classList.toggle('locked', specsLocked);
        specNote.textContent = specsLocked
          ? 'Variant specs are locked while active or sold units still use this variant. Remove or complete those units before changing the specs.'
          : 'RAM, storage, color and connectivity can be corrected because no active or sold units are attached to this variant.';
      }

      let branchPrices = {};
      try { branchPrices = JSON.parse(button.dataset.branchPrices || '{}'); } catch {}
      form.querySelectorAll('[data-branch-price]').forEach(input => {
        const branchId = input.dataset.branchPrice || '';
        input.value = branchPrices[branchId] ?? button.dataset.sellingPrice ?? '0';
      });
      if (label) label.textContent = button.dataset.configLabel ? `${button.dataset.configLabel}` : 'Update this variant.';
      modal.dataset.productId = button.dataset.id || '';
      modal.dataset.variantLabel = button.dataset.configLabel || '';
      modal.querySelectorAll('[data-variant-tab]').forEach((tab, index) => {
        const active = index === 0;
        tab.classList.toggle('active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      modal.querySelectorAll('[data-variant-panel]').forEach(panel => {
        panel.hidden = panel.dataset.variantPanel !== 'pricing';
      });
      const inventoryBody = modal.querySelector('[data-variant-inventory-body]');
      if (inventoryBody) {
        inventoryBody.dataset.loadedProduct = '';
        inventoryBody.innerHTML = '<div class="loading-state">Open Inventory to load current stock.</div>';
      }
    }

    if (entity === 'model') {
      const brandField = form.querySelector('[data-brand-field]');
      const typeField = form.querySelector('[data-type-field]');
      const note = form.querySelector('[data-model-edit-note]');
      const preferredBrand = button.dataset.brandId || '';

      if (brandField) brandField.value = mode === 'edit' ? preferredBrand : preferredBrand;
      if (typeField) typeField.value = mode === 'edit' ? (button.dataset.deviceType || 'phone') : 'phone';

      const used = Number(button.dataset.used || 0);
      if (brandField) brandField.disabled = mode === 'edit' && used > 0;
      if (typeField) typeField.disabled = mode === 'edit' && used > 0;

      if (mode === 'edit' && used > 0) {
        [brandField, typeField].forEach(field => {
          if (!field) return;
          const hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = field.name;
          hidden.value = field.value;
          hidden.dataset.masterMirror = '1';
          form.appendChild(hidden);
        });
        if (note) note.textContent = 'This model is already used by inventory. You can rename it, but Brand and Device Type are locked.';
      } else if (note) {
        note.textContent = 'Choose whether the model is a Phone or Tablet.';
      }
    }

    modal.hidden = false;
    document.body.classList.add('modal-open');
    requestAnimationFrame(() => nameField?.focus());
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove('modal-open');
  }

  document.addEventListener('click', event => {
    const openButton = event.target.closest('[data-master-open]');
    if (openButton) {
      event.preventDefault();
      openModal(openButton);
      return;
    }

    const closeButton = event.target.closest('[data-master-close]');
    if (closeButton) {
      event.preventDefault();
      closeModal(closeButton.closest('.master-modal'));
    }
  });

  document.addEventListener('submit', event => {
    const form = event.target.closest('form[data-confirm]');
    if (!form) return;
    const message = form.dataset.confirm || 'Continue?';
    if (!window.confirm(message)) event.preventDefault();
  });

  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const open = document.querySelector('.master-modal:not([hidden])');
    if (open) closeModal(open);
  });
})();


/* P2-007 Owner variant inventory management */
(() => {
  const modal = document.getElementById('masterConfigurationModal');
  if (!modal) return;

  const body = modal.querySelector('[data-variant-inventory-body]');
  const esc = (value='') => String(value).replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  let inventoryData = null;
  let activeBranchId = 0;

  function csrfToken() {
    return modal.querySelector('input[name="_csrf"]')?.value || '';
  }

  function setTab(name) {
    modal.querySelectorAll('[data-variant-tab]').forEach(tab => {
      const active = tab.dataset.variantTab === name;
      tab.classList.toggle('active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    modal.querySelectorAll('[data-variant-panel]').forEach(panel => {
      panel.hidden = panel.dataset.variantPanel !== name;
    });
    if (name === 'inventory') loadInventory();
  }

  async function loadInventory(force=false) {
    if (!body) return;
    const productId = Number(modal.dataset.productId || 0);
    if (!productId) return;
    if (!force && body.dataset.loadedProduct === String(productId) && inventoryData) return;

    body.innerHTML = '<div class="loading-state">Loading inventory…</div>';
    try {
      const response = await fetch('actions/variant_inventory.php?product_id=' + encodeURIComponent(productId), {
        headers: {'Accept':'application/json'}
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Unable to load inventory.');
      inventoryData = data;
      body.dataset.loadedProduct = String(productId);
      renderOverview();
    } catch (error) {
      body.innerHTML = '<div class="variant-inventory-message error">' + esc(error.message) + '</div>';
    }
  }

  function renderOverview(message='') {
    if (!body || !inventoryData) return;
    const branches = inventoryData.branches || [];
    body.innerHTML = `
      <div class="variant-inventory-content">
        ${message ? `<div class="variant-inventory-message success">${esc(message)}</div>` : ''}
        <div class="variant-inventory-summary">
          <div>
            <span>Available Stock</span>
            <strong>${Number(inventoryData.total_available || 0).toLocaleString()}</strong>
            <small>Across all branches</small>
          </div>
          <div class="variant-inventory-help">
            <strong>Adjust exact units, not just the quantity.</strong>
            <span>Sold units are protected and are never shown here.</span>
          </div>
        </div>
        <div class="variant-branch-stock-list">
          ${branches.map(branch => `
            <div class="variant-branch-stock-row">
              <div class="variant-branch-stock-name">
                <span class="variant-branch-icon">${branch.code ? esc(branch.code) : 'BR'}</span>
                <div><strong>${esc(branch.name)}</strong><small>${Number(branch.available_count || 0).toLocaleString()} available</small></div>
              </div>
              <button type="button" class="btn btn-outline btn-sm" data-manage-branch="${Number(branch.id)}" ${Number(branch.available_count || 0) < 1 ? 'disabled' : ''}>Manage Stock</button>
            </div>
          `).join('')}
        </div>
      </div>`;
  }

  function renderBranch(branchId) {
    if (!body || !inventoryData) return;
    const branch = (inventoryData.branches || []).find(item => Number(item.id) === Number(branchId));
    if (!branch) return;
    activeBranchId = Number(branch.id);
    const units = branch.units || [];
    body.innerHTML = `
      <div class="variant-inventory-content">
        <button type="button" class="variant-inventory-back" data-inventory-back>← Back to branches</button>
        <div class="variant-adjust-heading">
          <div><span class="section-kicker">${esc(branch.name)}</span><h3>Manage Available Stock</h3><p>Select the exact Serial Number / IMEI you want to remove from available stock.</p></div>
          <span class="count-badge">${Number(branch.available_count || 0)}</span>
        </div>
        ${units.length ? `
          <div class="variant-unit-toolbar">
            <label class="variant-select-all"><input type="checkbox" data-select-all-units> Select all available units</label>
            <strong data-selected-count>0 selected</strong>
          </div>
          <div class="variant-adjust-units">
            ${units.map(unit => `
              <label class="variant-adjust-unit">
                <input type="checkbox" value="${Number(unit.id)}" data-unit-select>
                <span class="variant-adjust-unit-index">${esc(unit.identifier_type === 'Serial Number' ? 'SN' : 'IMEI')}</span>
                <span class="variant-adjust-unit-copy"><strong>${esc(unit.identifier)}</strong>${unit.secondary_identifier ? `<small>IMEI 2: ${esc(unit.secondary_identifier)}</small>` : '<small>Available</small>'}</span>
              </label>
            `).join('')}
          </div>
          <div class="variant-adjust-reason">
            <label class="field"><span>Reason <b>*</b></span><select data-adjust-reason>
              <option value="">Select reason</option>
              <option value="stock_correction">Stock Correction</option>
              <option value="damaged">Damaged</option>
              <option value="missing">Missing</option>
              <option value="return_supplier">Return to Supplier</option>
              <option value="other">Other</option>
            </select></label>
            <label class="field"><span>Note <small>(optional)</small></span><input type="text" maxlength="180" data-adjust-note placeholder="Short note or reference"></label>
          </div>
          <div class="variant-adjust-footer">
            <div><strong>This does not delete the units.</strong><span>An Adjustment OUT record will be kept in Stock Movement.</span></div>
            <button type="button" class="btn btn-danger" data-adjust-submit disabled>Remove Selected from Available Stock</button>
          </div>
        ` : '<div class="empty-state small"><strong>No available units</strong><span>This branch has no units that can be adjusted.</span></div>'}
      </div>`;
    syncSelection();
  }

  function selectedUnitIds() {
    return [...body.querySelectorAll('[data-unit-select]:checked')].map(input => Number(input.value)).filter(Boolean);
  }

  function syncSelection() {
    if (!body) return;
    const count = selectedUnitIds().length;
    const counter = body.querySelector('[data-selected-count]');
    const submit = body.querySelector('[data-adjust-submit]');
    if (counter) counter.textContent = `${count} selected`;
    if (submit) submit.disabled = count < 1;
    const all = body.querySelectorAll('[data-unit-select]');
    const selectAll = body.querySelector('[data-select-all-units]');
    if (selectAll) {
      selectAll.checked = all.length > 0 && count === all.length;
      selectAll.indeterminate = count > 0 && count < all.length;
    }
  }

  async function submitAdjustment(button) {
    const productId = Number(modal.dataset.productId || 0);
    const unitIds = selectedUnitIds();
    const reason = body.querySelector('[data-adjust-reason]')?.value || '';
    const notes = body.querySelector('[data-adjust-note]')?.value.trim() || '';
    if (!unitIds.length) return;
    if (!reason) {
      body.querySelector('[data-adjust-reason]')?.focus();
      return;
    }
    if (reason === 'other' && !notes) {
      body.querySelector('[data-adjust-note]')?.focus();
      return;
    }
    const confirmed = window.confirm(`Remove ${unitIds.length} selected unit${unitIds.length === 1 ? '' : 's'} from available stock? This action will be recorded.`);
    if (!confirmed) return;

    button.disabled = true;
    button.textContent = 'Saving…';
    try {
      const response = await fetch('actions/adjust_inventory.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json','Accept':'application/json'},
        body: JSON.stringify({
          _csrf: csrfToken(),
          product_id: productId,
          branch_id: activeBranchId,
          unit_ids: unitIds,
          reason,
          notes
        })
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Unable to save adjustment.');

      const stock = document.querySelector(`[data-variant-stock="${productId}"]`);
      if (stock) stock.textContent = Number(data.total_available || 0).toLocaleString();
      body.dataset.loadedProduct = '';
      inventoryData = null;
      await loadInventory(true);
      renderOverview(`${data.message} Reference: ${data.reference_no}`);
    } catch (error) {
      const existing = body.querySelector('.variant-inventory-message.error');
      if (existing) existing.remove();
      body.insertAdjacentHTML('afterbegin', '<div class="variant-inventory-message error">' + esc(error.message) + '</div>');
      button.disabled = false;
      button.textContent = 'Remove Selected from Available Stock';
    }
  }

  document.addEventListener('click', event => {
    const tab = event.target.closest('[data-variant-tab]');
    if (tab && modal.contains(tab)) {
      event.preventDefault();
      setTab(tab.dataset.variantTab || 'pricing');
      return;
    }
    const manage = event.target.closest('[data-manage-branch]');
    if (manage && modal.contains(manage)) {
      event.preventDefault();
      renderBranch(Number(manage.dataset.manageBranch || 0));
      return;
    }
    const back = event.target.closest('[data-inventory-back]');
    if (back && modal.contains(back)) {
      event.preventDefault();
      renderOverview();
      return;
    }
    const submit = event.target.closest('[data-adjust-submit]');
    if (submit && modal.contains(submit)) {
      event.preventDefault();
      submitAdjustment(submit);
    }
  });

  document.addEventListener('change', event => {
    if (!modal.contains(event.target)) return;
    if (event.target.matches('[data-unit-select]')) syncSelection();
    if (event.target.matches('[data-select-all-units]')) {
      body.querySelectorAll('[data-unit-select]').forEach(input => { input.checked = event.target.checked; });
      syncSelection();
    }
  });
})();


/* P2-012 Auto-uppercase operational inventory/product fields */
(() => {
  const selector = '[data-uppercase]';
  const toUpper = (input) => {
    if (!input || typeof input.value !== 'string') return;
    const start = input.selectionStart;
    const end = input.selectionEnd;
    const upper = input.value.toLocaleUpperCase('en-US');
    if (upper === input.value) return;
    input.value = upper;
    try {
      if (start !== null && end !== null) input.setSelectionRange(start, end);
    } catch (_) {}
  };
  document.addEventListener('input', (event) => {
    const target = event.target;
    if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
      if (target.matches(selector)) toUpper(target);
    }
  });
  document.addEventListener('paste', (event) => {
    const target = event.target;
    if ((target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) && target.matches(selector)) {
      setTimeout(() => toUpper(target), 0);
    }
  });
  document.querySelectorAll(selector).forEach(toUpper);
})();
/* P2-026 — Branch inventory forwarding (approved modal UI) */
(() => {
  /* Dedicated inventory-forward.js owns this feature on Inventory pages. */
  if (window.__malbcoffInventoryForwardDedicated) return;
  const modal = document.getElementById('forwardInventoryModal');
  const form = document.getElementById('forwardInventoryForm');
  if (!modal || !form) return;

  const q = (selector, root = modal) => root.querySelector(selector);
  const qa = (selector, root = modal) => [...root.querySelectorAll(selector)];
  let current = null;

  const escapeHtml = (value = '') => String(value).replace(/[&<>'"]/g, char => ({
    '&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'
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
    qa('.forward-field input, .forward-field select').forEach(control => control.classList.remove('input-error'));
  };

  const showGeneralError = (message = '') => {
    const box = q('[data-forward-error]');
    if (!box) return;
    box.textContent = message;
    box.hidden = !message;
  };

  const selectedUnits = () => qa('input[name="unit_ids[]"]:checked');

  const syncSelectedQuantity = () => {
    if (!current || current.productType === 'accessory') return;
    const qty = q('input[name="quantity"]');
    if (qty) qty.value = String(selectedUnits().length);
    const selectAll = q('[data-forward-select-all]');
    const checks = qa('input[name="unit_ids[]"]');
    if (selectAll) {
      const allChecked = checks.length > 0 && checks.every(input => input.checked);
      selectAll.textContent = allChecked ? 'Clear All' : 'Select All';
    }
    setInlineError('[data-forward-units-error]');
  };

  const close = () => {
    modal.hidden = true;
    document.body.classList.remove('modal-open');
    form.reset();
    const units = q('[data-forward-units]');
    if (units) units.innerHTML = '';
    clearErrors();
    current = null;
  };

  const formatCondition = row => row.condition_type === 'preloved' ? 'Pre-Loved' : 'Brand New';

  const unitLine = (row, index) => {
    const identifierType = escapeHtml(row.identifier_type || 'Device ID');
    const identifier = escapeHtml(row.identifier || row.serial_no || row.imei || '—');
    const imei2 = row.imei2 ? `<span class="forward-unit-segment"><b>IMEI 2:</b> ${escapeHtml(row.imei2)}</span>` : '';
    const brand = current.brand ? `<span class="forward-unit-segment"><b>Brand:</b> ${escapeHtml(current.brand)}</span>` : '';
    const model = current.model ? `<span class="forward-unit-segment"><b>Model:</b> ${escapeHtml(current.model)}</span>` : '';
    const specs = current.specs && current.specs !== '—' ? `<span class="forward-unit-segment"><b>Unit:</b> ${escapeHtml(current.specs)}</span>` : '';
    return `
      <label class="forward-unit-option" title="Unit ${index + 1}">
        <input type="checkbox" name="unit_ids[]" value="${Number(row.unit_id || 0)}">
        <span class="forward-unit-check" aria-hidden="true"></span>
        <span class="forward-unit-line">
          <span class="forward-unit-segment forward-unit-identifier"><b>${identifierType}:</b> ${identifier}</span>
          ${imei2}${brand}${model}${specs}
          <span class="forward-unit-condition">${escapeHtml(formatCondition(row))}</span>
        </span>
      </label>`;
  };

  async function loadUnits() {
    const list = q('[data-forward-units]');
    list.innerHTML = '<div class="loading-state">Loading available units…</div>';
    try {
      const url = 'actions/product_units.php?product_id=' + encodeURIComponent(current.productId) + '&branch_id=' + encodeURIComponent(current.sourceBranchId);
      const response = await fetch(url, { headers: { 'Accept':'application/json' } });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Unable to load units.');
      const rows = data.rows || [];
      if (!rows.length) {
        list.innerHTML = '<div class="empty-state small"><strong>No available units</strong><span>Refresh the inventory and try again.</span></div>';
        return;
      }
      list.innerHTML = rows.map(unitLine).join('');
      qa('input[name="unit_ids[]"]').forEach(input => input.addEventListener('change', syncSelectedQuantity));
      if (rows.length === 1) {
        const only = q('input[name="unit_ids[]"]');
        if (only) only.checked = true;
      }
      syncSelectedQuantity();
    } catch (error) {
      list.innerHTML = '<div class="alert alert-error">' + escapeHtml(error.message) + '</div>';
    }
  }

  const openForwardModal = async (button) => {
    if (!button || button.disabled) return;

    current = {
      productId: Number(button.dataset.productId || 0),
      productType: button.dataset.productType || '',
      sourceBranchId: Number(button.dataset.sourceBranchId || 0),
      sourceBranch: button.dataset.sourceBranch || '',
      available: Number(button.dataset.available || 0),
      product: button.dataset.product || 'Product',
      productName: button.dataset.productName || button.dataset.product || 'Product',
      brand: button.dataset.brand || '',
      model: button.dataset.model || '',
      specs: button.dataset.specs || '—'
    };

    form.reset();
    clearErrors();

    const productIdField = q('input[name="product_id"]');
    const productName = q('[data-forward-product-name]');
    const productSpecs = q('[data-forward-product-specs]');
    const source = q('[data-forward-source]');
    const available = q('[data-forward-available]');
    const qty = q('input[name="quantity"]');
    const unitsWrap = q('[data-forward-units-wrap]');

    if (!productIdField || !productName || !productSpecs || !source || !available || !qty || !unitsWrap) {
      console.error('Forward Inventory modal is missing a required UI element.');
      return;
    }

    productIdField.value = String(current.productId);
    productName.textContent = current.productName;
    productSpecs.textContent = [current.brand, current.specs].filter(Boolean).join(' • ') || current.product;
    source.textContent = current.sourceBranch;
    available.textContent = current.available + ' available in ' + current.sourceBranch;

    qty.max = String(Math.max(1, current.available));
    qty.readOnly = current.productType !== 'accessory';
    qty.value = current.productType === 'accessory' ? '1' : '0';
    qty.classList.toggle('forward-quantity-readonly', current.productType !== 'accessory');
    unitsWrap.hidden = current.productType === 'accessory';

    modal.hidden = false;
    modal.removeAttribute('aria-hidden');
    document.body.classList.add('modal-open');

    requestAnimationFrame(() => q('select[name="destination_branch_id"]')?.focus());
    if (current.productType !== 'accessory') await loadUnits();
  };

  /* Delegated handler keeps Forward working after responsive/table DOM changes. */
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-forward-inventory]');
    if (!button) return;
    event.preventDefault();
    event.stopPropagation();
    openForwardModal(button).catch(error => {
      console.error('Unable to open Forward Inventory modal:', error);
      showGeneralError('Unable to open Forward Inventory. Please refresh and try again.');
    });
  });

  qa('[data-forward-close]').forEach(button => button.addEventListener('click', close));

  q('[data-forward-select-all]')?.addEventListener('click', () => {
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
    const destination = destinationControl.value;
    const unitIds = selectedUnits().map(input => Number(input.value));
    const quantity = current.productType === 'accessory' ? Number(quantityControl.value || 0) : unitIds.length;
    let invalid = false;

    if (!destination) {
      setInlineError('[data-forward-destination-error]', 'Select the destination branch.');
      destinationControl.classList.add('input-error');
      invalid = true;
    }

    if (current.productType === 'accessory') {
      if (quantity < 1 || quantity > current.available) {
        setInlineError('[data-forward-quantity-error]', 'Enter a quantity from 1 to ' + current.available + '.');
        quantityControl.classList.add('input-error');
        invalid = true;
      }
    } else if (!unitIds.length) {
      setInlineError('[data-forward-units-error]', 'Select at least one available unit to forward.');
      invalid = true;
    }

    if (invalid) return;

    const payload = {
      _csrf: q('input[name="_csrf"]').value,
      product_id: current.productId,
      destination_branch_id: Number(destination),
      notes: q('textarea[name="notes"]').value.trim(),
      quantity,
      unit_ids: unitIds
    };

    const submit = q('[data-forward-submit]');
    const originalText = submit.textContent;
    submit.disabled = true;
    submit.textContent = 'Forwarding…';

    try {
      const response = await fetch('actions/forward_inventory.php', {
        method:'POST',
        headers:{ 'Content-Type':'application/json', 'Accept':'application/json' },
        body:JSON.stringify(payload)
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Unable to forward inventory.');
      submit.textContent = 'Forwarded';
      setTimeout(() => window.location.reload(), 450);
    } catch (error) {
      showGeneralError(error.message);
      submit.disabled = false;
      submit.textContent = originalText;
    }
  });
})();
