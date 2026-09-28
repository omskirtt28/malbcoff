(() => {
  'use strict';
  const qs = (s, r = document) => r.querySelector(s);
  const qsa = (s, r = document) => [...r.querySelectorAll(s)];
  const modal = qs('#userAccountModal');
  const resetModal = qs('#userResetModal');
  if (!modal && !resetModal) return;

  const openModal = (el) => {
    if (!el) return;
    el.hidden = false;
    document.body.classList.add('modal-open');
  };
  const closeModal = (el) => {
    if (!el) return;
    el.hidden = true;
    if (!qs('.user-account-modal:not([hidden])')) document.body.classList.remove('modal-open');
  };

  const syncBranchField = () => {
    if (!modal) return;
    const role = qs('[data-user-role]', modal)?.value || '';
    const wrap = qs('[data-user-branch-wrap]', modal);
    const field = qs('[data-user-branch]', modal);
    const isOwner = role === 'owner';
    if (wrap) wrap.hidden = isOwner;
    if (field) {
      field.required = !isOwner;
      if (isOwner) field.value = '';
    }
  };

  const setupCreate = () => {
    if (!modal) return;
    const form = qs('[data-user-account-form]', modal);
    form?.reset();
    qs('[data-user-action]', modal).value = 'create';
    qs('[data-user-id]', modal).value = '';
    qs('[data-user-modal-eyebrow]', modal).textContent = 'NEW ACCOUNT';
    qs('[data-user-modal-title]', modal).textContent = 'Add User';
    qs('[data-user-submit]', modal).textContent = 'Create User';
    const pwWrap = qs('[data-user-temp-password-wrap]', modal);
    const pw = qs('[data-user-temp-password]', modal);
    if (pwWrap) pwWrap.hidden = false;
    if (pw) pw.required = true;
    syncBranchField();
    openModal(modal);
    setTimeout(() => qs('[data-user-name]', modal)?.focus(), 50);
  };

  const setupEdit = (button) => {
    if (!modal) return;
    const form = qs('[data-user-account-form]', modal);
    form?.reset();
    qs('[data-user-action]', modal).value = 'update';
    qs('[data-user-id]', modal).value = button.dataset.id || '';
    qs('[data-user-name]', modal).value = button.dataset.name || '';
    qs('[data-user-email]', modal).value = button.dataset.email || '';
    qs('[data-user-role]', modal).value = button.dataset.role || 'cashier';
    qs('[data-user-branch]', modal).value = button.dataset.branchId || '';
    qs('[data-user-modal-eyebrow]', modal).textContent = 'EDIT ACCOUNT';
    qs('[data-user-modal-title]', modal).textContent = 'Edit User';
    qs('[data-user-submit]', modal).textContent = 'Save Changes';
    const pwWrap = qs('[data-user-temp-password-wrap]', modal);
    const pw = qs('[data-user-temp-password]', modal);
    if (pwWrap) pwWrap.hidden = true;
    if (pw) {
      pw.required = false;
      pw.value = '';
    }
    syncBranchField();
    openModal(modal);
  };

  document.addEventListener('click', (event) => {
    const create = event.target.closest('[data-user-create]');
    if (create) {
      event.preventDefault();
      setupCreate();
      return;
    }
    const edit = event.target.closest('[data-user-edit]');
    if (edit) {
      event.preventDefault();
      setupEdit(edit);
      return;
    }
    const reset = event.target.closest('[data-user-reset]');
    if (reset && resetModal) {
      event.preventDefault();
      qs('[data-user-reset-id]', resetModal).value = reset.dataset.id || '';
      const copy = qs('[data-user-reset-copy]', resetModal);
      if (copy) copy.textContent = `Set a temporary password for ${reset.dataset.name || 'this user'}. They must replace it after signing in.`;
      const input = qs('input[name="temporary_password"]', resetModal);
      if (input) input.value = '';
      openModal(resetModal);
      setTimeout(() => input?.focus(), 50);
      return;
    }
    const close = event.target.closest('[data-user-modal-close]');
    if (close) {
      event.preventDefault();
      closeModal(modal);
      return;
    }
    const resetClose = event.target.closest('[data-user-reset-close]');
    if (resetClose) {
      event.preventDefault();
      closeModal(resetModal);
    }
  });

  qs('[data-user-role]', modal)?.addEventListener('change', syncBranchField);

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    closeModal(modal);
    closeModal(resetModal);
  });
})();
