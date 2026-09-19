-- Malbcoff Trading POS & Inventory System
-- Phase 1 / XAMPP MariaDB-MySQL compatible
CREATE DATABASE IF NOT EXISTS malbcoff_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE malbcoff_pos;

SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS inventory_balances;
DROP TABLE IF EXISTS inventory_units;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS product_models;
DROP TABLE IF EXISTS brands;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS branches;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE branches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(20) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','branch_manager','cashier','inventory') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

CREATE TABLE brands (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE product_models (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  brand_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_brand_model (brand_id,name),
  CONSTRAINT fk_models_brand FOREIGN KEY (brand_id) REFERENCES brands(id)
) ENGINE=InnoDB;

CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_type ENUM('phone','preloved','accessory') NOT NULL,
  brand_id INT UNSIGNED NULL,
  model_id INT UNSIGNED NULL,
  category_id INT UNSIGNED NULL,
  product_name VARCHAR(180) NULL,
  ram VARCHAR(40) NULL,
  storage VARCHAR(40) NULL,
  color VARCHAR(80) NULL,
  barcode VARCHAR(120) NULL,
  cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  low_stock_threshold INT NOT NULL DEFAULT 5,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_product_type(product_type),
  INDEX idx_product_barcode(barcode),
  CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands(id),
  CONSTRAINT fk_products_model FOREIGN KEY (model_id) REFERENCES product_models(id),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

CREATE TABLE inventory_units (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  imei VARCHAR(80) NOT NULL UNIQUE,
  serial_no VARCHAR(120) NULL UNIQUE,
  condition_grade VARCHAR(30) NULL,
  battery_health TINYINT UNSIGNED NULL,
  status ENUM('available','reserved','sold','defective','transferred','returned') NOT NULL DEFAULT 'available',
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_units_branch_status(branch_id,status),
  CONSTRAINT fk_units_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_units_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
  CONSTRAINT fk_units_user FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT chk_battery_health CHECK (battery_health IS NULL OR battery_health BETWEEN 1 AND 100)
) ENGINE=InnoDB;

CREATE TABLE inventory_balances (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_balance_product_branch(product_id,branch_id),
  CONSTRAINT fk_balance_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_balance_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

CREATE TABLE stock_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NULL,
  branch_id INT UNSIGNED NOT NULL,
  movement_type ENUM('stock_in','stock_out','sale','transfer_in','transfer_out','adjustment','return','defective') NOT NULL,
  quantity INT NOT NULL,
  reference_no VARCHAR(100) NULL,
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_movements_branch_date(branch_id,created_at),
  INDEX idx_movements_type(movement_type),
  CONSTRAINT fk_movements_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_movements_unit FOREIGN KEY (unit_id) REFERENCES inventory_units(id),
  CONSTRAINT fk_movements_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
  CONSTRAINT fk_movements_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT INTO branches(name,code) VALUES
('Branch 1','B1'),('Branch 2','B2'),('Branch 3','B3'),('Branch 4','B4');

INSERT INTO users(branch_id,name,email,password_hash,role) VALUES
(NULL,'Malbcoff Owner','owner@malbcoff.local','$2y$12$1jkavOcYHgeXt5gjuogrZepegN7zY3DuJwasrDZ49z5j.QQhcxvTu','owner'),
(1,'Branch 1 User','branch1@malbcoff.local','$2y$12$PyncbYymfzFxunhB05r4v.r7Xk797DaH2tD.LxVTSaNmyeO5q8czW','branch_manager'),
(2,'Branch 2 User','branch2@malbcoff.local','$2y$12$PyncbYymfzFxunhB05r4v.r7Xk797DaH2tD.LxVTSaNmyeO5q8czW','branch_manager'),
(3,'Branch 3 User','branch3@malbcoff.local','$2y$12$PyncbYymfzFxunhB05r4v.r7Xk797DaH2tD.LxVTSaNmyeO5q8czW','branch_manager'),
(4,'Branch 4 User','branch4@malbcoff.local','$2y$12$PyncbYymfzFxunhB05r4v.r7Xk797DaH2tD.LxVTSaNmyeO5q8czW','branch_manager');

INSERT INTO brands(name) VALUES
('Apple'),('Honor'),('Infinix'),('Itel'),('Nubia'),('Oppo'),('Poco'),('Realme'),('Samsung'),('Tecno'),('Vivo'),('Xiaomi');

INSERT INTO product_models(brand_id,name)
SELECT id,'iPhone 17' FROM brands WHERE name='Apple' UNION ALL
SELECT id,'iPhone 17 Pro' FROM brands WHERE name='Apple' UNION ALL
SELECT id,'iPhone 17 Pro Max' FROM brands WHERE name='Apple' UNION ALL
SELECT id,'Galaxy A55 5G' FROM brands WHERE name='Samsung' UNION ALL
SELECT id,'Galaxy S24 Ultra' FROM brands WHERE name='Samsung' UNION ALL
SELECT id,'Redmi Note 13' FROM brands WHERE name='Xiaomi' UNION ALL
SELECT id,'Xiaomi 14T Pro' FROM brands WHERE name='Xiaomi' UNION ALL
SELECT id,'Hot 40 Pro' FROM brands WHERE name='Infinix' UNION ALL
SELECT id,'Camon 20' FROM brands WHERE name='Tecno' UNION ALL
SELECT id,'Reno 11 5G' FROM brands WHERE name='Oppo' UNION ALL
SELECT id,'V30 5G' FROM brands WHERE name='Vivo' UNION ALL
SELECT id,'12 Pro+ 5G' FROM brands WHERE name='Realme' UNION ALL
SELECT id,'Honor 90' FROM brands WHERE name='Honor' UNION ALL
SELECT id,'X6 Pro 5G' FROM brands WHERE name='Poco' UNION ALL
SELECT id,'P55' FROM brands WHERE name='Itel' UNION ALL
SELECT id,'RedMagic 9 Pro' FROM brands WHERE name='Nubia';

INSERT INTO categories(name) VALUES
('Cases'),('Chargers'),('Cables'),('Earphones / Headsets'),('Power Banks'),('Screen Protectors'),('Adapters'),('Other Accessories');
