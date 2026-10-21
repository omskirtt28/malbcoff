(() => {
  const card = document.getElementById('deleted-inventory-units');
  const dialog = card?.querySelector('[data-unit-purge-dialog]');
  if (!dialog) return;
  const form = dialog.querySelector('form');
  const all = card.querySelector('[data-unit-select-all]');
  const boxes = [...card.querySelectorAll('[data-unit-select]')];
  const bulkButton = card.querySelector('[data-purge-selected]');
  const confirm = form.querySelector('[data-purge-confirm]');
  const typed = form.querySelector('[data-purge-type]');
  const cancel = form.querySelector('[data-purge-cancel]');
  const deleteAllButton = card.querySelector('[data-purge-all-no-sales]');
  const previewStatus = card.querySelector('[data-unit-preview-status]');
  let pending = [], bulk = false, busy = false, allPreview = null;
  const sync = () => {
    const n = boxes.filter(b => b.checked).length;
    bulkButton.disabled = !n;
    all.disabled = !boxes.length;
    all.checked = !!n && n === boxes.length;
    all.indeterminate = !!n && n < boxes.length;
    card.querySelector('[data-unit-selected-count]').textContent = `${n} selected`;
  };
  const open = (ids, isBulk, preview = null) => {
    pending = ids; bulk = isBulk; allPreview = preview;
    delete form.dataset.clientKey;
    form.querySelector('[data-purge-status]').replaceChildren();
    form.querySelector('[data-purge-count]').textContent = preview
      ? `${preview.count} unit(s) with no sales or voids will be deleted across all branches and pages. Page filters do not limit this action.`
      : `You are about to clean ${ids.length} unit(s).`;
    const review = form.querySelector('[data-purge-review]');
    review.replaceChildren();
    ids.forEach(id => { const li = document.createElement('li'); li.textContent = boxes.find(b => Number(b.value) === id)?.dataset.summary || card.querySelector(`[data-purge-unit="${id}"]`)?.dataset.summary || `Unit #${id}`; review.append(li); });
    typed.value = ''; typed.required = bulk;
    form.querySelector('[data-purge-type-label]').hidden = !bulk;
    confirm.disabled = bulk; cancel.disabled = false;
    dialog.showModal();
    (bulk ? typed : cancel).focus();
  };
  boxes.forEach(b => b.addEventListener('change', sync));
  all.addEventListener('change', () => { boxes.forEach(b => { b.checked = all.checked; }); sync(); });
  bulkButton.addEventListener('click', () => open(boxes.filter(b => b.checked).map(b => Number(b.value)), true));
  deleteAllButton.addEventListener('click', async () => {
    deleteAllButton.disabled = true;
    previewStatus.textContent = 'Counting eligible units...';
    try {
      const response = await fetch('actions/system_admin_deleted_records.php', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json'}, body: JSON.stringify({action: 'preview_no_sales', _csrf: form.dataset.csrf})});
      const preview = await response.json();
      if (!response.ok) throw new Error(preview.error || 'Unable to preview units. Try again.');
      previewStatus.textContent = `${preview.count} unit(s) with no sales or voids across all branches.`;
      if (preview.count > 0) open([], true, preview);
    } catch (error) { previewStatus.textContent = error.message || 'Unable to preview units. Try again.'; }
    finally { deleteAllButton.disabled = false; }
  });
  card.querySelectorAll('[data-purge-unit]').forEach(b => b.addEventListener('click', () => open([Number(b.dataset.purgeUnit)], false)));
  typed.addEventListener('input', () => { confirm.disabled = bulk && typed.value !== 'DELETE'; });
  cancel.addEventListener('click', () => { if (!busy) dialog.close(); });
  dialog.addEventListener('cancel', e => { if (busy) e.preventDefault(); });
  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (busy || (!pending.length && !allPreview) || (bulk && typed.value !== 'DELETE')) return;
    busy = true; cancel.disabled = true; typed.readOnly = true;
    const payload = {action: allPreview ? 'purge_all_no_sales' : 'purge_units', unit_ids: [...pending], bulk, confirmed: true, confirmation: typed.value,
      ...(allPreview ? {preview_token: allPreview.preview_token, preview_count: allPreview.count} : {})};
    try {
      await SafeSubmit.run({form, button: confirm, host: form.querySelector('[data-purge-status]'), url: 'actions/system_admin_deleted_records.php',
        getBody: () => ({...payload, _csrf: form.dataset.csrf}), onCsrf: token => { form.dataset.csrf = token; },
        successDetail: d => `${d.deleted} removed, ${d.archived} archived (history kept), ${d.already_processed} already processed. Identifiers are available for receiving.`,
        onSuccess: () => { confirm.disabled = true; }, onDismiss: () => location.reload()});
    } finally { busy = false; cancel.disabled = false; typed.readOnly = false; }
  });
  sync();
})();
