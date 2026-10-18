<?php
require __DIR__ . '/bootstrap.php';
// Login must work with no JavaScript at all: this page is a plain HTML form + a normal POST + a normal redirect.
$sessionCookieSent = isset($_COOKIE[session_name()]) && $_COOKIE[session_name()] !== '';
Security::loginDebug('login.request', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'session_cookie_sent' => $sessionCookieSent,
    'https' => Security::isHttps(),
    'logged_in' => Auth::check(),
    'ua' => Security::userAgent(),
    'sec_fetch_mode' => (string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''),
]);

if (Auth::check()) {
    Security::loginDebug('login.already_signed_in', ['redirect' => app_home_url()]);
    redirect(app_home_url());
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && is_legacy_script_request('login.php')) {
    redirect(app_url('login'));
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // The session ended on its own (idle, other browser, revoked): say so instead of silently showing the form.
    $error = Security::takeLoginNotice();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = Csrf::verify($_POST['_csrf'] ?? null);
    Security::loginDebug('login.post', [
        'check' => $csrfOk ? 'ok' : (!$sessionCookieSent ? 'no_session_cookie' : 'mismatch'),
        'has_email' => trim((string)($_POST['email'] ?? '')) !== '',
        'pw_filled' => (string)($_POST['password'] ?? '') !== '',
    ]);
    if (!$csrfOk) {
        $error = !$sessionCookieSent
            ? 'Your browser did not keep its session. Please enable cookies, then refresh the page and try again.'
            : 'Your security token expired. Please refresh the page and try again.';
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
            Security::loginDebug('login.blocked', ['setup_required' => !empty($rate['setup_required']), 'retry_after' => (int)($rate['retry_after'] ?? 0)]);
            $error = !empty($rate['setup_required'])
                ? 'Sign in is temporarily unavailable. Please contact the system administrator.'
                : 'Too many sign-in attempts. Please wait a few minutes and try again.';
        } else {
            try {
                if (Auth::attempt($email, $password)) {
                    Security::recordLoginSuccess($email);
                    Security::audit('auth.login_success', 'user', (int)(Auth::user()['id'] ?? 0));
                    $target = Auth::requiresPasswordChange() ? app_url('change-password') : app_home_url();
                    Security::loginDebug('login.session_created', ['user_id' => (int)(Auth::user()['id'] ?? 0), 'session_id_hash' => substr(hash('sha256', session_id()), 0, 8), 'redirect' => $target]);
                    redirect($target);
                }
                Security::recordLoginFailure($email);
                Security::audit('auth.login_failed', 'login', null, ['email_hash' => hash('sha256', $email)]);
                Security::loginDebug('login.wrong_password');
                usleep(250000);
                $error = 'Invalid email or password.';
            } catch (Throwable $e) {
                Security::reportException($e, 'login');
                Security::loginDebug('login.exception', ['class' => get_class($e)]);
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
    <?php require __DIR__ . '/partials/pwa-head.php'; ?>
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
        <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e(app_url('login')) ?>" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
            <label class="field">
                <span>Email address</span>
                <input type="email" name="email" placeholder="Enter your email address" autocomplete="username" inputmode="email" autocapitalize="none" autocorrect="off" spellcheck="false" required>
            </label>
            <label class="field">
                <span>Password</span>
                <input type="password" name="password" placeholder="Enter your password" autocomplete="current-password" autocapitalize="none" autocorrect="off" spellcheck="false" required>
            </label>
            <button class="btn btn-primary btn-block" type="submit">Sign In</button>
        </form>
        <?php if (Security::loginDebugEnabled()): ?>
            <pre class="login-debug" aria-label="Login debug">Login debug (config login_debug is ON)
request <?= e(Security::requestId()) ?>

<?= e(implode("\n", Security::loginDebugLines())) ?></pre>
        <?php endif; ?>
    </section>
</div>
<?php $pwaAuthPage = true; require __DIR__ . '/partials/pwa-register.php'; ?>
</body>
</html>
