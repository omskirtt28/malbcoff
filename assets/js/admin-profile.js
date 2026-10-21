(() => {
  'use strict';
  document.querySelectorAll('[data-open-account-dialog]').forEach(button => {
    button.addEventListener('click', () => document.getElementById(button.dataset.openAccountDialog)?.showModal());
  });
  document.querySelectorAll('[data-close-account-dialog]').forEach(button => {
    button.addEventListener('click', () => button.closest('dialog').close());
  });
  document.querySelectorAll('.admin-profile-dialog').forEach(dialog => {
    dialog.addEventListener('close', () => {
      const password = dialog.querySelector('[name="temporary_password"]');
      if (password) password.value = '';
      if (dialog.querySelector('.save-status.is-success')) window.location.reload();
    });
  });
  const role = document.querySelector('[data-admin-user-role]');
  const branch = document.querySelector('[data-admin-user-branch]');
  const syncBranch = () => {
    if (!role || !branch) return;
    const owner = role.value === 'owner';
    branch.disabled = owner;
    branch.required = !owner;
    branch.hidden = owner;
    if (owner) branch.value = '';
    document.querySelector('[data-admin-all-branches]').hidden = !owner;
  };
  role?.addEventListener('change', syncBranch);
  syncBranch();
  document.querySelectorAll('[data-account-change-form]').forEach(form => {
    // No outbox option: credentials are held only by the live form, never persisted on this device.
    form.addEventListener('submit', event => {
      if (!window.SafeSubmit) return; // The native POST keeps CSRF + server-generated retry key.
      event.preventDefault();
      const fields = [...form.elements].filter(field => field.type !== 'submit');
      const disabled = fields.map(field => field.disabled);
      form.dataset.clientKey = form.dataset.clientKey || form.elements.client_key.value;
      const body = new FormData(form);
      fields.forEach(field => { field.disabled = true; });
      const restore = () => fields.forEach((field, index) => { field.disabled = disabled[index]; });
      let csrf = body.get('_csrf');
      SafeSubmit.run({
        form, button: form.querySelector('[type="submit"]'), host: form,
        url: form.action,
        getBody: () => { body.set('_csrf', csrf); return body; },
        onCsrf: token => { csrf = token; form.elements._csrf.value = token; },
        successTitle: 'Saved', successDetail: data => data.message,
        onSuccess: data => {
          // Remove credentials from both the form and the in-memory retry body immediately.
          const password = form.querySelector('[name="temporary_password"]');
          if (password) password.value = '';
          body.delete('temporary_password');
          if (data.name) {
            document.querySelectorAll('.user-copy strong, .user-dropdown-meta strong').forEach(node => { node.textContent = data.name; });
            const avatar = document.querySelector('.user-menu .avatar');
            if (avatar) avatar.textContent = [...data.name][0].toUpperCase();
          }
          form.elements.client_key.value = SafeSubmit.uuid();
          const dialog = form.closest('dialog');
          if (dialog && !dialog.open) window.location.reload();
        },
        onDismiss: () => window.location.reload(),
      }).then(result => {
        if (result.business || result.cancelled) {
          restore();
          if (result.business) {
            delete form.dataset.clientKey;
            form.elements.client_key.value = SafeSubmit.uuid();
          }
        }
      }).catch(restore);
    });
  });
})();
