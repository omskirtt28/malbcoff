<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (in_array($path,['/__fixture-data','/__fixture-state'],true)) {
    if(getenv('DB_NAME')!=='malbcoff_fix_20261020_v2'){http_response_code(404);exit;}
    header('Content-Type: application/json');
    if($path==='/__fixture-data')readfile(__DIR__.'/fix8c2-fixtures.json');
    else require __DIR__.'/fix8c2-state.php';
    exit;
}
return require __DIR__.'/router.php';
