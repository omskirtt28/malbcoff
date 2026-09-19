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
