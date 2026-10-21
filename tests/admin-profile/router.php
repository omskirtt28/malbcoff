<?php
$root=dirname(__DIR__,2);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(getenv('DB_NAME')!=='malbcoff_admin_profile_20261021'){http_response_code(404);exit;}
if (str_starts_with($path,'/__test')) {
    require $root.'/bootstrap.php';
    if($path==='/__test/login') {
        $role=$_GET['role']??'system_admin';
        $id=Database::query('SELECT id FROM users WHERE email=?',[$role.'@test.invalid'])->fetchColumn();
        $_SESSION['user']=Auth::fetchUser((int)$id); unset($_SESSION['_impersonation']);
        Security::initializeAuthenticatedSession();
    }
    if($path==='/__test/logout') Auth::logout();
    if($path==='/__test/state') {
        header('Content-Type: application/json');
        $state=[];
        foreach(['branches','sales','sale_items','stock_movements','inventory_transfers','inventory_units','idempotency_keys','security_audit_logs'] as $table) $state[$table]=Database::query('SELECT * FROM '.$table.' ORDER BY '.($table==='idempotency_keys'?'client_key':'id'))->fetchAll();
        $state['users']=Database::query('SELECT id,name,email,branch_id,role,is_active,must_change_password,password_changed_at FROM users ORDER BY id')->fetchAll();
        $state['session']=Auth::user();
        $state['password_ok']=[];
        foreach(Database::query("SELECT id,password_hash FROM users WHERE email LIKE 'created-%' OR email='ui-created@test.invalid'")->fetchAll() as $row) $state['password_ok'][$row['id']]=password_verify('TemporaryPass123',$row['password_hash']);
        echo json_encode($state);exit;
    }
    header('Content-Type: application/json');echo json_encode(['csrf'=>Csrf::token(),'user'=>Auth::user()]);exit;
}
if($path==='/'||(!str_contains($path,'.')&&!str_starts_with($path,'/assets/'))){
    $route=trim($path,'/');
    if(in_array($route,['login','logout','change-password'],true)){$_SERVER['SCRIPT_NAME']='/'.$route.'.php';require $root.'/'.$route.'.php';return true;}
    $_GET['page']=$route==='system-admin'?'system-admin-dashboard':($route?:'dashboard');
    $_SERVER['SCRIPT_NAME']='/index.php';require $root.'/index.php';return true;
}
return false;
