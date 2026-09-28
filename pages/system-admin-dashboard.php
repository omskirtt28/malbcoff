<?php
$stats=['active_users'=>0,'branches'=>0,'sessions_today'=>0,'events_today'=>0];
try {
    $stats['active_users']=(int)Database::query("SELECT COUNT(*) FROM users WHERE is_active=1 AND role<>'system_admin'")->fetchColumn();
    $stats['branches']=(int)Database::query("SELECT COUNT(*) FROM branches WHERE is_active=1")->fetchColumn();
    $stats['sessions_today']=(int)Database::query("SELECT COUNT(*) FROM security_impersonation_sessions WHERE started_at>=CURDATE()")->fetchColumn();
    $stats['events_today']=(int)Database::query("SELECT COUNT(*) FROM security_audit_logs WHERE created_at>=CURDATE()")->fetchColumn();
} catch(Throwable $e){ Security::reportException($e,'system_admin_dashboard'); }
?>
<section class="page-heading"><div><span class="eyebrow">SYSTEM CONTROL</span><h1>System Admin</h1><p>Support users without viewing or changing their passwords. Every impersonated action remains traceable.</p></div><a class="btn btn-primary" href="index.php?page=system-admin-users"><?= icon('users') ?> User Accounts</a></section>
<div class="kpi-grid system-admin-kpis">
<article class="kpi-card tint-blue"><span>Active Users</span><strong><?= $stats['active_users'] ?></strong><small>Owner and branch accounts</small></article>
<article class="kpi-card tint-green"><span>Active Branches</span><strong><?= $stats['branches'] ?></strong><small>Current branch master</small></article>
<article class="kpi-card tint-violet"><span>Account Sessions Today</span><strong><?= $stats['sessions_today'] ?></strong><small>System Admin entries</small></article>
<article class="kpi-card tint-amber"><span>Security Events Today</span><strong><?= $stats['events_today'] ?></strong><small>Audit activity</small></article>
</div>
<section class="card system-admin-guide"><div class="card-header"><div><h2>Support Workflow</h2><p>Use controlled impersonation instead of asking users for passwords.</p></div></div><div class="system-admin-steps"><div><b>1</b><span><strong>Find the account</strong><small>Search by user, role, or branch.</small></span></div><div><b>2</b><span><strong>Enter Account</strong><small>The target role and branch permissions apply immediately.</small></span></div><div><b>3</b><span><strong>Return to System Admin</strong><small>The banner stays visible while impersonating.</small></span></div></div></section>
