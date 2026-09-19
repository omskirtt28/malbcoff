USE malbcoff_pos;
-- Optional demo inventory. Import only if you want sample dashboard data.
INSERT INTO products(product_type,brand_id,model_id,storage,color,barcode,cost_price,selling_price)
SELECT 'phone',b.id,m.id,'256GB','Black Titanium','880000000001',59000,72990 FROM brands b JOIN product_models m ON m.brand_id=b.id WHERE b.name='Apple' AND m.name='iPhone 17 Pro Max' LIMIT 1;
SET @iphone = LAST_INSERT_ID();
INSERT INTO inventory_units(product_id,branch_id,imei,status,created_by) VALUES
(@iphone,1,'351234567890101','available',1),(@iphone,1,'351234567890102','available',1),(@iphone,2,'351234567890103','available',1),(@iphone,3,'351234567890104','available',1),(@iphone,4,'351234567890105','available',1);
INSERT INTO stock_movements(product_id,unit_id,branch_id,movement_type,quantity,reference_no,created_by)
SELECT @iphone,id,branch_id,'stock_in',1,'DEMO-SEED',1 FROM inventory_units WHERE product_id=@iphone;

INSERT INTO products(product_type,category_id,product_name,barcode,cost_price,selling_price)
SELECT 'accessory',id,'20W USB-C Charger','880000000101',450,790 FROM categories WHERE name='Chargers' LIMIT 1;
SET @charger = LAST_INSERT_ID();
INSERT INTO inventory_balances(product_id,branch_id,quantity) VALUES (@charger,1,25),(@charger,2,18),(@charger,3,20),(@charger,4,22);
INSERT INTO stock_movements(product_id,branch_id,movement_type,quantity,reference_no,created_by) VALUES
(@charger,1,'stock_in',25,'DEMO-SEED',1),(@charger,2,'stock_in',18,'DEMO-SEED',1),(@charger,3,'stock_in',20,'DEMO-SEED',1),(@charger,4,'stock_in',22,'DEMO-SEED',1);
