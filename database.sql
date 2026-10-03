-- =====================================================================
--  EXECOM Logistics — POS & Inventory System
--  Database schema + sample data  (MySQL 5.7+ / MariaDB 10.4+)
--
--  WARNING: re-importing this file DROPS and recreates every table
--  in execomlogistics_db. Back up first if you have live data.
--
--  Default logins (change them after first sign-in):
--    admin   / admin123    (role: super_admin, branch MAR)
--    cashier / cashier123  (role: cashier,     branch MAR)
--
--  Existing installs: don't re-import; apply migrations/ (002, 003) instead.
--  This file = Phase 1-4 schema + migrations 002 and 003.
-- =====================================================================

-- Silence the harmless "database exists" / "unknown table" notes that
-- IF [NOT] EXISTS produces, so the import reports 0 warnings.
SET @OLD_SQL_NOTES = @@SQL_NOTES, SQL_NOTES = 0;

CREATE DATABASE IF NOT EXISTS execomlogistics_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE execomlogistics_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS stock_balances;
DROP TABLE IF EXISTS sale_items;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS customer_branches;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS storage_locations;
DROP TABLE IF EXISTS warehouses;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS user_branches;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS branches;
SET FOREIGN_KEY_CHECKS = 1;
SET SQL_NOTES = @OLD_SQL_NOTES;

