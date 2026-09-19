<?php
require __DIR__ . '/../bootstrap.php';

if (!Auth::check()) {
    if (isset($_GET['check_identifier']) || isset($_GET['check_imei'])) {
        http_response_code(401); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['exists'=>false,'error'=>'Unauthenticated']); exit;
    }
    redirect('../login.php');
}

$role=Auth::user()['role']??'';
$canStockIn=in_array($role,['owner','branch_manager','inventory'],true);
$canEditSelling=in_array($role,['owner','branch_manager'],true);

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['ajax_action'] ?? '') === 'create_variant') {
    header('Content-Type: application/json; charset=utf-8');
    if(!$canStockIn){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Your account cannot add product variants.']);exit;}
    if(!Csrf::verify($_POST['_csrf']??null)){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Your session expired. Refresh the page and try again.']);exit;}
    try {
        $variant=create_receive_variant($_POST);
        echo json_encode(['ok'=>true,'variant'=>$variant],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    exit;
}

if (isset($_GET['check_identifier']) || isset($_GET['check_imei'])) {
    header('Content-Type: application/json; charset=utf-8');
    if(!$canStockIn){http_response_code(403);echo json_encode(['exists'=>false,'error'=>'Forbidden']);exit;}
    $value=normalize_stock_identifier($_GET['check_identifier']??$_GET['check_imei']??'');
    if($value===''){echo json_encode(['exists'=>false]);exit;}
    try{
        $exists=(bool)Database::query('SELECT 1 FROM inventory_units WHERE imei=? OR serial_no=? LIMIT 1',[$value,$value])->fetchColumn();
        echo json_encode(['exists'=>$exists]);
    }catch(Throwable $e){http_response_code(500);echo json_encode(['exists'=>false,'error'=>'Database error']);}
    exit;
}

if(!$canStockIn){flash('error','Your account does not have permission to receive stock.');redirect('../index.php?page=inventory');}
if($_SERVER['REQUEST_METHOD']!=='POST')redirect('../index.php?page=stock-in');
if(!Csrf::verify($_POST['_csrf']??null)){flash('error','Your session expired. Please submit the Receive Stock form again.');redirect('../index.php?page=stock-in');}

$productId=filter_var($_POST['product_id']??null,FILTER_VALIDATE_INT)?:0;
$requestedBranch=filter_var($_POST['branch_id']??null,FILTER_VALIDATE_INT)?:0;
$branchId=Auth::isOwner()?$requestedBranch:(Auth::branchId()?:0);
$returnUrl='../index.php?page=stock-in'.($productId?'&product_id='.$productId:'').(Auth::isOwner()&&$branchId?'&branch='.$branchId:'');
if(!$productId||!$branchId){flash('error',!$productId?'Please choose a product variant first.':'Please select a valid stock location.');redirect($returnUrl);}

try{
    ensure_stock_in_schema_p1004();
    $pdo=Database::connection();$pdo->beginTransaction();
    $product=Database::query(
        "SELECT p.*,br.name brand_name,pm.name model_name,c.name category_name
         FROM products p LEFT JOIN brands br ON br.id=p.brand_id LEFT JOIN product_models pm ON pm.id=p.model_id LEFT JOIN categories c ON c.id=p.category_id
         WHERE p.id=? AND p.is_active=1 LIMIT 1",[$productId]
    )->fetch();
    if(!$product)throw new RuntimeException('The selected item is no longer available.');
    $branch=Database::query('SELECT id,name,code FROM branches WHERE id=? AND is_active=1 LIMIT 1',[$branchId])->fetch();
    if(!$branch)throw new RuntimeException('The selected branch is not available.');

    $cost=round(max(0,(float)($product['cost_price']??0)),2); // 0 means protected cost is still pending Owner review.
    $selling=branch_selling_price($productId,$branchId,(float)($product['selling_price']??0));
    if($canEditSelling && isset($_POST['selling_price'])){
        $postedSelling=round(max(0,(float)$_POST['selling_price']),2);
        if($postedSelling<=0) throw new RuntimeException('Enter a valid selling price.');
        save_branch_selling_price($productId,$branchId,$postedSelling,(int)Auth::user()['id']);
        $selling=$postedSelling;
    }
    if($selling<=0) throw new RuntimeException('Selling Price has not been set for this branch yet.');
    $notes=trim((string)($_POST['notes']??''));$notes=preg_replace('/\s+/',' ',$notes)??$notes;if(mb_strlen($notes)>180)$notes=mb_substr($notes,0,180);
    $userId=(int)Auth::user()['id'];$type=$product['product_type'];$reference=generate_stock_reference((string)$branch['code']);

    if($type==='accessory'){
        $quantity=max(1,min(100000,(int)($_POST['quantity']??1)));
        Database::query('INSERT INTO inventory_balances (product_id,branch_id,quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)',[$productId,$branchId,$quantity]);
        Database::query('INSERT INTO stock_movements (product_id,branch_id,movement_type,quantity,reference_no,notes,unit_cost,unit_selling_price,created_by) VALUES (?,?,?,?,?,?,?,?,?)',[$productId,$branchId,'stock_in',$quantity,$reference,stock_note($notes,'Accessory stock received'),$cost,$selling,$userId]);
    }else{
        $conditionType='brand_new';
        $isPreloved=false;
        $quantity=max(1,min(100,(int)($_POST['quantity']??1)));
        $identifiers=array_values(array_filter(array_map('normalize_stock_identifier',(array)($_POST['identifiers']??[])),fn($v)=>$v!==''));
        if(count($identifiers)!==$quantity)throw new RuntimeException('Please provide one unique identifier for every device unit.');
        if(count(array_unique($identifiers))!==count($identifiers))throw new RuntimeException('Duplicate identifiers were found in this receiving list.');

        $placeholders=implode(',',array_fill(0,count($identifiers),'?'));
        $params=array_merge($identifiers,$identifiers);
        $existing=Database::query("SELECT COALESCE(imei,serial_no) identifier_value FROM inventory_units WHERE imei IN ($placeholders) OR serial_no IN ($placeholders) LIMIT 1",$params)->fetch();
        if($existing)throw new RuntimeException('Identifier '.$existing['identifier_value'].' is already registered.');

        $conditionGrade=null;$battery=null;$isApple=strcasecmp(trim((string)($product['brand_name']??'')),'Apple')===0;

        $unitIds=[];
        foreach($identifiers as $identifier){
            [$imei,$serial]=stock_identifier_columns($type,$product['connectivity']??null,$identifier,$isApple);
            Database::query(
                'INSERT INTO inventory_units (product_id,branch_id,imei,serial_no,condition_type,condition_grade,battery_health,acquisition_cost,selling_price_snapshot,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$productId,$branchId,$imei,$serial,$conditionType,$conditionGrade,$battery,$cost,$selling,'available',$userId]
            );
            $unitIds[]=(int)$pdo->lastInsertId();
        }
        $device=$type==='tablet'?'Tablet':'Phone';$conditionText='Brand New ';
        Database::query(
            'INSERT INTO stock_movements (product_id,unit_id,branch_id,movement_type,quantity,reference_no,notes,unit_cost,unit_selling_price,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$productId,count($unitIds)===1?$unitIds[0]:null,$branchId,'stock_in',$quantity,$reference,stock_note($notes,$conditionText.$device.' stock received'),$cost,$selling,$userId]
        );
    }

    $pdo->commit();
    flash('stock_in_success',json_encode(['reference'=>$reference,'quantity'=>$quantity,'product'=>stock_action_product_label($product),'product_id'=>$productId,'branch'=>$branch['name'],'branch_id'=>$branchId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    redirect($returnUrl);
}catch(PDOException $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    if((string)$e->getCode()==='23000')flash('error','A duplicate IMEI / Serial Number or unique inventory record was detected. Nothing was added.');
    else flash('error','Unable to receive stock. Please check the item details and try again.');
    redirect($returnUrl);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect($returnUrl);}

function normalize_stock_identifier(mixed $value):string{return preg_replace('/\s+/','',trim((string)$value))??'';}
function ensure_stock_in_schema_p1004():void{
    $unitCost=Database::query("SHOW COLUMNS FROM inventory_units LIKE 'acquisition_cost'")->fetch();
    $condition=Database::query("SHOW COLUMNS FROM inventory_units LIKE 'condition_type'")->fetch();
    $connectivity=Database::query("SHOW COLUMNS FROM products LIKE 'connectivity'")->fetch();
    $movementCost=Database::query("SHOW COLUMNS FROM stock_movements LIKE 'unit_cost'")->fetch();
    if(!$unitCost||!$condition||!$connectivity||!$movementCost)throw new RuntimeException('Required inventory setup is missing before using Receive Stock.');
}
function stock_identifier_columns(string $type,?string $connectivity,string $identifier,bool $isApple):array{
    if($isApple)return[null,$identifier];
    if($type==='phone')return[$identifier,null];
    if($type==='tablet'&&$connectivity==='Wi-Fi')return[null,$identifier];
    return[$identifier,null];
}
function generate_stock_reference(string $branchCode):string{
    $branchCode=preg_replace('/[^A-Z0-9]/i','',strtoupper($branchCode))?:'BR';
    for($i=0;$i<8;$i++){$reference='STK-'.date('Ymd').'-'.$branchCode.'-'.str_pad((string)random_int(1,9999),4,'0',STR_PAD_LEFT);if(!Database::query('SELECT 1 FROM stock_movements WHERE reference_no=? LIMIT 1',[$reference])->fetchColumn())return$reference;}
    return'STK-'.date('Ymd-His').'-'.$branchCode;
}
function stock_note(string $userNote,string $default):string{$text=$userNote!==''?$userNote:$default;return mb_strlen($text)>255?mb_substr($text,0,255):$text;}
function stock_action_product_label(array $p):string{
    if(($p['product_type']??'')==='accessory')return trim((string)($p['product_name']??'Accessory'));
    $label=trim(($p['brand_name']??'').' '.($p['model_name']??''));$parts=[];foreach(['ram','storage','connectivity','color'] as $key)if(!empty($p[$key]))$parts[]=$p[$key];return trim($label.($parts?' • '.implode(' • ',$parts):''));
}

function create_receive_variant(array $input):array{
    if(!Database::query("SHOW COLUMNS FROM product_models LIKE 'device_type'")->fetch()) throw new RuntimeException('The device setup is not ready yet.');
    $modelId=filter_var($input['model_id']??null,FILTER_VALIDATE_INT)?:0;
    if(!$modelId) throw new RuntimeException('Choose a model first.');
    $model=Database::query(
        "SELECT pm.id,pm.name,pm.device_type,pm.is_active,b.id brand_id,b.name brand_name,b.is_active brand_active
         FROM product_models pm JOIN brands b ON b.id=pm.brand_id WHERE pm.id=? LIMIT 1",[$modelId]
    )->fetch();
    if(!$model || !(int)$model['is_active'] || !(int)$model['brand_active']) throw new RuntimeException('This model is not active.');

    $type=in_array($model['device_type'],['phone','tablet'],true)?$model['device_type']:'phone';
    $isApple=strcasecmp(trim((string)$model['brand_name']),'Apple')===0;
    $storage=normalize_receive_capacity($input['storage']??'');
    $ram=$isApple?null:normalize_receive_capacity($input['ram']??'');
    $color=($type==='phone'&&$isApple)?clean_receive_text($input['color']??'',80):null;
    $connectivity=$type==='tablet'?clean_receive_text($input['connectivity']??'',40):null;
    $selling=max(0,(float)($input['selling_price']??0));
    $requestedBranch=filter_var($input['branch_id']??null,FILTER_VALIDATE_INT)?:0;
    $priceBranchId=Auth::isOwner()?$requestedBranch:(Auth::branchId()?:0);

    if($storage==='') throw new RuntimeException('Select the storage.');
    if(!$isApple&&$ram==='') throw new RuntimeException('Select the RAM.');
    if($type==='phone'&&$isApple&&$color==='') throw new RuntimeException('Enter the color.');
    if($type==='tablet'&&!in_array($connectivity,['Wi-Fi','Wi-Fi + Cellular'],true)) throw new RuntimeException('Select the tablet connectivity.');
    if($selling<=0) throw new RuntimeException('Enter a valid selling price.');

    $existing=Database::query(
        "SELECT id,is_active,selling_price,cost_price FROM products
         WHERE product_type=? AND brand_id=? AND model_id=?
           AND COALESCE(ram,'')=COALESCE(?,'') AND storage=?
           AND COALESCE(color,'')=COALESCE(?,'') AND COALESCE(connectivity,'')=COALESCE(?,'')
         LIMIT 1",
        [$type,(int)$model['brand_id'],$modelId,$ram,$storage,$color,$connectivity]
    )->fetch();
    if($existing){
        if(!(int)$existing['is_active']) throw new RuntimeException('This variant already exists but is archived. Ask the Owner to restore it.');
        $existingId=(int)$existing['id'];
        $branchPrice=$priceBranchId?branch_selling_price($existingId,$priceBranchId,(float)$existing['selling_price']):(float)$existing['selling_price'];
        return receive_variant_payload($existingId,$type,$ram,$storage,$color,$connectivity,$branchPrice,(float)($existing['cost_price']??0));
    }

    $pdo=Database::connection();
    $started=!$pdo->inTransaction();
    if($started)$pdo->beginTransaction();
    try{
        Database::query(
            'INSERT INTO products (product_type,brand_id,model_id,ram,storage,color,connectivity,cost_price,selling_price,is_active) VALUES (?,?,?,?,?,?,?,?,?,1)',
            [$type,(int)$model['brand_id'],$modelId,$ram,$storage,$color,$connectivity,0,$selling]
        );
        $id=(int)$pdo->lastInsertId();
        if($priceBranchId>0 && branch_pricing_ready()) save_branch_selling_price($id,$priceBranchId,$selling,(int)Auth::user()['id']);
        if($started)$pdo->commit();
        return receive_variant_payload($id,$type,$ram,$storage,$color,$connectivity,$selling,0.0);
    }catch(Throwable $e){
        if($started&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
function receive_variant_payload(int $id,string $type,?string $ram,string $storage,?string $color,?string $connectivity,float $selling,float $cost=0.0):array{
    $parts=[];foreach([$ram,$storage,$connectivity,$color] as $v)if($v!==null&&$v!=='')$parts[]=$v;
    return ['id'=>$id,'type'=>$type,'specs'=>$parts?implode(' • ',$parts):'Standard','selling'=>$selling,'prices'=>[],'cost'=>$cost,'costReady'=>$cost>0];
}
function normalize_receive_capacity(mixed $value):string{
    $value=strtoupper(preg_replace('/\s+/','',trim((string)$value))??'');
    if($value==='')return'';
    if(preg_match('/^\d+(?:\.\d+)?$/',$value))return$value.'GB';
    if(preg_match('/^(\d+(?:\.\d+)?)G(?:B)?$/',$value,$m))return$m[1].'GB';
    if(preg_match('/^(\d+(?:\.\d+)?)T(?:B)?$/',$value,$m))return$m[1].'TB';
    return$value;
}
function clean_receive_text(mixed $value,int $max):string{
    $value=trim((string)$value);$value=preg_replace('/\s+/',' ',$value)??$value;return mb_substr($value,0,$max);
}

