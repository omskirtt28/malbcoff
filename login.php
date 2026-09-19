<?php
require __DIR__ . '/bootstrap.php';
if (Auth::check()) {
    redirect('index.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        try {
            if (Auth::attempt($email, $password)) {
                redirect('index.php');
            }
            $error = 'Invalid email or password.';
        } catch (Throwable $e) {
            $error = 'Database is not ready yet. Import the SQL file first.';
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
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-page">
<div class="auth-shell">
    <section class="auth-brand-panel">
        <div class="brand-mark">M</div>
        <span class="phase-pill">Phase 1</span>
        <h1>Malbcoff Trading</h1>
        <p>POS & Inventory System</p>
        <div class="auth-feature-list">
            <div><?= icon('shield') ?><span>Role-based branch access</span></div>
            <div><?= icon('inventory') ?><span>IMEI and barcode-ready inventory</span></div>
            <div><?= icon('movement') ?><span>Clear stock in / out history</span></div>
        </div>
    </section>
    <section class="auth-card">
        <div class="auth-card-header">
            <span class="eyebrow">WELCOME BACK</span>
            <h2>Sign in to continue</h2>
            <p>Use your assigned Malbcoff Trading account.</p>
        </div>
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
            <label class="field">
                <span>Email address</span>
                <input type="email" name="email" placeholder="name@malbcoff.local" required autofocus>
            </label>
            <label class="field">
                <span>Password</span>
                <input type="password" name="password" placeholder="Enter your password" required>
            </label>
            <button class="btn btn-primary btn-block" type="submit">Sign In</button>
        </form>
        <div class="demo-credentials">
            <strong>Local demo accounts</strong>
            <span>Owner: owner@malbcoff.local / Owner@123</span>
            <span>Branch: branch1@malbcoff.local / Branch@123</span>
        </div>
    </section>
</div>
</body>
</html>
