(() => {
  const qs = (s, r=document) => r.querySelector(s);
  const qsa = (s, r=document) => [...r.querySelectorAll(s)];

  const sidebar = qs('#sidebar');
  qs('[data-sidebar-toggle]')?.addEventListener('click', () => sidebar?.classList.toggle('open'));

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
      window.location = 'index.php?page=inventory&q=' + encodeURIComponent(input.value.trim());
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
        body.innerHTML = '<div class="empty-state small"><strong>No tracked units</strong><span>This product may be quantity-based or has no unit records yet.</span></div>';
        return;
      }
      body.innerHTML = '<div class="unit-list">' + data.rows.map(row => `
        <div class="unit-row"><div><strong>${escapeHtml(row.identifier_type || 'Device ID')}: ${escapeHtml(row.identifier || row.serial_no || row.imei || '—')}</strong><span>${escapeHtml(row.branch_name || '')}${row.condition_type==='preloved' ? ' • Pre-Loved' : ''}${row.condition_grade ? ' • '+escapeHtml(row.condition_grade) : ''}</span></div><div><span class="status-pill ${row.status==='available'?'available':'low'}">${escapeHtml(capitalize(row.status))}</span>${row.battery_health ? `<small>${row.battery_health}% battery</small>` : ''}</div></div>
      `).join('') + '</div>';
    } catch (err) {
      body.innerHTML = '<div class="alert alert-error">' + escapeHtml(err.message) + '</div>';
    }
  }
  qsa('[data-unit-modal]').forEach(btn => btn.addEventListener('click', () => openUnits(btn)));
  qsa('[data-modal-close]').forEach(btn => btn.addEventListener('click', () => { if(modal){ modal.hidden=true; document.body.classList.remove('modal-open'); }}));

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
      if (title) title.textContent = 'Edit Variant';
      if (sellingField) sellingField.value = button.dataset.sellingPrice || '0';
      if (costField) costField.value = button.dataset.costPrice || '0';
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
                <span class="variant-adjust-unit-copy"><strong>${esc(unit.identifier)}</strong><small>Available</small></span>
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
