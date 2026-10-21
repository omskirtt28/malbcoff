<?php
putenv('DB_NAME=malbcoff_fix_20261020_v2');
require __DIR__.'/../../core/Database.php';
$f=json_decode(file_get_contents(__DIR__.'/fixtures.json'),true);
$ids=implode(',',array_values($f['products']));
echo json_encode([
'units'=>Database::query("SELECT * FROM inventory_units WHERE product_id IN ($ids)")->fetchAll(),
'movements'=>Database::query("SELECT * FROM stock_movements WHERE product_id IN ($ids)")->fetchAll(),
'audits'=>Database::query('SELECT * FROM inventory_unit_delete_audits')->fetchAll(),
'sales'=>Database::query("SELECT si.*,s.status sale_status FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE si.product_id IN ($ids)")->fetchAll(),
]);
