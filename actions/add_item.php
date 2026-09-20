<?php
require __DIR__ . '/../bootstrap.php';

if (!Auth::check()) redirect('../login.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('../index.php?page=add-item');
if (!Csrf::verify($_POST['_csrf'] ?? null)) { flash('error','Your session expired. Please submit the form again.'); redirect('../index.php?page=add-item'); }

$role = Auth::user()['role'] ?? '';
if (!in_array($role, ['owner','branch_manager','inventory'], true)) { flash('error','Your account does not have permission to manage Products.'); redirect('../index.php?page=products'); }

$type = strtolower(trim((string)($_POST['product_type'] ?? 'phone')));
$nextAction = ($_POST['next_action'] ?? 'products') === 'receive' ? 'receive' : 'products';
if (!in_array($type,['phone','tablet','accessory'],true)) $type='phone';
$selling = round(max(0,(float)($_POST['selling_price'] ?? 0)),2);
$cost = Auth::isOwner() ? round(max(0,(float)($_POST['cost_price'] ?? 0)),2) : 0.0;
if ($selling <= 0) { flash('error','Enter a valid selling price.'); redirect('../index.php?page=add-item'); }

try {
    ensure_configuration_schema();
    $pdo=Database::connection();$pdo->beginTransaction();

    if ($type==='accessory') {
        $categoryId=filter_var($_POST['category_id']??null,FILTER_VALIDATE_INT)?:0;
        $name=clean_config_text($_POST['product_name']??'',180,true);
        $barcode=clean_config_text($_POST['barcode']??'',120,false) ?: null;
        if(!$categoryId||$name==='') throw new RuntimeException('Category and product name are required.');
        if(!Database::query('SELECT 1 FROM categories WHERE id=? AND is_active=1 LIMIT 1',[$categoryId])->fetchColumn()) throw new RuntimeException('Please select an active accessory category.');
        $existing=Database::query("SELECT id,is_active FROM products WHERE product_type='accessory' AND category_id=? AND LOWER(product_name)=LOWER(?) LIMIT 1",[$categoryId,$name])->fetch();
        if(!$existing&&$barcode)$existing=Database::query("SELECT id,is_active FROM products WHERE product_type='accessory' AND barcode=? LIMIT 1",[$barcode])->fetch();
        if($existing) throw new RuntimeException((int)$existing['is_active']?'This accessory product already exists in Product Master.':'This accessory product already exists but is archived. Ask the Owner to restore it.');
        Database::query("INSERT INTO products (product_type,category_id,product_name,barcode,cost_price,selling_price,is_active) VALUES ('accessory',?,?,?,?,?,1)",[$categoryId,$name,$barcode,$cost,$selling]);
        $productId=(int)$pdo->lastInsertId();
        seed_new_product_branch_prices($productId,$selling);
        $pdo->commit();
        if ($nextAction === 'receive') { flash('success','Accessory saved. Enter the quantity that arrived.'); redirect('../index.php?page=stock-in&product_id='.$productId); }
        flash('success','Accessory product saved to Products.');redirect('../index.php?page=products&view=accessories');
    }

    $brandId=filter_var($_POST['brand_id']??null,FILTER_VALIDATE_INT)?:0;
    $modelId=filter_var($_POST['model_id']??null,FILTER_VALIDATE_INT)?:0;
    $storage=normalize_capacity_config($_POST['storage']??'');
    if(!$brandId||!$modelId||$storage==='') throw new RuntimeException('Brand, model and storage are required.');

    $model=Database::query("SELECT pm.id,pm.brand_id,pm.name,pm.device_type,pm.is_active,b.name brand_name,b.is_active brand_active FROM product_models pm JOIN brands b ON b.id=pm.brand_id WHERE pm.id=? LIMIT 1",[$modelId])->fetch();
    if(!$model||(int)$model['brand_id']!==$brandId||!(int)$model['is_active']||!(int)$model['brand_active']) throw new RuntimeException('Please select an active model under the selected brand.');
    if(($model['device_type']??'phone')!==$type) throw new RuntimeException('The selected model belongs to a different device type.');

    $isApple=strcasecmp(trim((string)$model['brand_name']),'Apple')===0;
    $ram=$isApple?null:normalize_capacity_config($_POST['ram']??'');
    $color=clean_config_text($_POST['color']??'',80,true);
    $connectivity=$type==='tablet'?clean_config_text($_POST['connectivity']??'',40,false):null;
    if(!$isApple&&$ram==='') throw new RuntimeException('RAM is required for Android devices.');
    if($color==='') throw new RuntimeException('Color is required for phone and tablet variants.');
    if($type==='tablet'&&!in_array($connectivity,['Wi-Fi','Wi-Fi + Cellular'],true)) throw new RuntimeException('Please select tablet connectivity.');

    $existing=Database::query("SELECT id,is_active FROM products WHERE product_type=? AND brand_id=? AND model_id=? AND COALESCE(ram,'')=COALESCE(?,'') AND storage=? AND COALESCE(color,'')=COALESCE(?,'') AND COALESCE(connectivity,'')=COALESCE(?,'') LIMIT 1",[$type,$brandId,$modelId,$ram,$storage,$color,$connectivity])->fetch();
    if($existing) throw new RuntimeException((int)$existing['is_active']?'This variant already exists. Use Receive Stock when physical units arrive.':'This variant already exists but is archived. Ask the Owner to restore it.');

    Database::query('INSERT INTO products (product_type,brand_id,model_id,ram,storage,color,connectivity,cost_price,selling_price,is_active) VALUES (?,?,?,?,?,?,?,?,?,1)',[$type,$brandId,$modelId,$ram,$storage,$color,$connectivity,$cost,$selling]);
    $productId=(int)$pdo->lastInsertId();
    seed_new_product_branch_prices($productId,$selling);
    $pdo->commit();
    if ($nextAction === 'receive') {
        flash('success','Variant saved. Enter how many units arrived and add their Serial Numbers / IMEIs.');
        redirect('../index.php?page=stock-in&product_id='.$productId);
    }
    flash('success','Variant saved. No physical stock was added.');
    redirect('../index.php?page=products&view=devices&brand='.$brandId.'&model='.$modelId.'#variants');
} catch(Throwable $e) {
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    flash('error',$e->getMessage());
    redirect('../index.php?page=add-item'.(!empty($_POST['model_id'])?'&model_id='.(int)$_POST['model_id']:''));
}


function seed_new_product_branch_prices(int $productId,float $selling):void{
    $userId=(int)(Auth::user()['id']??0);
    if(Auth::isOwner()){
        $branches=Database::query('SELECT id FROM branches WHERE is_active=1 ORDER BY id')->fetchAll();
        foreach($branches as $branch) save_branch_selling_price($productId,(int)$branch['id'],$selling,$userId?:null);
        return;
    }
    $branchId=Auth::branchId()?:0;
    if($branchId>0) save_branch_selling_price($productId,$branchId,$selling,$userId?:null);
}

function ensure_configuration_schema():void{
    if(!Database::query("SHOW COLUMNS FROM products LIKE 'connectivity'")->fetch()||!Database::query("SHOW COLUMNS FROM product_models LIKE 'device_type'")->fetch()) throw new RuntimeException('The device setup is required before creating variants.');
    if(!branch_pricing_ready()) throw new RuntimeException('Run database/P2_004_pricing_variant_serial_ux.sql before creating variants.');
}
function clean_config_text(mixed $value,int $max,bool $uppercase=true):string{$value=trim((string)$value);$value=preg_replace('/\s+/',' ',$value)??$value;if($uppercase)$value=function_exists('mb_strtoupper')?mb_strtoupper($value,'UTF-8'):strtoupper($value);return mb_substr($value,0,$max);}
function normalize_capacity_config(mixed $value):string{$value=strtoupper(preg_replace('/\s+/','',trim((string)$value))??'');if($value==='')return'';if(preg_match('/^\d+(?:\.\d+)?$/',$value))return$value.'GB';if(preg_match('/^(\d+(?:\.\d+)?)G(?:B)?$/',$value,$m))return$m[1].'GB';if(preg_match('/^(\d+(?:\.\d+)?)T(?:B)?$/',$value,$m))return$m[1].'TB';return$value;}
