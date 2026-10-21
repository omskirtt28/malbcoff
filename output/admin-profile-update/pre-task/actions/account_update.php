<?php
// Self-service: a logged-in user changes ONLY their own display name.
// The record id comes from the session — nothing in the request can select another user.
require __DIR__ . '/../bootstrap.php';

if (!Auth::check()) redirect(app_url('login'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(app_url('account'));
if (!Csrf::verify($_POST['_csrf'] ?? null)) {
    flash('error', 'Your session expired. Please try again.');
    redirect(app_url('account'));
}
if (Auth::isImpersonating() || Auth::isSystemAdmin()) {
    flash('error', 'Return to your own account before changing a display name.');
    redirect(app_home_url());
}

$userId = (int)(Auth::user()['id'] ?? 0);
$submitted = (string)($_POST['name'] ?? '');

try {
    $name = validate_display_name($submitted);
    $updated = Database::query('UPDATE users SET name=? WHERE id=? AND is_active=1', [$name, $userId]);
    if ($updated->rowCount() === 0) {
        // Unchanged name also reports 0 rows on some drivers: make sure the account is still active.
        $exists = Database::query('SELECT 1 FROM users WHERE id=? AND is_active=1 LIMIT 1', [$userId])->fetchColumn();
        if (!$exists) throw new RuntimeException('Your account is no longer active.');
    }
    Auth::refreshCurrentUser();
    flash('success', 'Your display name is now "' . $name . '".');
} catch (Throwable $e) {
    flash('error', safe_exception_message($e, 'Unable to save your name right now. Please try again.'));
    flash('account_name', mb_substr($submitted, 0, 120));
}

redirect(app_url('account'));
