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
    $productId=filter_var($_GET['product_id']??null,FILTER_VALIDATE_INT)?:0;
    $requestedBranch=filter_var($_GET['branch_id']??null,FILTER_VALIDATE_INT)?:0;
    $branchId=Auth::isOwner()?$requestedBranch:(Auth::branchId()?:0);
    try{
        $unit=Database::query(
            "SELECT iu.id,iu.product_id,iu.branch_id,iu.status,iu.imei,iu.imei2,iu.serial_no,br.name branch_name,
                    p.model_id,p.product_type,pm.name model_name,b.name brand_name
             FROM inventory_units iu
             JOIN products p ON p.id=iu.product_id
             LEFT JOIN product_models pm ON pm.id=p.model_id
             LEFT JOIN brands b ON b.id=p.brand_id
             LEFT JOIN branches br ON br.id=iu.branch_id
             WHERE iu.imei=? OR iu.imei2=? OR iu.serial_no=? LIMIT 1",
            [$value,$value,$value]
        )->fetch();
        if(!$unit){echo json_encode(['exists'=>false]);exit;}

        $matchedField=normalize_stock_identifier($unit['serial_no']??'')===$value?'serial':(normalize_stock_identifier($unit['imei']??'')===$value?'imei1':'imei2');
        $response=['exists'=>true,'status'=>$unit['status']??'','restorable'=>false,'unit_id'=>(int)$unit['id'],'branch_name'=>$unit['branch_name']??'','matched_field'=>$matchedField];
        if(($unit['status']??'')==='adjusted_out'){
            if($matchedField==='imei2'){
                $response['message']='This is IMEI 2 of a previously removed phone. Enter its IMEI 1 to restore the unit.';
                echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                exit;
            }
            $lastAdjustment=Database::query(
                "SELECT notes,reference_no,quantity FROM stock_movements
                 WHERE unit_id=? AND movement_type='adjustment' AND quantity<0
                 ORDER BY id DESC LIMIT 1",
                [(int)$unit['id']]
            )->fetch();
            $adjustmentLabel=restore_adjustment_label((string)($lastAdjustment['notes']??''));
            $response['adjustment_reason']=$adjustmentLabel;
            $response['adjustment_label']=$adjustmentLabel;
            if(!$lastAdjustment){
                $response['message']='This unit is marked removed, but its removal record is missing. Ask the Owner to review it.';
            }elseif(!$branchId){
                $response['restore_requires_branch']=true;
            }elseif($productId>0){
                $target=Database::query('SELECT id,model_id,product_type FROM products WHERE id=? AND is_active=1 LIMIT 1',[$productId])->fetch();
                $sameModel=$target && (int)$target['model_id']===(int)$unit['model_id'] && (string)$target['product_type']===(string)$unit['product_type'];
                $branchAllowed=(int)$unit['branch_id']===$branchId;
                if($sameModel && $branchAllowed){
                    $response['restorable']=true;
                    $response['message']='Previously removed'.($adjustmentLabel!==''?' — '.$adjustmentLabel:'').'. This unit can be restored.';
                }elseif(!$sameModel){
                    $response['message']='This identifier belongs to another model and cannot be restored here.';
                }else{
                    $response['message']='This unit belongs to another branch.';
                }
            }
        }
        echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
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
        $isApple=strcasecmp(trim((string)($product['brand_name']??'')),'Apple')===0;
        $dualImeiPhone=(!$isApple && $type==='phone');
        $requiresImei=(!$isApple && ($type==='phone' || ($type==='tablet' && stripos((string)($product['connectivity']??''),'Cellular')!==false)));

        $primaryRaw=(array)($_POST['identifiers']??[]);
        $secondaryRaw=(array)($_POST['secondary_identifiers']??[]);
        $identifiers=[];$secondaryIdentifiers=[];
        for($i=0;$i<$quantity;$i++){
            $primary=normalize_stock_identifier($primaryRaw[$i]??'');
            $secondary=$dualImeiPhone?normalize_stock_identifier($secondaryRaw[$i]??''):'';
            if($primary==='') throw new RuntimeException('Please provide IMEI 1 / Serial Number for every device unit.');
            if($requiresImei && !preg_match('/^\d{15}$/',$primary)) throw new RuntimeException('IMEI 1 must be exactly 15 digits.');
            if($requiresImei && !stock_valid_imei($primary)) throw new RuntimeException('IMEI 1 is not valid. Scan the IMEI barcode again.');
            if($dualImeiPhone && $secondary!=='' && !preg_match('/^\d{15}$/',$secondary)) throw new RuntimeException('IMEI 2 must be exactly 15 digits when provided.');
            if($dualImeiPhone && $secondary!=='' && !stock_valid_imei($secondary)) throw new RuntimeException('IMEI 2 is not valid. Scan the IMEI barcode again.');
            if($secondary!=='' && $secondary===$primary) throw new RuntimeException('IMEI 1 and IMEI 2 cannot be the same for the same unit.');
            $identifiers[]=$primary;
            $secondaryIdentifiers[]=$secondary;
        }

        $allIdentifiers=$identifiers;
        foreach($secondaryIdentifiers as $secondary)if($secondary!=='')$allIdentifiers[]=$secondary;
        if(count(array_unique($allIdentifiers))!==count($allIdentifiers))throw new RuntimeException('Duplicate IMEI / Serial Number values were found in this receiving list.');

        $placeholders=implode(',',array_fill(0,count($allIdentifiers),'?'));
        $params=array_merge($allIdentifiers,$allIdentifiers,$allIdentifiers);
        $existingRows=Database::query(
            "SELECT iu.*,p.model_id old_model_id,p.product_type old_product_type
             FROM inventory_units iu
             JOIN products p ON p.id=iu.product_id
             WHERE iu.imei IN ($placeholders) OR iu.imei2 IN ($placeholders) OR iu.serial_no IN ($placeholders)
             FOR UPDATE",
            $params
        )->fetchAll();
        $existingByIdentifier=[];
        foreach($existingRows as $row){
            foreach(['imei','imei2','serial_no'] as $column){
                $key=normalize_stock_identifier($row[$column]??'');
                if($key!=='')$existingByIdentifier[$key]=$row;
            }
        }

        $conditionGrade=null;$battery=null;
        $newUnitIds=[];$restoredUnitIds=[];
        foreach($identifiers as $index=>$identifier){
            $secondaryIdentifier=$secondaryIdentifiers[$index]??'';
            $existing=$existingByIdentifier[$identifier]??null;
            $secondaryExisting=$secondaryIdentifier!==''?($existingByIdentifier[$secondaryIdentifier]??null):null;

            if($secondaryExisting && (!$existing || (int)$secondaryExisting['id']!==(int)$existing['id'])){
                throw new RuntimeException('IMEI 2 '.$secondaryIdentifier.' is already registered to another unit.');
            }

            if($existing){
                if(($existing['status']??'')!=='adjusted_out') throw new RuntimeException('Identifier '.$identifier.' is already registered.');
                if($dualImeiPhone && normalize_stock_identifier($existing['imei']??'')!==$identifier){
                    throw new RuntimeException('Use IMEI 1 to restore this Android phone. IMEI 2 is only the secondary identifier.');
                }
                if($dualImeiPhone && $secondaryIdentifier!=='' && !empty($existing['imei2']) && normalize_stock_identifier($existing['imei2'])!==$secondaryIdentifier){
                    throw new RuntimeException('IMEI 2 does not match the previously registered unit.');
                }

                $lastAdjustment=Database::query(
                    "SELECT notes,reference_no FROM stock_movements
                     WHERE unit_id=? AND movement_type='adjustment' AND quantity<0
                     ORDER BY id DESC LIMIT 1",
                    [(int)$existing['id']]
                )->fetch();
                if(!$lastAdjustment) throw new RuntimeException('Identifier '.$identifier.' is marked removed, but its removal record is missing. Ask the Owner to review it.');
                $previousReason=restore_adjustment_label((string)($lastAdjustment['notes']??''));
                if((int)$existing['old_model_id']!==(int)$product['model_id'] || (string)$existing['old_product_type']!==(string)$type){
                    throw new RuntimeException('Identifier '.$identifier.' belongs to another model and cannot be restored to this variant.');
                }
                if((int)$existing['branch_id']!==$branchId){
                    throw new RuntimeException('Identifier '.$identifier.' was removed from another branch. Restore it to its original branch first.');
                }

                $imei2ForRestore=$dualImeiPhone
                    ? ($secondaryIdentifier!==''?$secondaryIdentifier:($existing['imei2']??null))
                    : ($existing['imei2']??null);
                $updated=Database::query(
                    "UPDATE inventory_units
                     SET product_id=?,branch_id=?,imei2=?,status='available',selling_price_snapshot=?
                     WHERE id=? AND status='adjusted_out'",
                    [$productId,$branchId,$imei2ForRestore?:null,$selling,(int)$existing['id']]
                );
                if($updated->rowCount()!==1) throw new RuntimeException('Identifier '.$identifier.' changed while receiving stock. Refresh and try again.');
                $restoreRef=generate_restore_reference((string)$branch['code']);
                $restoreNote='Inventory Restore'.($previousReason!==''?' — Previous: '.$previousReason:'').($notes!==''?' — '.$notes:'');
                Database::query(
                    'INSERT INTO stock_movements (product_id,unit_id,branch_id,movement_type,quantity,reference_no,notes,unit_cost,unit_selling_price,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$productId,(int)$existing['id'],$branchId,'adjustment',1,$restoreRef,stock_note($restoreNote,'Inventory Restore'),(float)($existing['acquisition_cost']??0),$selling,$userId]
                );
                $restoredUnitIds[]=(int)$existing['id'];
                continue;
            }

            [$imei,$serial]=stock_identifier_columns($type,$product['connectivity']??null,$identifier,$isApple);
            $imei2=$dualImeiPhone && $secondaryIdentifier!==''?$secondaryIdentifier:null;
            Database::query(
                'INSERT INTO inventory_units (product_id,branch_id,imei,imei2,serial_no,condition_type,condition_grade,battery_health,acquisition_cost,selling_price_snapshot,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$productId,$branchId,$imei,$imei2,$serial,$conditionType,$conditionGrade,$battery,$cost,$selling,'available',$userId]
            );
            $newUnitIds[]=(int)$pdo->lastInsertId();
        }
        $device=$type==='tablet'?'Tablet':'Phone';$conditionText='Brand New ';
        if($newUnitIds){
            Database::query(
                'INSERT INTO stock_movements (product_id,unit_id,branch_id,movement_type,quantity,reference_no,notes,unit_cost,unit_selling_price,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$productId,count($newUnitIds)===1?$newUnitIds[0]:null,$branchId,'stock_in',count($newUnitIds),$reference,stock_note($notes,$conditionText.$device.' stock received'),$cost,$selling,$userId]
            );
        }
        $restoredCount=count($restoredUnitIds);
    }

    $pdo->commit();
    flash('stock_in_success',json_encode(['reference'=>$reference,'quantity'=>$quantity,'restored'=>$restoredCount??0,'product'=>stock_action_product_label($product),'product_id'=>$productId,'branch'=>$branch['name'],'branch_id'=>$branchId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    redirect($returnUrl);
}catch(PDOException $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    if((string)$e->getCode()==='23000')flash('error','A duplicate IMEI 1, IMEI 2, Serial Number, or unique inventory record was detected. Nothing was added.');
    else flash('error','Unable to receive stock. Please check the item details and try again.');
    redirect($returnUrl);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect($returnUrl);}

function restore_adjustment_label(string $notes):string{
    $notes=trim($notes);
    if($notes==='') return 'Previous adjustment';
    $first=trim((string)preg_split('/\s+[—-]\s+/u',$notes,2)[0]);
    $map=[
        'stock correction'=>'Stock Correction',
        'missing'=>'Missing / Found Again',
        'other'=>'Other Adjustment',
    ];
    $key=strtolower($first);
    return $map[$key]??($first!==''?$first:'Previous adjustment');
}

function normalize_stock_identifier(mixed $value):string{$value=preg_replace('/\s+/','',trim((string)$value))??'';return function_exists('mb_strtoupper')?mb_strtoupper($value,'UTF-8'):strtoupper($value);}
function stock_valid_imei(string $value):bool{
    if(!preg_match('/^\d{15}$/',$value))return false;
    $sum=0;
    for($i=0;$i<14;$i++){
        $digit=(int)$value[$i];
        if($i%2===1){$digit*=2;if($digit>9)$digit-=9;}
        $sum+=$digit;
    }
    return ((10-($sum%10))%10)===(int)$value[14];
}
function ensure_stock_in_schema_p1004():void{
    $unitCost=Database::query("SHOW COLUMNS FROM inventory_units LIKE 'acquisition_cost'")->fetch();
    $condition=Database::query("SHOW COLUMNS FROM inventory_units LIKE 'condition_type'")->fetch();
    $connectivity=Database::query("SHOW COLUMNS FROM products LIKE 'connectivity'")->fetch();
    $movementCost=Database::query("SHOW COLUMNS FROM stock_movements LIKE 'unit_cost'")->fetch();
    $imei2=Database::query("SHOW COLUMNS FROM inventory_units LIKE 'imei2'")->fetch();
    if(!$unitCost||!$condition||!$connectivity||!$movementCost)throw new RuntimeException('Required inventory setup is missing before using Receive Stock.');
    if(!$imei2)throw new RuntimeException('Run database/P2_023_dual_imei_support.sql before receiving Android phones.');
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
function generate_restore_reference(string $branchCode):string{
    $branchCode=preg_replace('/[^A-Z0-9]/i','',strtoupper($branchCode))?:'BR';
    for($i=0;$i<8;$i++){$reference='RST-'.date('Ymd').'-'.$branchCode.'-'.str_pad((string)random_int(1,9999),4,'0',STR_PAD_LEFT);if(!Database::query('SELECT 1 FROM stock_movements WHERE reference_no=? LIMIT 1',[$reference])->fetchColumn())return$reference;}
    return'RST-'.date('Ymd-His').'-'.$branchCode;
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
    $color=($type==='phone'&&$isApple)?clean_receive_text($input['color']??'',80,true):null;
    $connectivity=$type==='tablet'?clean_receive_text($input['connectivity']??'',40,false):null;
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
function clean_receive_text(mixed $value,int $max,bool $uppercase=true):string{
    $value=trim((string)$value);$value=preg_replace('/\s+/',' ',$value)??$value;
    if($uppercase)$value=function_exists('mb_strtoupper')?mb_strtoupper($value,'UTF-8'):strtoupper($value);
    return mb_substr($value,0,$max);
}

