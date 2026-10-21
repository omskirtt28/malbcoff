<?php
$root=dirname(__DIR__,2);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($path,'/__fixture')){
 if(getenv('DB_NAME')!=='malbcoff_fix_20261020_v2'){http_response_code(404);exit;}
 require $root.'/bootstrap.php';
 $role=$_GET['role']??'branch_manager';
 $u=Database::query('SELECT u.*,b.name branch_name FROM users u LEFT JOIN branches b ON b.id=u.branch_id WHERE u.email=?',['lifecycle-'.$role.'@test.invalid'])->fetch();
 if(!$u)throw new RuntimeException('Fixture user missing');
 unset($u['password_hash']);$_SESSION['user']=$u;unset($_SESSION['_impersonation']);Security::initializeAuthenticatedSession();
 if(isset($_GET['branch'])&&$u['branch_id'])$_SESSION['user']['branch_id']=(int)$_GET['branch'];
 header('Content-Type: application/json');echo json_encode(['csrf'=>Csrf::token(),'user'=>$u['id'],'branch'=>$_SESSION['user']['branch_id']]);exit;
}
if($path==='/'||(!str_contains($path,'.')&&!str_starts_with($path,'/assets/'))){$_GET['page']=trim($path,'/')?:'dashboard';$_SERVER['SCRIPT_NAME']='/index.php';require $root.'/index.php';return true;}
return false;
