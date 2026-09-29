<?php
require __DIR__ . '/bootstrap.php';
if (!Auth::check()) redirect(app_url('login'));
if (Auth::isImpersonating()) {
    flash('error', 'Return to System Admin before changing a password.');
    redirect(app_home_url());
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && is_legacy_script_request('change-password.php')) {
    redirect(app_url('change-password'));
}

$error = null;
$success = null;
$forced = Auth::requiresPasswordChange();
$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        try {
            if ($newPassword !== $confirmPassword) throw new RuntimeException('The new passwords do not match.');
            validate_account_password($newPassword);

            $record = Database::query('SELECT password_hash FROM users WHERE id=? AND is_active=1 LIMIT 1', [(int)$user['id']])->fetch();
            if (!$record) throw new RuntimeException('Your account is no longer active.');
            if (!$forced && !password_verify($currentPassword, (string)$record['password_hash'])) {
                throw new RuntimeException('Current password is incorrect.');
            }
            if (password_verify($newPassword, (string)$record['password_hash'])) {
                throw new RuntimeException('Choose a new password that is different from your current password.');
            }

            Database::query(
                'UPDATE users SET password_hash=?,must_change_password=0,password_changed_at=NOW() WHERE id=?',
                [password_hash($newPassword, PASSWORD_DEFAULT),(int)$user['id']]
            );
            Security::audit('auth.password_changed', 'user', (int)$user['id'], ['forced' => $forced]);
            Auth::refreshCurrentUser();
            Csrf::rotate();
            session_regenerate_id(true);
            Security::initializeAuthenticatedSession();
            flash('success', 'Password updated successfully.');
            redirect(app_home_url());
        } catch (Throwable $e) {
            $error = safe_exception_message($e, 'Unable to update your password right now. Please try again.');
        }
    }
}

function validate_account_password(string $password): void
{
    $length = strlen($password);
    if ($length < 12) throw new RuntimeException('Password must be at least 12 characters.');
    if ($length > 128) throw new RuntimeException('Password is too long.');
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        throw new RuntimeException('Password must contain at least one letter and one number.');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Change Password | <?= e($app['name']) ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="shortcut icon" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/app.css') ?>">
</head>
<body class="auth-page account-security-page">
<div class="auth-shell account-security-shell">
    <section class="auth-brand-panel">
        <div class="brand-mark">M</div>
        <h1><?= e($app['name']) ?></h1>
        <p><?= e($app['subtitle']) ?></p>
        <div class="auth-feature-list">
            <div><?= icon('shield') ?><span>Use a unique password for this account</span></div>
            <div><?= icon('users') ?><span>Never share user accounts between employees</span></div>
            <div><?= icon('alert') ?><span>Minimum 12 characters with letters and numbers</span></div>
        </div>
    </section>
    <section class="auth-card">
        <div class="auth-mobile-brand" aria-hidden="true">
            <div class="brand-mark">M</div>
        </div>
        <div class="auth-card-header">
            <span class="eyebrow">ACCOUNT SECURITY</span>
            <h2><?= $forced ? 'Create your private password' : 'Change password' ?></h2>
            <p><?= $forced ? 'Your temporary password must be replaced before you can continue.' : 'Update the password for your Malbcoff account.' ?></p>
        </div>
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
            <?php if (!$forced): ?>
            <label class="field"><span>Current password</span><input type="password" name="current_password" autocomplete="current-password" required></label>
            <?php endif; ?>
            <label class="field"><span>New password</span><input type="password" name="new_password" autocomplete="new-password" minlength="12" maxlength="128" required></label>
            <label class="field"><span>Confirm new password</span><input type="password" name="confirm_password" autocomplete="new-password" minlength="12" maxlength="128" required></label>
            <button class="btn btn-primary btn-block" type="submit">Save New Password</button>
            <?php if (!$forced): ?><a class="btn btn-secondary btn-block" href="<?= e(app_home_url()) ?>">Cancel</a><?php endif; ?>
        </form>
        <?php if ($forced): ?><a class="auth-secondary-link" href="<?= e(app_url('logout')) ?>">Sign out instead</a><?php endif; ?>
    </section>
</div>
</body>
</html>
