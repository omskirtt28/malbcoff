(() => {
  'use strict';

  const modal = document.getElementById('transferReceiveModal');
  const form = document.getElementById('transferReceiveForm');
  if (!modal || !form) return;

  const q = (selector, root = modal) => root.querySelector(selector);
  const qa = (selector, root = modal) => Array.from(root.querySelectorAll(selector));
  let activeTransfer = null;

  const esc = (value = '') => String(value).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const formatDate = value => {
    if (!value) return '—';
    const d = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString([], {year:'numeric',month:'short',day:'2-digit',hour:'2-digit',minute:'2-digit'});
  };
  const show = (el, visible) => { if (el) el.hidden = !visible; };
  const setText = (selector, value) => { const el = q(selector); if (el) el.textContent = value ?? '—'; };
  const setError = message => { const el = q('[data-transfer-error]'); if (!el) return; el.textContent = message || ''; el.hidden = !message; };
  const setReceiverError = message => { const el = q('[data-transfer-receiver-error]'); if (!el) return; el.textContent = message || ''; el.hidden = !message; q('input[name="receiver_name"]')?.classList.toggle('input-error', !!message); };

  const close = () => {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
    form.reset();
    activeTransfer = null;
    setError('');
    setReceiverError('');
  };

  const renderUnits = units => {
    const wrap = q('[data-transfer-unit-wrap]');
    const list = q('[data-transfer-units]');
    if (!wrap || !list) return;
    if (!Array.isArray(units) || !units.length) {
      wrap.hidden = true;
      list.innerHTML = '';
      return;
    }
    wrap.hidden = false;
    list.innerHTML = units.map((unit, index) => {
      const parts = [];
      if (unit.unit_deleted) {
        parts.push('<span class="status-pill">Unit deleted</span>');
        if (unit.product_label) parts.push(`<span>${esc(unit.product_label)}</span>`);
      }
      if (unit.imei) parts.push(`<span><b>IMEI 1:</b> ${esc(unit.imei)}</span>`);
      if (unit.imei2) parts.push(`<span><b>IMEI 2:</b> ${esc(unit.imei2)}</span>`);
      if (unit.serial_no) parts.push(`<span><b>Serial:</b> ${esc(unit.serial_no)}</span>`);
      const condition = unit.condition_type === 'preloved' ? (unit.condition_grade ? `Pre-Loved • ${esc(unit.condition_grade)}` : 'Pre-Loved') : 'Brand New';
      parts.push(`<span><b>Condition:</b> ${condition}</span>`);
      return `<div class="transfer-proof-unit${unit.unit_deleted ? ' is-deleted' : ''}"><span class="transfer-proof-index">${index + 1}</span><div class="transfer-proof-line">${parts.join('')}</div></div>`;
    }).join('');
  };

  const populate = data => {
    const t = data.transfer || {};
    activeTransfer = t;
    form.querySelector('input[name="transfer_id"]').value = String(t.id || '');
    setText('[data-transfer-reference]', t.reference_no);
    setText('[data-transfer-source]', t.source_branch);
    setText('[data-transfer-destination]', t.destination_branch);
    setText('[data-transfer-product]', t.product_name);
    setText('[data-transfer-specs]', t.specs);
    setText('[data-transfer-quantity]', String(t.quantity ?? '—'));
    setText('[data-transfer-forwarded-by]', t.forwarded_by);
    setText('[data-transfer-forwarded-at]', formatDate(t.forwarded_at));
    setText('[data-transfer-notes]', t.notes || 'No notes');
    renderUnits(data.units || []);

    const status = q('[data-transfer-status]');
    if (status) {
      status.textContent = t.status === 'received' ? 'Received' : 'Pending Receipt';
      status.className = `transfer-status ${t.status === 'received' ? 'received' : 'pending'}`;
    }

    const receiverField = q('[data-transfer-receiver-field]');
    const proof = q('[data-transfer-received-proof]');
    const submit = q('[data-transfer-submit]');
    const receiver = q('input[name="receiver_name"]');
    const canReceive = !!t.can_receive;
    show(receiverField, canReceive);
    show(submit, canReceive);
    show(proof, t.status === 'received');
    if (canReceive && receiver) {
      receiver.value = '';
      window.setTimeout(() => receiver.focus(), 80);
    }
    if (t.status === 'received') {
      setText('[data-transfer-receiver-name]', t.receiver_name || '—');
      setText('[data-transfer-received-by]', t.received_by_user ? `Confirmed by ${t.received_by_user}` : 'Confirmed by branch user');
      setText('[data-transfer-received-at]', formatDate(t.received_at));
    }
  };

  const open = async transferId => {
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    show(q('[data-transfer-loading]'), true);
    show(q('[data-transfer-content]'), false);
    setError('');
    setReceiverError('');
    try {
      const response = await fetch(`actions/transfer_details.php?transfer_id=${encodeURIComponent(transferId)}`, {headers:{Accept:'application/json'},credentials:'same-origin'});
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || 'Unable to load transfer details.');
      populate(data);
      show(q('[data-transfer-loading]'), false);
      show(q('[data-transfer-content]'), true);
    } catch (error) {
      q('[data-transfer-loading]').textContent = error.message || 'Unable to load transfer details.';
    }
  };

  document.addEventListener('click', event => {
    const button = event.target.closest?.('[data-transfer-open]');
    if (button) {
      event.preventDefault();
      void open(button.dataset.transferId);
      return;
    }
    if (event.target.closest?.('[data-transfer-close]')) {
      event.preventDefault();
      close();
    }
  });
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && !modal.hidden) close(); });
  q('input[name="receiver_name"]')?.addEventListener('input', () => setReceiverError(''));

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!activeTransfer?.can_receive) return;
    const receiver = q('input[name="receiver_name"]');
    const submit = q('[data-transfer-submit]');
    const csrf = q('input[name="_csrf"]');
    const transferId = q('input[name="transfer_id"]')?.value;
    const name = receiver?.value.trim() || '';
    setError('');
    setReceiverError('');
    if (name.length < 2) {
      setReceiverError('Enter the name of the person who received the inventory.');
      receiver?.focus();
      return;
    }
    if (!submit || !csrf || !transferId) return;
    if (!window.SafeSubmit) {
      setError('The page did not load completely. Please refresh the page and try again.');
      return;
    }
    // Words for the offline outbox (Patch 6c-1).
    const t = activeTransfer || {};
    const productLabel = [t.product_name, t.specs && t.specs !== '—' ? t.specs : ''].filter(Boolean).join(' • ');
    const outboxLines = [`Reference: ${t.reference_no || ''}`, `From: ${t.source_branch || ''}`, `To: ${t.destination_branch || ''}`, `Quantity: ${t.quantity ?? ''}`, `Received by: ${name}`];
    qa('.transfer-proof-line', q('[data-transfer-units]') || modal).forEach(line => {
      const text = line.textContent.replace(/\s+/g, ' ').trim().slice(0, 120);
      if (text) outboxLines.push(`Unit: ${text}`);
    });

    // Same key on every retry → the server confirms the first receipt instead of moving stock twice.
    await window.SafeSubmit.run({
      url: 'actions/receive_transfer.php',
      keyHolder: form,
      button: submit,
      host: submit.parentElement,
      before: submit,
      getBody: () => ({ _csrf: csrf.value, transfer_id: Number(transferId), receiver_name: name }),
      onCsrf: token => { csrf.value = token; },
      successDetail: data => `${data.reference_no || ''} · ${data.message || ''}`,
      onDismiss: () => window.location.reload(),
      outbox: {
        type: 'transfer_receive',
        summary: `Receive ${t.quantity ?? ''} ${Number(t.quantity) === 1 ? 'item' : 'items'} of ${productLabel} from ${t.source_branch || 'another branch'}`.replace(/\s+/g, ' '),
        lines: outboxLines.filter(line => !/:\s*$/.test(line)),
        dedupe: `transfer_receive:${transferId}`,
        review: { page: 'branch-transfers', params: { open: transferId } }
      }
    });
  });

  // Outbox "Review" opens this page with ?open=<transfer id> so the transfer is shown right away.
  const autoOpen = new URLSearchParams(window.location.search).get('open');
  if (autoOpen && /^\d+$/.test(autoOpen)) void open(autoOpen);
})();
