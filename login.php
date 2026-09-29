<?php
require __DIR__ . '/bootstrap.php';
if (Auth::check()) {
    redirect(app_home_url());
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && is_legacy_script_request('login.php')) {
    redirect(app_url('login'));
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $email = Security::normalizeEmail((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $rate = Security::loginRateStatus($email);

        if (empty($rate['allowed'])) {
            Security::audit('auth.login_blocked', 'login', null, [
                'email_hash' => hash('sha256', $email),
                'retry_after' => (int)($rate['retry_after'] ?? 0),
                'setup_required' => !empty($rate['setup_required']),
            ]);
            $error = !empty($rate['setup_required'])
                ? 'Sign in is temporarily unavailable. Please contact the system administrator.'
                : 'Too many sign-in attempts. Please wait a few minutes and try again.';
        } else {
            try {
                if (Auth::attempt($email, $password)) {
                    Security::recordLoginSuccess($email);
                    Security::audit('auth.login_success', 'user', (int)(Auth::user()['id'] ?? 0));
                    redirect(Auth::requiresPasswordChange() ? app_url('change-password') : app_home_url());
                }
                Security::recordLoginFailure($email);
                Security::audit('auth.login_failed', 'login', null, ['email_hash' => hash('sha256', $email)]);
                usleep(250000);
                $error = 'Invalid email or password.';
            } catch (Throwable $e) {
                Security::reportException($e, 'login');
                $error = 'Sign in is temporarily unavailable. Please contact the system administrator.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | <?= e($app['name']) ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="shortcut icon" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/app.css') ?>">
</head>
<body class="auth-page">
<div class="auth-shell">
    <section class="auth-brand-panel">
        <div class="brand-mark">M</div>
        <h1>Malbcoff Trading</h1>
        <p>POS & Inventory System</p>
        <div class="auth-feature-list">
            <div><?= icon('shield') ?><span>Role-based branch access</span></div>
            <div><?= icon('inventory') ?><span>IMEI and barcode-ready inventory</span></div>
            <div><?= icon('movement') ?><span>Clear stock in / out history</span></div>
        </div>
    </section>
    <section class="auth-card">
        <div class="auth-mobile-brand" aria-hidden="true">
            <div class="brand-mark">M</div>
        </div>
        <div class="auth-card-header">
            <span class="eyebrow">WELCOME BACK</span>
            <h2>Sign in to continue</h2>
            <p>Use your assigned Malbcoff Trading account.</p>
        </div>
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
            <label class="field">
                <span>Email address</span>
                <input type="email" name="email" placeholder="Enter your email address" autocomplete="username" required autofocus>
            </label>
            <label class="field">
                <span>Password</span>
                <input type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
            </label>
            <button class="btn btn-primary btn-block" type="submit">Sign In</button>
        </form>
    </section>
</div>
</body>
</html>
