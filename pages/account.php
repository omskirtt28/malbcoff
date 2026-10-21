<?php
// My Account — a logged-in user edits ONLY their own display name (id comes from the session in the action).
$me = Auth::user() ?? [];
require_once __DIR__ . '/../core/AccountChanges.php';
$impersonating = Auth::isImpersonating();
$nameValue = flash('account_name');
if ($nameValue === null) $nameValue = (string)($me['name'] ?? '');
?>
<section class="page-heading account-heading">
    <div>
        <span class="eyebrow">MY ACCOUNT</span>
        <h1>My Account</h1>
        <p>Change the display name shown in the top bar, on the Users page and on new sales, stock-in and transfer records. Records already made keep the name used at that time.</p>
    </div>
</section>

<?php if ($impersonating): ?>
    <div class="alert alert-info">You are viewing this account as System Admin. Return to System Admin before changing a name.</div>
<?php endif; ?>

<section class="card account-card">
    <h2>Edit Name</h2>
    <form method="post" action="actions/account_update.php" class="account-form" autocomplete="off" data-account-change-form>
        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="client_key" value="<?= e(account_change_key()) ?>">
        <label class="field">
            <span>Display name <b>*</b></span>
            <input type="text" name="name" value="<?= e($nameValue) ?>" required maxlength="60" autocomplete="name" <?= $impersonating ? 'disabled' : '' ?>>
            <small class="account-hint">Required · up to 60 characters · no HTML.</small>
        </label>
        <button class="btn btn-primary account-save" type="submit" <?= $impersonating ? 'disabled' : '' ?>>Save name</button>
    </form>

    <dl class="account-meta" aria-label="Account details (read-only)">
        <div><dt>Email / username</dt><dd><?= e((string)($me['email'] ?? '—')) ?></dd></div>
        <div><dt>Role</dt><dd><?= e(role_label((string)($me['role'] ?? ''))) ?></dd></div>
        <div><dt>Branch</dt><dd><?= e((string)($me['branch_name'] ?? ($me['role'] === 'owner' ? 'All Branches' : '—'))) ?></dd></div>
    </dl>
</section>
