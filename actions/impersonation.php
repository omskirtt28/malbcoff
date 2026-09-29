<?php
require __DIR__ . '/../bootstrap.php';

if (!Auth::check()) redirect(app_url('login'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}
if (!Csrf::verify($_POST['_csrf'] ?? null)) {
    flash('error', 'Your session expired. Please try again.');
    redirect(app_home_url());
}

$action = strtolower(trim((string)($_POST['action'] ?? '')));

try {
    if ($action === 'start') {
        if (!Auth::isSystemAdmin() || Auth::isImpersonating()) {
            throw new RuntimeException('Only a System Admin can enter another account.');
        }
        $targetId = filter_var($_POST['target_user_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $target = $targetId > 0 ? Auth::fetchUser($targetId) : null;
        if (!$target) throw new RuntimeException('User account not found.');
        if (!(int)$target['is_active']) throw new RuntimeException('Only active user accounts can be entered.');
        if (($target['role'] ?? '') === 'system_admin') throw new RuntimeException('System Admin accounts cannot be impersonated.');

        $actor = Auth::user();
        $sessionKey = bin2hex(random_bytes(16));
        Database::query(
            'INSERT INTO security_impersonation_sessions (session_key,system_admin_user_id,target_user_id,target_name,target_role,target_branch_id,start_ip,start_user_agent) VALUES (?,?,?,?,?,?,?,?)',
            [$sessionKey,(int)$actor['id'],(int)$target['id'],(string)$target['name'],(string)$target['role'],$target['branch_id']!==null?(int)$target['branch_id']:null,Security::clientIp(),Security::userAgent()]
        );
        $dbSessionId = (int)Database::connection()->lastInsertId();
        Auth::beginImpersonation($target, $dbSessionId, $sessionKey);
        Security::audit('impersonation.started', 'user', (int)$target['id'], ['system_admin_user_id' => (int)$actor['id'], 'target_role' => $target['role'], 'target_branch_id' => $target['branch_id']]);
        flash('success', 'You are now viewing the selected account.');
        redirect(app_home_url());
    }

    if ($action === 'end') {
        if (!Auth::isImpersonating() || !Auth::actorIsSystemAdmin()) {
            throw new RuntimeException('No active impersonation session was found.');
        }
        $target = Auth::user();
        Security::audit('impersonation.ended', 'user', (int)($target['id'] ?? 0), ['reason' => 'manual_return']);
        Security::closeImpersonationSession('manual_return');
        Auth::endImpersonation();
        flash('success', 'Returned to System Admin.');
        redirect(app_url('system-admin-dashboard'));
    }

    throw new RuntimeException('Invalid impersonation request.');
} catch (Throwable $e) {
    flash('error', safe_exception_message($e, 'Unable to change account view right now.'));
    redirect(app_url(Auth::isSystemAdmin() ? 'system-admin-users' : 'dashboard'));
}
