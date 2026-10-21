<?php
/** Online-only account mutations. Passwords never enter the saved intent or result. */
require_once __DIR__ . '/Idempotency.php';

function account_change_reply(int $status, array $body, string $route): never
{
    if (Idempotency::wantsJson()) Idempotency::send($status, $body);
    flash($status < 300 ? 'success' : 'error', (string)($body['message'] ?? $body['error'] ?? 'Unable to save.'));
    redirect(app_url($route));
}

function account_change_key(): string
{
    $hex = bin2hex(random_bytes(16));
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function account_change_authorized(?array $user, bool $admin): bool
{
    return $user && (int)$user['is_active'] === 1 && !(int)$user['must_change_password']
        && ($admin ? $user['role'] === 'system_admin' : in_array($user['role'], ['owner','branch_manager','inventory','cashier'], true));
}

/** The callback receives the locked session user. Writes and retry result commit together. */
function run_account_change(string $action, array $intent, callable $save, bool $admin = true): never
{
    $route = $admin ? 'system-admin-users' : 'account';
    if (!Auth::check()) account_change_reply(401, ['ok'=>false, 'error'=>'Please sign in again.', 'code'=>'auth'], $route);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') account_change_reply(405, ['ok'=>false, 'error'=>'Use the save button to submit this request.'], $route);
    if (Auth::isImpersonating() || ($admin ? !Auth::isSystemAdmin() : Auth::isSystemAdmin())) {
        account_change_reply(403, ['ok'=>false, 'error'=>$admin ? 'Only a directly signed-in System Admin can make this change.' : 'Return to your own account before changing a display name.'], $route);
    }
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        if (Idempotency::wantsJson()) Idempotency::csrfFailure();
        account_change_reply(419, ['ok'=>false, 'error'=>'Your session expired. Please try again.'], $route);
    }
    foreach ($_POST as $value) {
        if (!is_string($value)) account_change_reply(422, ['ok'=>false, 'error'=>'Enter one value for each field.'], $route);
    }
    $pdo = Database::connection();
    $userId = (int)Auth::user()['id'];
    try {
        if (!account_change_authorized(Auth::fetchUser($userId), $admin)) {
            account_change_reply(403, ['ok'=>false, 'error'=>'Your account no longer has permission to make this change.'], $route);
        }
        $key = Idempotency::key();
        if ($key === null) throw new RuntimeException('Refresh the page before saving. Safe saving must be available.');
        $fingerprint = Idempotency::fingerprint($intent);
        $pdo->beginTransaction();
        $claimed = Idempotency::claim($key, $action, $userId, $fingerprint);
        $user = Database::query('SELECT id,role,is_active,must_change_password FROM users WHERE id=? FOR UPDATE', [$userId])->fetch();
        if (!account_change_authorized($user ?: null, $admin)) {
            $pdo->rollBack();
            account_change_reply(403, ['ok'=>false, 'error'=>'Your account no longer has permission to make this change.'], $route);
        }
        if (!$claimed) {
            $prior = Idempotency::lookup($key, $action, $userId, $fingerprint);
            $pdo->rollBack();
            if ($prior === null) account_change_reply(409, ['ok'=>false, 'error'=>'This request is still being saved. Please try again.', 'code'=>'in_progress'], $route);
            if (!$admin) Auth::refreshCurrentUser();
            account_change_reply(200, $prior + ['replayed'=>true], $route);
        }
        $result = $save($user);
        Idempotency::complete($key, $result);
        $pdo->commit();
        if (!$admin) Auth::refreshCurrentUser();
        account_change_reply(200, $result, $route);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Do not log SQL exception text from a password-bearing INSERT.
        if ($error instanceof PDOException) {
            $message = (string)$error->getCode() === '23000' && $action === 'admin_user_create'
                ? 'That email address is already assigned to another user.' : 'Unable to save right now. Please try again.';
        } elseif ($error instanceof RuntimeException) {
            $message = $error->getMessage();
        } else {
            $message = 'Unable to save right now. Please try again.';
        }
        account_change_reply(422, ['ok'=>false, 'error'=>$message], $route);
    }
}
