(() => {
  const modal = document.getElementById('deleteAccessoryInventoryModal');
  if (!modal) return;
  const form = modal.querySelector('[data-delete-accessory-form]');
  const submit = form.querySelector('[type="submit"]');
  const errorBox = modal.querySelector('[data-delete-accessory-error]');
  let selected = null;
  let submitting = false;
  const close = () => {
    if (submitting) return;
    modal.hidden = true;
    document.body.classList.remove('modal-open');
    selected = null;
  };
  document.querySelectorAll('[data-delete-accessory-inventory]').forEach(button => {
    button.addEventListener('click', () => {
      if (submitting) return;
      selected = {product_id:Number(button.dataset.productId),branch_id:Number(button.dataset.branchId),expected_quantity:Number(button.dataset.available)};
      form.reset();
      errorBox.hidden = true;
      errorBox.textContent = '';
      modal.querySelector('[data-delete-accessory-product]').textContent = button.dataset.product;
      modal.querySelector('[data-delete-accessory-branch]').textContent = button.dataset.branch;
      modal.querySelector('[data-delete-accessory-quantity]').textContent = selected.expected_quantity.toLocaleString();
      modal.hidden = false;
      document.body.classList.add('modal-open');
      form.elements.reason.focus();
    });
  });
  modal.querySelectorAll('[data-delete-accessory-close]').forEach(button => button.addEventListener('click', close));
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && !modal.hidden) close(); });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (submitting || !selected || !form.reportValidity()) return;
    const reason = form.elements.reason.value.trim();
    if (reason.length < 3) {
      errorBox.textContent = 'Enter a reason of at least 3 characters.';
      errorBox.hidden = false;
      return;
    }
    submitting = true;
    submit.disabled = true;
    submit.textContent = 'Deleting…';
    errorBox.hidden = true;
    try {
      const response = await fetch('actions/delete_accessory_inventory.php', {
        method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({...selected,_csrf:form.dataset.csrf,reason,confirmed:form.elements.confirmed.checked})
      });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.error || 'Unable to delete inventory.');
      window.location.reload();
    } catch (error) {
      errorBox.textContent = error.message || 'Unable to delete inventory. Refresh to check the current stock before trying again.';
      errorBox.hidden = false;
      submitting = false;
      submit.disabled = false;
      submit.textContent = 'Delete Inventory';
    }
  });
})();