-- ---------------------------------------------------------------------
-- Branches  (MAR = main store; address/contact/TIN filled in by the owner)
-- ---------------------------------------------------------------------
CREATE TABLE branches (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code             VARCHAR(10)  NOT NULL,
  name             VARCHAR(100) NOT NULL,
  address          VARCHAR(255) NULL,
  contact_no       VARCHAR(50)  NULL,
  tin_branch_code  VARCHAR(30)  NULL,
  is_main          TINYINT(1)   NOT NULL DEFAULT 0,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_branches_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Roles & permissions  (roles.is_super = every permission, no rows needed)
-- ---------------------------------------------------------------------
CREATE TABLE roles (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(30)  NOT NULL,
  name         VARCHAR(60)  NOT NULL,
  description  VARCHAR(255) NULL,
  is_system    TINYINT(1)   NOT NULL DEFAULT 0,
  is_super     TINYINT(1)   NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  perm_key    VARCHAR(50)  NOT NULL,
  module      VARCHAR(30)  NOT NULL,
  label       VARCHAR(120) NOT NULL,
  sort_order  SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_key (perm_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id        INT UNSIGNED NOT NULL,
  permission_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  KEY idx_role_permissions_permission (permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Users & authentication  (role = roles.code, branch_id = home branch)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username       VARCHAR(50)  NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  full_name      VARCHAR(100) NOT NULL,
  role           VARCHAR(30)  NOT NULL,
  branch_id      INT UNSIGNED NOT NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at  DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_role (role),
  KEY idx_users_branch (branch_id),
  CONSTRAINT fk_users_role FOREIGN KEY (role) REFERENCES roles (code)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extra branches granted to a user (the home branch is users.branch_id).
CREATE TABLE user_branches (
  user_id     INT UNSIGNED NOT NULL,
  branch_id   INT UNSIGNED NOT NULL,
  granted_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, branch_id),
  KEY idx_user_branches_branch (branch_id),
  KEY idx_user_branches_granted_by (granted_by),
  CONSTRAINT fk_user_branches_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_user_branches_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_user_branches_granted_by FOREIGN KEY (granted_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Brute-force protection: failed logins are counted per username+IP.
CREATE TABLE login_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(50)  NOT NULL,
  ip_address    VARCHAR(45)  NOT NULL,
  success       TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_attempts_ip_time (ip_address, attempted_at),
  KEY idx_attempts_user_ip (username, ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Catalog
-- ---------------------------------------------------------------------
CREATE TABLE categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(50)  NOT NULL,
  slug        VARCHAR(50)  NOT NULL,
  icon        VARCHAR(30)  NOT NULL DEFAULT 'grid',
  sort_order  SMALLINT     NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  category_id    INT UNSIGNED  NOT NULL,
  code           VARCHAR(20)   NOT NULL,
  barcode        VARCHAR(50)   NULL,
  name           VARCHAR(100)  NOT NULL,
  description    VARCHAR(255)  NULL,
  price          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  stock          INT           NOT NULL DEFAULT 0,
  reorder_level  INT           NOT NULL DEFAULT 5,
  image          VARCHAR(255)  NULL,
  is_active      TINYINT(1)    NOT NULL DEFAULT 1,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_code (code),
  UNIQUE KEY uq_products_barcode (barcode),
  KEY idx_products_category (category_id),
  KEY idx_products_name (name),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_products_price CHECK (price >= 0),
  CONSTRAINT chk_products_stock CHECK (stock >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Branch stock: branch -> warehouse -> storage location -> balance.
--   products.stock stays the company total (= SUM(stock_balances.qty)).
--   The composite keys keep (location, warehouse, branch) consistent.
-- ---------------------------------------------------------------------
CREATE TABLE warehouses (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id   INT UNSIGNED NOT NULL,
  code        VARCHAR(20)  NOT NULL,
  name        VARCHAR(100) NOT NULL,
  is_default  TINYINT(1)   NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_warehouses_branch_code (branch_id, code),
  UNIQUE KEY uq_warehouses_id_branch (id, branch_id),
  CONSTRAINT fk_warehouses_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE storage_locations (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  warehouse_id  INT UNSIGNED NOT NULL,
  branch_id     INT UNSIGNED NOT NULL,
  code          VARCHAR(20)  NOT NULL,
  name          VARCHAR(100) NOT NULL,
  is_sellable   TINYINT(1)   NOT NULL DEFAULT 1,
  is_default    TINYINT(1)   NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_locations_warehouse_code (warehouse_id, code),
  UNIQUE KEY uq_locations_id_wh_branch (id, warehouse_id, branch_id),
  KEY idx_locations_wh_branch (warehouse_id, branch_id),
  CONSTRAINT fk_locations_warehouse FOREIGN KEY (warehouse_id, branch_id) REFERENCES warehouses (id, branch_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_balances (
  product_id    INT UNSIGNED NOT NULL,
  location_id   INT UNSIGNED NOT NULL,
  warehouse_id  INT UNSIGNED NOT NULL,
  branch_id     INT UNSIGNED NOT NULL,
  qty           INT          NOT NULL DEFAULT 0,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (product_id, location_id),
  KEY idx_balances_branch_product (branch_id, product_id),
  KEY idx_balances_location (location_id, warehouse_id, branch_id),
  CONSTRAINT fk_balances_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_balances_location FOREIGN KEY (location_id, warehouse_id, branch_id)
    REFERENCES storage_locations (id, warehouse_id, branch_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_balances_qty CHECK (qty >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Customers  (a sale with customer_id = NULL is a walk-in)
--   Shared across branches: branch_id = home branch, customer_branches =
--   every branch where the customer is visible (always incl. home).
-- ---------------------------------------------------------------------
CREATE TABLE customers (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  phone       VARCHAR(30)  NULL,
  email       VARCHAR(120) NULL,
  address     VARCHAR(255) NULL,
  branch_id   INT UNSIGNED NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_customers_name (name),
  KEY idx_customers_branch (branch_id),
  CONSTRAINT fk_customers_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_branches (
  customer_id  INT UNSIGNED NOT NULL,
  branch_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (customer_id, branch_id),
  KEY idx_customer_branches_branch (branch_id, customer_id),
  CONSTRAINT fk_customer_branches_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_customer_branches_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Sales
--   held      = saved cart, stock NOT yet deducted
--   completed = paid, stock deducted
--   cancelled = voided (stock returned; who/when/why in voided_by, voided_at, void_reason)
-- ---------------------------------------------------------------------
CREATE TABLE sales (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sale_no           VARCHAR(20)   NOT NULL,
  branch_id         INT UNSIGNED  NOT NULL,
  user_id           INT UNSIGNED  NOT NULL,
  customer_id       INT UNSIGNED  NULL,
  payment_type      ENUM('cash','gcash','card') NOT NULL DEFAULT 'cash',
  status            ENUM('held','completed','cancelled') NOT NULL DEFAULT 'completed',
  subtotal          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount_percent  DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  discount_amount   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  vat_rate          DECIMAL(5,2)  NOT NULL DEFAULT 12.00,
  vat_amount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  amount_paid       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  change_amount     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at      DATETIME      NULL,
  voided_at         DATETIME      NULL,
  voided_by         INT UNSIGNED  NULL,
  void_reason       VARCHAR(255)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_no (sale_no),
  KEY idx_sales_status_date (status, created_at),
  KEY idx_sales_user (user_id),
  KEY idx_sales_customer (customer_id),
  KEY idx_sales_created (created_at),
  KEY idx_sales_voided_by (voided_by),
  KEY idx_sales_branch_status_date (branch_id, status, created_at),
  KEY idx_sales_branch_user_date (branch_id, user_id, created_at),
  CONSTRAINT fk_sales_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sales_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_sales_voided_by FOREIGN KEY (voided_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_sales_discount CHECK (discount_percent BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Name/code/price are copied onto each line so history survives product edits.
CREATE TABLE sale_items (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sale_id       INT UNSIGNED  NOT NULL,
  product_id    INT UNSIGNED  NULL,
  product_code  VARCHAR(20)   NOT NULL,
  product_name  VARCHAR(100)  NOT NULL,
  unit_price    DECIMAL(10,2) NOT NULL,
  quantity      INT           NOT NULL,
  line_total    DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_items_sale (sale_id),
  KEY idx_items_product (product_id),
  CONSTRAINT fk_items_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_items_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Stock audit log: every change to products.stock and why.
--   quantity is signed (+ in, - out); stock_after is the company level after
--   the change; location_qty_after is the level at (branch, warehouse, location).
-- ---------------------------------------------------------------------
CREATE TABLE stock_movements (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id          INT UNSIGNED    NOT NULL,
  user_id             INT UNSIGNED    NULL,
  sale_id             INT UNSIGNED    NULL,
  branch_id           INT UNSIGNED    NOT NULL,
  warehouse_id        INT UNSIGNED    NOT NULL,
  location_id         INT UNSIGNED    NOT NULL,
  type                ENUM('initial','sale','restock','adjustment','void') NOT NULL,
  quantity            INT             NOT NULL,
  stock_after         INT             NOT NULL,
  location_qty_after  INT             NULL,
  note                VARCHAR(255)    NULL,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_movements_product (product_id, created_at),
  KEY fk_movements_user (user_id),
  KEY fk_movements_sale (sale_id),
  KEY idx_movements_branch_date (branch_id, created_at),
  KEY idx_movements_location (location_id, warehouse_id, branch_id),
  CONSTRAINT fk_movements_location FOREIGN KEY (location_id, warehouse_id, branch_id)
    REFERENCES storage_locations (id, warehouse_id, branch_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_movements_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_movements_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_movements_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Company settings (editable by admin in Settings)
-- ---------------------------------------------------------------------
CREATE TABLE settings (
  setting_key    VARCHAR(50)  NOT NULL,
  setting_value  VARCHAR(255) NOT NULL DEFAULT '',
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit log: who did what. username/role are snapshots (survive user
-- edits/deletes); old/new values are JSON (never passwords or tokens).
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  occurred_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id      INT UNSIGNED    NULL,
  username     VARCHAR(50)     NULL,
  role         VARCHAR(30)     NULL,
  branch_id    INT UNSIGNED    NULL,
  module       VARCHAR(30)     NOT NULL,
  action       VARCHAR(50)     NOT NULL,
  entity_type  VARCHAR(40)     NULL,
  entity_id    BIGINT UNSIGNED NULL,
  entity_ref   VARCHAR(60)     NULL,
  old_values   LONGTEXT        NULL,
  new_values   LONGTEXT        NULL,
  ip_address   VARCHAR(45)     NULL,
  user_agent   VARCHAR(255)    NULL,
  PRIMARY KEY (id),
  KEY idx_audit_branch_date (branch_id, occurred_at),
  KEY idx_audit_module_date (module, occurred_at),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_user_date (user_id, occurred_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_audit_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_audit_old_json CHECK (old_values IS NULL OR JSON_VALID(old_values)),
  CONSTRAINT chk_audit_new_json CHECK (new_values IS NULL OR JSON_VALID(new_values))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  REFERENCE DATA  (same rows and ids as migrations/003)
-- =====================================================================

-- Address / contact / TIN stay NULL until the owner fills them in.
INSERT INTO branches (id, code, name, is_main) VALUES
  (1, 'MAR', 'Maramag City',        1),
  (2, 'MLB', 'Malaybalay City',     0),
  (3, 'CDO', 'Cagayan de Oro City', 0),
  (4, 'DAV', 'Davao City',          0),
  (5, 'VAL', 'Valencia City',       0);

-- One MAIN warehouse + one sellable GENERAL location per branch (ids = branch ids).
INSERT INTO warehouses (branch_id, code, name, is_default)
SELECT id, 'MAIN', 'Main Warehouse', 1 FROM branches ORDER BY id;

INSERT INTO storage_locations (warehouse_id, branch_id, code, name, is_sellable, is_default)
SELECT id, branch_id, 'GENERAL', 'General Stock', 1, 1 FROM warehouses ORDER BY id;

-- Permission registry (must match config/permissions.php).
INSERT INTO permissions (id, perm_key, module, label, sort_order) VALUES
  ( 1, 'pos.access',          'POS',       'Open the POS and complete sales',                           10),
  ( 2, 'sales.view',          'Sales',     'View sales history and receipts',                           20),
  ( 3, 'sales.cancel',        'Sales',     'Void sales',                                                30),
  ( 4, 'customers.view',      'Customers', 'View customers',                                            40),
  ( 5, 'customers.edit',      'Customers', 'Add and edit customers',                                    50),
  ( 6, 'customers.delete',    'Customers', 'Deactivate and delete customers',                           60),
  ( 7, 'inventory.view',      'Inventory', 'View products and stock (read-only)',                       70),
  ( 8, 'inventory.adjust',    'Inventory', 'Adjust stock',                                              80),
  ( 9, 'products.manage',     'Inventory', 'Add, edit, deactivate and delete products',                 90),
  (10, 'reports.view',        'Reports',   'View reports and export CSV',                              100),
  (11, 'users.view',          'Users',     'View users',                                               110),
  (12, 'users.manage',        'Users',     'Add and edit users, reset passwords, activate/deactivate', 120),
  (13, 'users.delete',        'Users',     'Delete users',                                             130),
  (14, 'roles.manage',        'Roles',     'Manage roles and permissions',                             140),
  (15, 'branches.manage',     'Branches',  'Manage branch details',                                    150),
  (16, 'branches.access_all', 'Branches',  'Access all branches (view and switch)',                    160),
  (17, 'settings.manage',     'Settings',  'Manage company settings',                                  170),
  (18, 'audit_logs.view',     'Audit Log', 'View the audit log',                                       180);

-- super_admin: is_super = 1 means every permission (no role_permissions rows).
INSERT INTO roles (id, code, name, description, is_system, is_super) VALUES
  (1, 'super_admin',  'Super Administrator',  'Full access to every module and every branch.', 1, 1),
  (2, 'branch_admin', 'Branch Administrator', 'Runs a branch: sales and voids, customers, stock adjustments, reports and branch users.', 1, 0),
  (3, 'cashier',      'Cashier',              'Sells at the POS and looks after customers.', 1, 0),
  (4, 'technician',   'Technician',           'Looks up customers and stock.', 1, 0);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE (r.code = 'branch_admin' AND p.perm_key IN ('pos.access', 'sales.view', 'sales.cancel', 'customers.view',
         'customers.edit', 'customers.delete', 'inventory.view', 'inventory.adjust', 'reports.view', 'users.view',
         'users.manage', 'audit_logs.view'))
   OR (r.code = 'cashier' AND p.perm_key IN ('pos.access', 'sales.view', 'customers.view', 'customers.edit',
         'inventory.view'))
   OR (r.code = 'technician' AND p.perm_key IN ('customers.view', 'inventory.view'))
ORDER BY r.id, p.id;


-- =====================================================================
--  SAMPLE DATA  (everything lives at branch 1 = MAR, the main store)
-- =====================================================================

-- Passwords: admin123 / cashier123 (bcrypt via password_hash)
INSERT INTO users (id, username, password_hash, full_name, role, branch_id) VALUES
  (1, 'admin',   '$2y$10$7gxc7KA0gu01bSQBkwmJH.vSePJE8ApYFSY0jLBfXrPAWlw31EG/y', 'System Administrator', 'super_admin', 1),
  (2, 'cashier', '$2y$10$nDFq/226yK.ITggPBw5mTOMTJYcU4ecgAMBnVa25vB6.UDu.Xv9BG', 'Sales Counter',        'cashier',     1);

-- Company details printed on receipts. Replace them with your own
-- (Settings page arrives in Phase 4; until then edit them in phpMyAdmin).
INSERT INTO settings (setting_key, setting_value) VALUES
  ('shop_name',      'EXECOM Logistics'),
  ('shop_address',   'Company Address, City, Province'),
  ('shop_phone',     '(000) 000-0000'),
  ('shop_tin',       ''),
  ('vat_rate',       '12.00'),
  ('receipt_footer', 'Thank you for choosing EXECOM Logistics! Keep this receipt for warranty claims.');

INSERT INTO categories (id, name, slug, icon, sort_order) VALUES
  (1, 'Laptops & Computers', 'laptops-computers', 'laptop',    1),
  (2, 'Peripherals',         'peripherals',       'mouse',     2),
  (3, 'Accessories',         'accessories',       'plug',      3),
  (4, 'Network',             'network',           'network',   4),
  (5, 'Office Supplies',     'office-supplies',   'clipboard', 5);

INSERT INTO products (id, category_id, code, barcode, name, description, price, stock, reorder_level) VALUES
  ( 1, 1, 'ITM-0001', '4806500000011', 'Laptop',            '14" Core i5, 8GB RAM, 512GB SSD',        25000.00, 12, 3),
  ( 2, 2, 'ITM-0002', '4806500000028', 'Mouse',             'Wireless optical mouse, 2.4GHz',           350.00, 45, 10),
  ( 3, 2, 'ITM-0003', '4806500000035', 'Keyboard',          'Full-size USB keyboard',                   550.00, 32, 10),
  ( 4, 2, 'ITM-0004', '4806500000042', 'Monitor 24"',       '24" Full HD IPS monitor, HDMI/VGA',       4500.00, 18, 5),
  ( 5, 3, 'ITM-0005', '4806500000059', 'UPS 1000VA',        '1000VA line-interactive UPS with AVR',    3800.00, 10, 3),
  ( 6, 4, 'ITM-0006', '4806500000066', 'Network Switch',    '16-port Gigabit unmanaged switch',        2500.00, 15, 3),
  ( 7, 3, 'ITM-0007', '4806500000073', 'External HDD 1TB',  'USB 3.0 portable hard drive',             3200.00, 22, 5),
  ( 8, 5, 'ITM-0008', '4806500000080', 'Printer',           'Ink tank printer: print, scan, copy',     6500.00,  8, 3),
  ( 9, 3, 'ITM-0009', '4806500000097', 'RAM 8GB',           'DDR4 3200MHz desktop memory',             1800.00, 30, 8),
  (10, 3, 'ITM-0010', '4806500000103', 'SSD 512GB',         '2.5" SATA III solid state drive',         2800.00, 20, 5),
  (11, 2, 'ITM-0011', '4806500000110', 'Webcam',            '1080p USB webcam with microphone',        1200.00, 16, 5),
  (12, 2, 'ITM-0012', '4806500000127', 'Headset',           'Over-ear USB headset with boom mic',      1500.00, 14, 5);

INSERT INTO customers (id, name, phone, email, address, branch_id) VALUES
  (1, 'Juan Dela Cruz', '0917 123 4567', 'juan.delacruz@example.com', 'Makati City', 1),
  (2, 'Maria Santos',   '0918 234 5678', 'maria.santos@example.com',  'Pasig City',  1),
  (3, 'Carlo Reyes',    '0919 345 6789', NULL,                         'Taguig City', 1),
  (4, 'Angela Lim',     '0920 456 7890', 'angela.lim@example.com',    NULL,          1);

-- Every customer is visible at its home branch.
INSERT INTO customer_branches (customer_id, branch_id)
SELECT id, branch_id FROM customers ORDER BY id;

-- A few completed sales so History/Reports have data.
-- Totals: VAT 12% is applied on (subtotal - discount).
INSERT INTO sales (id, sale_no, branch_id, user_id, customer_id, payment_type, status, subtotal, discount_percent, discount_amount, vat_rate, vat_amount, total, amount_paid, change_amount, created_at, completed_at) VALUES
  (1, '0000001', 1, 2, NULL, 'cash',  'completed', 1250.00,  0.00,   0.00, 12.00, 150.00, 1400.00, 1500.00, 100.00, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY),
  (2, '0000002', 1, 2, 1,    'gcash', 'completed', 4600.00, 10.00, 460.00, 12.00, 496.80, 4636.80, 4636.80,   0.00, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
  (3, '0000003', 1, 1, 2,    'card',  'completed', 5700.00,  0.00,   0.00, 12.00, 684.00, 6384.00, 6384.00,   0.00, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
  (4, '0000004', 1, 2, NULL, 'cash',  'completed', 4700.00,  0.00,   0.00, 12.00, 564.00, 5264.00, 5300.00,  36.00, NOW() - INTERVAL 1 HOUR, NOW() - INTERVAL 1 HOUR);

INSERT INTO sale_items (sale_id, product_id, product_code, product_name, unit_price, quantity, line_total) VALUES
  (1,  2, 'ITM-0002', 'Mouse',             350.00, 2,  700.00),
  (1,  3, 'ITM-0003', 'Keyboard',          550.00, 1,  550.00),
  (2,  9, 'ITM-0009', 'RAM 8GB',          1800.00, 1, 1800.00),
  (2, 10, 'ITM-0010', 'SSD 512GB',        2800.00, 1, 2800.00),
  (3,  4, 'ITM-0004', 'Monitor 24"',      4500.00, 1, 4500.00),
  (3, 11, 'ITM-0011', 'Webcam',           1200.00, 1, 1200.00),
  (4, 12, 'ITM-0012', 'Headset',          1500.00, 1, 1500.00),
  (4,  7, 'ITM-0007', 'External HDD 1TB', 3200.00, 1, 3200.00);

-- Opening stock: all of it at MAR / MAIN / GENERAL (location 1), plus the audit log.
INSERT INTO stock_balances (product_id, location_id, warehouse_id, branch_id, qty)
SELECT id, 1, 1, 1, stock FROM products ORDER BY id;

INSERT INTO stock_movements (product_id, user_id, branch_id, warehouse_id, location_id, type, quantity, stock_after, location_qty_after, note)
SELECT id, 1, 1, 1, 1, 'initial', stock, stock, stock, 'Opening stock' FROM products ORDER BY id;

-- Sample product illustrations (files in assets/uploads/products/)
UPDATE products SET image = CONCAT('sample-', LOWER(code), '.png');
