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
--  Existing installs: don't re-import; apply migrations/ (002 ... 019, in order) instead.
--  This file = Phase 1-4 schema + migrations 002 to 019.
-- =====================================================================

-- Silence the harmless "database exists" / "unknown table" notes that
-- IF [NOT] EXISTS produces, so the import reports 0 warnings.
SET @OLD_SQL_NOTES = @@SQL_NOTES, SQL_NOTES = 0;

CREATE DATABASE IF NOT EXISTS execomlogistics_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE execomlogistics_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS notification_recipients;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS document_attachments;
DROP TABLE IF EXISTS disbursement_lines;
DROP TABLE IF EXISTS disbursements;
DROP TABLE IF EXISTS supplier_invoices;
DROP TABLE IF EXISTS collection_lines;
DROP TABLE IF EXISTS collections;
DROP TABLE IF EXISTS quotation_lines;
DROP TABLE IF EXISTS quotations;
DROP TABLE IF EXISTS customer_delivery_serials;
DROP TABLE IF EXISTS customer_delivery_lines;
DROP TABLE IF EXISTS customer_deliveries;
DROP TABLE IF EXISTS customer_order_lines;
DROP TABLE IF EXISTS customer_orders;
DROP TABLE IF EXISTS purchase_order_request_lines;
DROP TABLE IF EXISTS purchase_order_lines;
DROP TABLE IF EXISTS purchase_orders;
DROP TABLE IF EXISTS purchase_request_lines;
DROP TABLE IF EXISTS purchase_requests;
DROP TABLE IF EXISTS job_order_technicians;
DROP TABLE IF EXISTS job_order_types;
DROP TABLE IF EXISTS job_order_part_serials;
DROP TABLE IF EXISTS job_order_parts;
DROP TABLE IF EXISTS job_order_events;
DROP TABLE IF EXISTS job_orders;
DROP TABLE IF EXISTS stock_transfer_serials;
DROP TABLE IF EXISTS stock_transfer_lines;
DROP TABLE IF EXISTS stock_transfers;
DROP TABLE IF EXISTS inventory_doc_serials;
DROP TABLE IF EXISTS inventory_doc_lines;
DROP TABLE IF EXISTS inventory_docs;
DROP TABLE IF EXISTS sale_item_serials;
DROP TABLE IF EXISTS product_serials;
DROP TABLE IF EXISTS receiving_item_serials;
DROP TABLE IF EXISTS receiving_items;
DROP TABLE IF EXISTS receiving_reports;
DROP TABLE IF EXISTS product_branches;
DROP TABLE IF EXISTS document_sequences;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS stock_balances;
DROP TABLE IF EXISTS price_approvals;
DROP TABLE IF EXISTS sale_items;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS customer_contacts;
DROP TABLE IF EXISTS customer_branches;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS customer_types;
DROP TABLE IF EXISTS supplier_contacts;
DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS storage_locations;
DROP TABLE IF EXISTS warehouses;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS product_models;
DROP TABLE IF EXISTS brands;
DROP TABLE IF EXISTS units;
DROP TABLE IF EXISTS lookups;
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
  monthly_target   DECIMAL(14,2) NULL,
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
  max_price_drop DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  max_discount DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code),
  CONSTRAINT chk_roles_limits CHECK (max_price_drop BETWEEN 0 AND 100 AND max_discount BETWEEN 0 AND 100)
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

-- Master data (company-wide, no branch_id). Names are unique case-insensitively
-- (utf8mb4_unicode_ci); inactive entries stay on old records but aren't offered.
CREATE TABLE brands (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(80)  NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_brands_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (id, brand_id) is unique so products can reference (model_id, brand_id):
-- a product's model always belongs to the product's brand.
CREATE TABLE product_models (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  brand_id    INT UNSIGNED NOT NULL,
  name        VARCHAR(80)  NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_models_brand_name (brand_id, name),
  UNIQUE KEY uq_product_models_id_brand (id, brand_id),
  CONSTRAINT fk_product_models_brand FOREIGN KEY (brand_id) REFERENCES brands (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE units (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(10)  NOT NULL,
  name        VARCHAR(40)  NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_units_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic simple lists; list keys are registered in config/master-data.php.
CREATE TABLE lookups (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  list        VARCHAR(30)  NOT NULL,
  name        VARCHAR(80)  NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lookups_list_name (list, name),
  KEY idx_lookups_list_sort (list, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- price = suggested selling price; unit_cost is only shown with products.cost.
CREATE TABLE products (
  id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  category_id    INT UNSIGNED      NOT NULL,
  brand_id       INT UNSIGNED      NULL,
  model_id       INT UNSIGNED      NULL,
  unit_id        INT UNSIGNED      NULL,
  code           VARCHAR(20)       NOT NULL,
  barcode        VARCHAR(50)       NULL,
  name           VARCHAR(100)      NOT NULL,
  description    VARCHAR(255)      NULL,
  specs          VARCHAR(500)      NULL,
  price          DECIMAL(10,2)     NOT NULL DEFAULT 0.00,
  unit_cost      DECIMAL(10,2)     NOT NULL DEFAULT 0.00,
  stock          INT               NOT NULL DEFAULT 0,
  reorder_level  INT               NOT NULL DEFAULT 5,
  track_serial   TINYINT(1)        NOT NULL DEFAULT 0,
  warranty_days  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  image          VARCHAR(255)      NULL,
  is_active      TINYINT(1)        NOT NULL DEFAULT 1,
  created_at     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_code (code),
  UNIQUE KEY uq_products_barcode (barcode),
  KEY idx_products_category (category_id),
  KEY idx_products_name (name),
  KEY idx_products_brand (brand_id),
  KEY idx_products_model_brand (model_id, brand_id),
  KEY idx_products_unit (unit_id),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_products_model FOREIGN KEY (model_id, brand_id) REFERENCES product_models (id, brand_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_products_unit FOREIGN KEY (unit_id) REFERENCES units (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_products_price CHECK (price >= 0),
  CONSTRAINT chk_products_stock CHECK (stock >= 0),
  CONSTRAINT chk_products_unit_cost CHECK (unit_cost >= 0),
  CONSTRAINT chk_products_warranty_days CHECK (warranty_days <= 3650),
  CONSTRAINT chk_products_model_brand CHECK (model_id IS NULL OR brand_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Branch stock: branch -> warehouse -> storage location -> balance.
--   products.stock stays the company total (= SUM(stock_balances.qty)).
--   The composite keys keep (location, warehouse, branch) consistent.
--   Location kind: stock (GENERAL, bins; may be sellable/default),
--   damaged (DAMAGED) and display (DISPLAY), one each per warehouse and
--   never sellable or default.
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
  kind          ENUM('stock','damaged','display') NOT NULL DEFAULT 'stock',
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
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_locations_kind CHECK (kind = 'stock' OR (is_sellable = 0 AND is_default = 0)),
  CONSTRAINT chk_locations_kind_code CHECK ((kind = 'stock') = (code NOT IN ('DAMAGED', 'DISPLAY')))
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

-- Moving-average cost of a product at a branch (4 dp). A missing row means
-- "not costed yet": the app creates it from products.unit_cost (default cost).
CREATE TABLE product_branches (
  product_id  INT UNSIGNED  NOT NULL,
  branch_id   INT UNSIGNED  NOT NULL,
  avg_cost    DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (product_id, branch_id),
  KEY idx_product_branches_branch (branch_id),
  CONSTRAINT fk_product_branches_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_product_branches_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_product_branches_avg_cost CHECK (avg_cost >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Customers  (a sale with customer_id = NULL is a walk-in)
--   Shared across branches: branch_id = home branch, customer_branches =
--   every branch where the customer is visible (always incl. home).
-- ---------------------------------------------------------------------
CREATE TABLE customer_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(60)  NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(100) NOT NULL,
  phone             VARCHAR(30)  NULL,
  email             VARCHAR(120) NULL,
  address           VARCHAR(255) NULL,
  customer_type_id  INT UNSIGNED NULL,
  tin               VARCHAR(20)  NULL,
  credit_days       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  credit_limit      DECIMAL(14,2) NULL,
  branch_id         INT UNSIGNED NOT NULL,
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_customers_name (name),
  KEY idx_customers_branch (branch_id),
  KEY idx_customers_type (customer_type_id),
  KEY idx_customers_phone (phone),
  CONSTRAINT fk_customers_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customers_type FOREIGN KEY (customer_type_id) REFERENCES customer_types (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contact persons (max 5 per customer, enforced in PHP).
CREATE TABLE customer_contacts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id  INT UNSIGNED NOT NULL,
  name         VARCHAR(100) NOT NULL,
  position     VARCHAR(60)  NULL,
  phone        VARCHAR(30)  NULL,
  email        VARCHAR(120) NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_customer_contacts_customer (customer_id, sort_order),
  CONSTRAINT fk_customer_contacts_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
    ON UPDATE CASCADE ON DELETE CASCADE
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
-- Suppliers  (company-wide; contacts max 5 per supplier, enforced in PHP)
-- ---------------------------------------------------------------------
CREATE TABLE suppliers (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(20)  NOT NULL,
  name           VARCHAR(120) NOT NULL,
  tin            VARCHAR(20)  NULL,
  address        VARCHAR(255) NULL,
  phone          VARCHAR(30)  NULL,
  email          VARCHAR(120) NULL,
  payment_terms  VARCHAR(60)  NULL,
  terms_days     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  notes          VARCHAR(255) NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_code (code),
  KEY idx_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_contacts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_id  INT UNSIGNED NOT NULL,
  name         VARCHAR(100) NOT NULL,
  position     VARCHAR(60)  NULL,
  phone        VARCHAR(30)  NULL,
  email        VARCHAR(120) NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_supplier_contacts_supplier (supplier_id, sort_order),
  CONSTRAINT fk_supplier_contacts_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
    ON UPDATE CASCADE ON DELETE CASCADE
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
  job_order_id      INT UNSIGNED  NULL,
  customer_order_id INT UNSIGNED  NULL,
  payment_type      ENUM('cash','gcash','card','charge') NOT NULL DEFAULT 'cash',
  status            ENUM('held','completed','cancelled') NOT NULL DEFAULT 'completed',
  subtotal          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount_percent  DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  discount_amount   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount_approved_by INT UNSIGNED NULL,
  vat_rate          DECIMAL(5,2)  NOT NULL DEFAULT 12.00,
  vat_amount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  cost_total        DECIMAL(12,2) NULL,
  amount_paid       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  change_amount     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  settled_amount    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  due_date          DATE          NULL,
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
  KEY idx_sales_discount_approved_by (discount_approved_by),
  KEY idx_sales_job_order (job_order_id),
  KEY idx_sales_customer_order (customer_order_id),
  KEY idx_sales_receivable (payment_type, status, branch_id, customer_id),
  CONSTRAINT fk_sales_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sales_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_sales_voided_by FOREIGN KEY (voided_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_sales_discount_approved_by FOREIGN KEY (discount_approved_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_sales_discount CHECK (discount_percent BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Name/code/price are copied onto each line so history survives product edits.
CREATE TABLE sale_items (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sale_id       INT UNSIGNED  NOT NULL,
  product_id    INT UNSIGNED  NULL,
  line_type     ENUM('item','part','labor') NOT NULL DEFAULT 'item',
  product_code  VARCHAR(20)   NOT NULL,
  product_name  VARCHAR(100)  NOT NULL,
  unit_price    DECIMAL(10,2) NOT NULL,
  suggested_price DECIMAL(10,2) NULL,
  price_reason  VARCHAR(255)  NULL,
  price_approved_by INT UNSIGNED NULL,
  unit_cost     DECIMAL(12,4) NULL,
  quantity      INT           NOT NULL,
  line_total    DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_items_sale (sale_id),
  KEY idx_items_product (product_id),
  KEY idx_items_price_approved_by (price_approved_by),
  CONSTRAINT fk_items_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_items_price_approved_by FOREIGN KEY (price_approved_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_items_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Document numbering: last_no = last number handed out for
-- (branch, doc_type, year); RR uses the posting year.
-- ---------------------------------------------------------------------
CREATE TABLE document_sequences (
  branch_id   INT UNSIGNED      NOT NULL,
  doc_type    VARCHAR(20)       NOT NULL,
  year        SMALLINT UNSIGNED NOT NULL,
  last_no     INT UNSIGNED      NOT NULL DEFAULT 0,
  PRIMARY KEY (branch_id, doc_type, year),
  CONSTRAINT fk_docseq_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Receiving reports
--   draft     = editable, no number, no stock effect
--   posted    = rr_no assigned, stock added, avg cost updated
--   cancelled = posted then reversed (cancelled_* + cancel_reason)
-- ---------------------------------------------------------------------
CREATE TABLE receiving_reports (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  rr_no          VARCHAR(30)   NULL,
  branch_id      INT UNSIGNED  NOT NULL,
  warehouse_id   INT UNSIGNED  NOT NULL,
  location_id    INT UNSIGNED  NOT NULL,
  supplier_id    INT UNSIGNED  NOT NULL,
  po_id          INT UNSIGNED  NULL,
  reference_no   VARCHAR(50)   NULL,
  received_date  DATE          NOT NULL,
  notes          VARCHAR(500)  NULL,
  status         ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  total_qty      INT           NOT NULL DEFAULT 0,
  total_cost     DECIMAL(14,2) NULL,
  created_by     INT UNSIGNED  NOT NULL,
  posted_by      INT UNSIGNED  NULL,
  cancelled_by   INT UNSIGNED  NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  posted_at      DATETIME      NULL,
  cancelled_at   DATETIME      NULL,
  cancel_reason  VARCHAR(255)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_receiving_rr_no (rr_no),
  KEY idx_receiving_branch_status_date (branch_id, status, received_date),
  KEY idx_receiving_supplier (supplier_id),
  KEY idx_receiving_location (location_id, warehouse_id, branch_id),
  KEY idx_receiving_created_by (created_by),
  KEY idx_receiving_posted_by (posted_by),
  KEY idx_receiving_cancelled_by (cancelled_by),
  KEY idx_receiving_po (po_id),
  CONSTRAINT fk_receiving_location FOREIGN KEY (location_id, warehouse_id, branch_id)
    REFERENCES storage_locations (id, warehouse_id, branch_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_receiving_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_receiving_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_receiving_posted_by FOREIGN KEY (posted_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_receiving_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_receiving_rr_no_status CHECK ((status = 'draft') = (rr_no IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- unit_cost NULL = not entered yet (drafts by users without products.cost);
-- posting requires it. qty_before / avg_cost_* are filled when posted.
CREATE TABLE receiving_items (
  id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  receiving_id     INT UNSIGNED      NOT NULL,
  product_id       INT UNSIGNED      NOT NULL,
  po_line_id       INT UNSIGNED      NULL,
  quantity         INT               NOT NULL,
  unit_cost        DECIMAL(12,4)     NULL,
  line_total       DECIMAL(14,2)     NULL,
  qty_before       INT               NULL,
  avg_cost_before  DECIMAL(12,4)     NULL,
  avg_cost_after   DECIMAL(12,4)     NULL,
  sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_receiving_items_product (receiving_id, product_id),
  KEY idx_receiving_items_product (product_id),
  KEY idx_receiving_items_po_line (po_line_id),
  CONSTRAINT fk_receiving_items_receiving FOREIGN KEY (receiving_id) REFERENCES receiving_reports (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_receiving_items_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_receiving_items_qty CHECK (quantity > 0),
  CONSTRAINT chk_receiving_items_cost CHECK (unit_cost IS NULL OR unit_cost >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Serials typed on an RR line (draft entry; kept as the permanent record).
CREATE TABLE receiving_item_serials (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  receiving_item_id  INT UNSIGNED NOT NULL,
  serial_no          VARCHAR(60)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_receiving_item_serials (receiving_item_id, serial_no),
  CONSTRAINT fk_receiving_item_serials_item FOREIGN KEY (receiving_item_id) REFERENCES receiving_items (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Serial numbers (one row per physical unit of a track_serial product)
--   in_stock = at (branch, warehouse, location); sold = on a completed sale;
--   removed  = reserved for Phase 7b.
-- ---------------------------------------------------------------------
CREATE TABLE product_serials (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id         INT UNSIGNED NOT NULL,
  serial_no          VARCHAR(60)  NOT NULL,
  branch_id          INT UNSIGNED NOT NULL,
  warehouse_id       INT UNSIGNED NOT NULL,
  location_id        INT UNSIGNED NOT NULL,
  status             ENUM('in_stock','sold','removed','in_transit','in_custody','installed','delivered') NOT NULL DEFAULT 'in_stock',
  receiving_item_id  INT UNSIGNED NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_serials (product_id, serial_no),
  KEY idx_product_serials_serial (serial_no),
  KEY idx_product_serials_loc_product_status (location_id, product_id, status),
  KEY idx_product_serials_location (location_id, warehouse_id, branch_id),
  KEY idx_product_serials_branch_status (branch_id, status),
  KEY idx_product_serials_receiving_item (receiving_item_id),
  CONSTRAINT fk_product_serials_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_product_serials_location FOREIGN KEY (location_id, warehouse_id, branch_id)
    REFERENCES storage_locations (id, warehouse_id, branch_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_product_serials_receiving_item FOREIGN KEY (receiving_item_id) REFERENCES receiving_items (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which serials went out on a sale line (kept after a void, as history).
CREATE TABLE sale_item_serials (
  sale_item_id  INT UNSIGNED NOT NULL,
  serial_id     INT UNSIGNED NOT NULL,
  PRIMARY KEY (sale_item_id, serial_id),
  KEY idx_sale_item_serials_serial (serial_id),
  CONSTRAINT fk_sale_item_serials_item FOREIGN KEY (sale_item_id) REFERENCES sale_items (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_sale_item_serials_serial FOREIGN KEY (serial_id) REFERENCES product_serials (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Stock documents (as migrations/006)
--   doc_type transfer = location -> location of the same branch (purpose
--                       move / damage / display / restore, set by the app
--                       from the location kinds); posted at creation
--            issue    = internal use (stock leaves the company); posted
--            writeoff = damaged stock written off; posted
--            count    = stock count: open -> submitted -> posted | cancelled
--   from_* = source location (count: the counted location); to_* = transfer
--   destination only. The composite FKs keep both locations in branch_id.
--   Numbers TRF/ISS/WOF/CNT-<branch>-<year>-<n> come from document_sequences.
-- ---------------------------------------------------------------------
CREATE TABLE inventory_docs (
  id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  doc_no             VARCHAR(30)   NOT NULL,
  doc_type           ENUM('transfer','issue','writeoff','count') NOT NULL,
  purpose            ENUM('move','damage','display','restore') NULL,
  branch_id          INT UNSIGNED  NOT NULL,
  from_warehouse_id  INT UNSIGNED  NOT NULL,
  from_location_id   INT UNSIGNED  NOT NULL,
  to_warehouse_id    INT UNSIGNED  NULL,
  to_location_id     INT UNSIGNED  NULL,
  status             ENUM('open','submitted','posted','cancelled') NOT NULL,
  reason             VARCHAR(255)  NULL,
  total_qty          INT           NOT NULL DEFAULT 0,
  total_cost         DECIMAL(14,2) NULL,
  created_by         INT UNSIGNED  NOT NULL,
  submitted_by       INT UNSIGNED  NULL,
  posted_by          INT UNSIGNED  NULL,
  cancelled_by       INT UNSIGNED  NULL,
  created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  submitted_at       DATETIME      NULL,
  posted_at          DATETIME      NULL,
  cancelled_at       DATETIME      NULL,
  cancel_reason      VARCHAR(255)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invdocs_doc_no (doc_no),
  KEY idx_invdocs_branch_type_status_date (branch_id, doc_type, status, created_at),
  KEY idx_invdocs_from_status (from_location_id, status),
  KEY idx_invdocs_from_location (from_location_id, from_warehouse_id, branch_id),
  KEY idx_invdocs_to_location (to_location_id, to_warehouse_id, branch_id),
  KEY idx_invdocs_created_by (created_by),
  KEY idx_invdocs_submitted_by (submitted_by),
  KEY idx_invdocs_posted_by (posted_by),
  KEY idx_invdocs_cancelled_by (cancelled_by),
  CONSTRAINT fk_invdocs_from_location FOREIGN KEY (from_location_id, from_warehouse_id, branch_id)
    REFERENCES storage_locations (id, warehouse_id, branch_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_invdocs_to_location FOREIGN KEY (to_location_id, to_warehouse_id, branch_id)
    REFERENCES storage_locations (id, warehouse_id, branch_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_invdocs_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_invdocs_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_invdocs_posted_by FOREIGN KEY (posted_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_invdocs_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_invdocs_status CHECK (doc_type = 'count' OR status = 'posted'),
  CONSTRAINT chk_invdocs_purpose CHECK ((doc_type = 'transfer') = (purpose IS NOT NULL)),
  CONSTRAINT chk_invdocs_to_location CHECK ((doc_type = 'transfer') = (to_location_id IS NOT NULL)),
  CONSTRAINT chk_invdocs_to_pair CHECK ((to_location_id IS NULL) = (to_warehouse_id IS NULL)),
  CONSTRAINT chk_invdocs_from_to CHECK (to_location_id IS NULL OR to_location_id <> from_location_id),
  CONSTRAINT chk_invdocs_total_qty CHECK (total_qty >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- quantity   = transfer / issue / writeoff quantity (NULL on counts)
-- system_qty = count: balance frozen when the count was created
-- counted_qty= count: quantity entered (NULL until counted)
-- adjust_qty = signed change actually posted (-qty for issue/writeoff,
--              the variance for a count, NULL for a transfer)
-- unit_cost  = branch average cost snapshot (outbound / count lines)
CREATE TABLE inventory_doc_lines (
  id           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  doc_id       INT UNSIGNED      NOT NULL,
  product_id   INT UNSIGNED      NOT NULL,
  quantity     INT               NULL,
  system_qty   INT               NULL,
  counted_qty  INT               NULL,
  adjust_qty   INT               NULL,
  unit_cost    DECIMAL(12,4)     NULL,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invdoc_lines_product (doc_id, product_id),
  KEY idx_invdoc_lines_product (product_id),
  CONSTRAINT fk_invdoc_lines_doc FOREIGN KEY (doc_id) REFERENCES inventory_docs (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_invdoc_lines_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_invdoc_lines_qty CHECK (quantity IS NULL OR quantity > 0),
  CONSTRAINT chk_invdoc_lines_system_qty CHECK (system_qty IS NULL OR system_qty >= 0),
  CONSTRAINT chk_invdoc_lines_counted_qty CHECK (counted_qty IS NULL OR counted_qty >= 0),
  CONSTRAINT chk_invdoc_lines_cost CHECK (unit_cost IS NULL OR unit_cost >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Serials on a document line. Counts: the expected serials frozen at
-- creation (found 0 = not found yet / missing, 1 = found). Other types: the
-- serials moved / removed (found = 1).
CREATE TABLE inventory_doc_serials (
  line_id    INT UNSIGNED NOT NULL,
  serial_id  INT UNSIGNED NOT NULL,
  found      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (line_id, serial_id),
  KEY idx_invdoc_serials_serial (serial_id),
  CONSTRAINT fk_invdoc_serials_line FOREIGN KEY (line_id) REFERENCES inventory_doc_lines (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_invdoc_serials_serial FOREIGN KEY (serial_id) REFERENCES product_serials (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- One-time price / discount approvals typed at the POS (migration 008)
-- ---------------------------------------------------------------------
CREATE TABLE price_approvals (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  token_hash        CHAR(64)      NOT NULL,
  branch_id         INT UNSIGNED  NOT NULL,
  cashier_id        INT UNSIGNED  NOT NULL,
  approver_id       INT UNSIGNED  NOT NULL,
  product_id        INT UNSIGNED  NULL,
  price             DECIMAL(10,2) NULL,
  discount_percent  DECIMAL(5,2)  NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at        DATETIME      NOT NULL,
  used_at           DATETIME      NULL,
  sale_id           INT UNSIGNED  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_price_approvals_token (token_hash),
  KEY idx_price_approvals_cashier (cashier_id, expires_at),
  KEY idx_price_approvals_branch (branch_id),
  KEY idx_price_approvals_approver (approver_id),
  KEY idx_price_approvals_product (product_id),
  KEY idx_price_approvals_sale (sale_id),
  CONSTRAINT fk_price_approvals_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_price_approvals_cashier FOREIGN KEY (cashier_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_price_approvals_approver FOREIGN KEY (approver_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_price_approvals_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_price_approvals_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_price_approvals_kind CHECK ((product_id IS NULL) = (price IS NULL) AND (product_id IS NULL) = (discount_percent IS NOT NULL)),
  CONSTRAINT chk_price_approvals_values CHECK ((price IS NULL OR price >= 0) AND (discount_percent IS NULL OR discount_percent BETWEEN 0 AND 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Branch-to-branch transfers (migration 007)
--   requested -> approved -> released (in transit) -> received
--   requested / approved -> cancelled
--   from_* location: the sending branch's POS location, set at release.
--   to_*   location: the receiving branch's POS location, set at receive.
-- ---------------------------------------------------------------------
CREATE TABLE stock_transfers (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  transfer_no       VARCHAR(30)   NOT NULL,
  from_branch_id    INT UNSIGNED  NOT NULL,
  to_branch_id      INT UNSIGNED  NOT NULL,
  status            ENUM('requested','approved','released','received','cancelled') NOT NULL DEFAULT 'requested',
  notes             VARCHAR(255)  NULL,
  from_warehouse_id INT UNSIGNED  NULL,
  from_location_id  INT UNSIGNED  NULL,
  to_warehouse_id   INT UNSIGNED  NULL,
  to_location_id    INT UNSIGNED  NULL,
  total_qty         INT           NOT NULL DEFAULT 0,
  total_cost        DECIMAL(12,2) NULL,
  receive_note      VARCHAR(255)  NULL,
  requested_by      INT UNSIGNED  NOT NULL,
  requested_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_by       INT UNSIGNED  NULL,
  approved_at       DATETIME      NULL,
  released_by       INT UNSIGNED  NULL,
  released_at       DATETIME      NULL,
  received_by       INT UNSIGNED  NULL,
  received_at       DATETIME      NULL,
  cancelled_by      INT UNSIGNED  NULL,
  cancelled_at      DATETIME      NULL,
  cancel_reason     VARCHAR(255)  NULL,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transfers_no (transfer_no),
  KEY idx_transfers_from_status (from_branch_id, status),
  KEY idx_transfers_to_status (to_branch_id, status),
  KEY idx_transfers_from_location (from_location_id, from_warehouse_id, from_branch_id),
  KEY idx_transfers_to_location (to_location_id, to_warehouse_id, to_branch_id),
  KEY idx_transfers_requested_by (requested_by),
  KEY idx_transfers_approved_by (approved_by),
  KEY idx_transfers_released_by (released_by),
  KEY idx_transfers_received_by (received_by),
  KEY idx_transfers_cancelled_by (cancelled_by),
  CONSTRAINT fk_transfers_from_branch FOREIGN KEY (from_branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_transfers_to_branch FOREIGN KEY (to_branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_transfers_from_location FOREIGN KEY (from_location_id, from_warehouse_id, from_branch_id)
    REFERENCES storage_locations (id, warehouse_id, branch_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_transfers_to_location FOREIGN KEY (to_location_id, to_warehouse_id, to_branch_id)
    REFERENCES storage_locations (id, warehouse_id, branch_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_transfers_requested_by FOREIGN KEY (requested_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_transfers_approved_by FOREIGN KEY (approved_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_transfers_released_by FOREIGN KEY (released_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_transfers_received_by FOREIGN KEY (received_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_transfers_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_transfers_branches CHECK (from_branch_id <> to_branch_id),
  CONSTRAINT chk_transfers_from_pair CHECK ((from_location_id IS NULL) = (from_warehouse_id IS NULL)),
  CONSTRAINT chk_transfers_to_pair CHECK ((to_location_id IS NULL) = (to_warehouse_id IS NULL)),
  CONSTRAINT chk_transfers_released CHECK (status IN ('requested','approved','cancelled') OR from_location_id IS NOT NULL),
  CONSTRAINT chk_transfers_received CHECK (status <> 'received' OR to_location_id IS NOT NULL),
  CONSTRAINT chk_transfers_total_qty CHECK (total_qty >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- qty_requested by the receiving branch; qty_approved by the sending branch
-- (0 = not sent); qty_released = qty_approved at release; qty_received at
-- receive (short = released - received, explained in receive_note).
-- unit_cost = the sending branch's average cost at release.
CREATE TABLE stock_transfer_lines (
  id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  transfer_id    INT UNSIGNED      NOT NULL,
  product_id     INT UNSIGNED      NOT NULL,
  qty_requested  INT               NOT NULL,
  qty_approved   INT               NULL,
  qty_released   INT               NULL,
  qty_received   INT               NULL,
  unit_cost      DECIMAL(12,4)     NULL,
  sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transfer_lines_product (transfer_id, product_id),
  KEY idx_transfer_lines_product (product_id),
  CONSTRAINT fk_transfer_lines_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_transfer_lines_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_transfer_lines_requested CHECK (qty_requested > 0),
  CONSTRAINT chk_transfer_lines_approved CHECK (qty_approved IS NULL OR qty_approved BETWEEN 0 AND qty_requested),
  CONSTRAINT chk_transfer_lines_released CHECK (qty_released IS NULL OR qty_released >= 0),
  CONSTRAINT chk_transfer_lines_received CHECK (qty_received IS NULL OR qty_received BETWEEN 0 AND qty_released),
  CONSTRAINT chk_transfer_lines_cost CHECK (unit_cost IS NULL OR unit_cost >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Serials released on a line (fixed at release). received: NULL = in
-- transit, 1 = arrived, 0 = missing on arrival (serial -> 'removed').
CREATE TABLE stock_transfer_serials (
  line_id    INT UNSIGNED NOT NULL,
  serial_id  INT UNSIGNED NOT NULL,
  received   TINYINT(1)   NULL,
  PRIMARY KEY (line_id, serial_id),
  KEY idx_transfer_serials_serial (serial_id),
  CONSTRAINT fk_transfer_serials_line FOREIGN KEY (line_id) REFERENCES stock_transfer_lines (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_transfer_serials_serial FOREIGN KEY (serial_id) REFERENCES product_serials (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Job orders (migration 009)
--   new -> assigned -> diagnosing -> (for_approval ->) in_repair
--   in_repair <-> waiting_parts, in_repair -> for_testing -> completed
--   for_testing -> in_repair (test failed); for_approval -> completed
--   (customer declined); new / assigned -> cancelled.
--   released / closed are used from Phase 10b.
--   customer_name / customer_phone are snapshots (walk-ins have no
--   customer_id). serial_id + warranty_until: the device was sold by us.
-- ---------------------------------------------------------------------
CREATE TABLE job_orders (
  id                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  job_no               VARCHAR(30)   NOT NULL,
  branch_id            INT UNSIGNED  NOT NULL,
  status               ENUM('new','assigned','diagnosing','for_approval','in_repair','waiting_parts','for_testing',
                            'completed','released','closed','cancelled') NOT NULL DEFAULT 'new',
  priority             ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  service_location     ENUM('in_shop','on_site') NOT NULL DEFAULT 'in_shop',
  customer_id          INT UNSIGNED  NULL,
  customer_name        VARCHAR(100)  NOT NULL,
  customer_phone       VARCHAR(30)   NOT NULL,
  contact_person       VARCHAR(100)  NULL,
  job_type_id          INT UNSIGNED  NULL,
  device_type_id       INT UNSIGNED  NULL,
  brand                VARCHAR(80)   NULL,
  model                VARCHAR(80)   NULL,
  serial_no            VARCHAR(80)   NULL,
  serial_id            INT UNSIGNED  NULL,
  warranty_until       DATE          NULL,
  accessories          VARCHAR(500)  NULL,
  device_condition     VARCHAR(500)  NULL,
  problem              VARCHAR(1000) NOT NULL,
  remarks              VARCHAR(500)  NULL,
  expected_at          DATE          NULL,
  technician_id        INT UNSIGNED  NULL,
  assigned_at          DATETIME      NULL,
  diagnosis            VARCHAR(2000) NULL,
  estimate             DECIMAL(12,2) NULL,
  diagnosed_at         DATETIME      NULL,
  approval             ENUM('not_needed','pending','approved','declined') NULL,
  approval_method      VARCHAR(20)   NULL,
  approval_by_name     VARCHAR(100)  NULL,
  approval_note        VARCHAR(255)  NULL,
  approval_recorded_by INT UNSIGNED  NULL,
  approval_at          DATETIME      NULL,
  resolution           VARCHAR(2000) NULL,
  labor                DECIMAL(12,2) NULL,
  completed_by         INT UNSIGNED  NULL,
  completed_at         DATETIME      NULL,
  cancelled_by         INT UNSIGNED  NULL,
  cancelled_at         DATETIME      NULL,
  cancel_reason        VARCHAR(255)  NULL,
  release_type         ENUM('paid','warranty','no_charge') NULL,
  released_to          VARCHAR(100)  NULL,
  release_note         VARCHAR(255)  NULL,
  released_by          INT UNSIGNED  NULL,
  released_at          DATETIME      NULL,
  sale_id              INT UNSIGNED  NULL,
  parent_job_id        INT UNSIGNED  NULL,
  created_by           INT UNSIGNED  NOT NULL,
  created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_job_orders_no (job_no),
  KEY idx_job_orders_branch_status (branch_id, status, priority),
  KEY idx_job_orders_technician (technician_id, status),
  KEY idx_job_orders_customer (customer_id),
  KEY idx_job_orders_serial_no (serial_no),
  KEY idx_job_orders_serial (serial_id),
  KEY idx_job_orders_job_type (job_type_id),
  KEY idx_job_orders_device_type (device_type_id),
  KEY idx_job_orders_created_by (created_by),
  KEY idx_job_orders_approval_by (approval_recorded_by),
  KEY idx_job_orders_completed_by (completed_by),
  KEY idx_job_orders_cancelled_by (cancelled_by),
  KEY idx_job_orders_released_by (released_by),
  KEY idx_job_orders_sale (sale_id),
  KEY idx_job_orders_parent (parent_job_id),
  KEY idx_job_orders_branch_created (branch_id, created_at),
  KEY idx_job_orders_completed (completed_at),
  KEY idx_job_orders_released (released_at),
  CONSTRAINT fk_job_orders_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_job_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_job_orders_job_type FOREIGN KEY (job_type_id) REFERENCES lookups (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_job_orders_device_type FOREIGN KEY (device_type_id) REFERENCES lookups (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_job_orders_serial FOREIGN KEY (serial_id) REFERENCES product_serials (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_job_orders_technician FOREIGN KEY (technician_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_job_orders_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_job_orders_approval_by FOREIGN KEY (approval_recorded_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_job_orders_completed_by FOREIGN KEY (completed_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_job_orders_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_job_orders_released_by FOREIGN KEY (released_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_job_orders_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_job_orders_parent FOREIGN KEY (parent_job_id) REFERENCES job_orders (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_job_orders_estimate CHECK (estimate IS NULL OR estimate >= 0),
  CONSTRAINT chk_job_orders_assigned CHECK (status IN ('new','cancelled') OR technician_id IS NOT NULL),
  CONSTRAINT chk_job_orders_labor CHECK (labor IS NULL OR labor >= 0),
  CONSTRAINT chk_job_orders_released CHECK ((status IN ('released','closed')) = (release_type IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Timeline: every status change (from_status -> to_status) and note.
CREATE TABLE job_order_events (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  job_order_id INT UNSIGNED  NOT NULL,
  user_id      INT UNSIGNED  NOT NULL,
  action       VARCHAR(30)   NOT NULL,
  from_status  VARCHAR(20)   NULL,
  to_status    VARCHAR(20)   NULL,
  note         VARCHAR(2000) NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_job_events_job (job_order_id, id),
  KEY idx_job_events_user (user_id),
  CONSTRAINT fk_job_events_job FOREIGN KEY (job_order_id) REFERENCES job_orders (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_job_events_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Job parts (migration 010): requested -> issued (job custody) -> used / returned.
CREATE TABLE job_order_parts (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  job_order_id  INT UNSIGNED  NOT NULL,
  product_id    INT UNSIGNED  NOT NULL,
  status        ENUM('requested','issued','cancelled') NOT NULL DEFAULT 'requested',
  qty_requested INT           NOT NULL,
  qty_issued    INT           NULL,
  qty_used      INT           NOT NULL DEFAULT 0,
  qty_returned  INT           NOT NULL DEFAULT 0,
  unit_cost     DECIMAL(12,4) NULL,
  note          VARCHAR(255)  NULL,
  requested_by  INT UNSIGNED  NOT NULL,
  requested_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  issued_by     INT UNSIGNED  NULL,
  issued_at     DATETIME      NULL,
  cancelled_by  INT UNSIGNED  NULL,
  cancelled_at  DATETIME      NULL,
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_job_parts_job (job_order_id, status),
  KEY idx_job_parts_product (product_id),
  KEY idx_job_parts_requested_by (requested_by),
  KEY idx_job_parts_issued_by (issued_by),
  KEY idx_job_parts_cancelled_by (cancelled_by),
  CONSTRAINT fk_job_parts_job FOREIGN KEY (job_order_id) REFERENCES job_orders (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_job_parts_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_job_parts_requested_by FOREIGN KEY (requested_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_job_parts_issued_by FOREIGN KEY (issued_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_job_parts_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_job_parts_requested CHECK (qty_requested > 0),
  CONSTRAINT chk_job_parts_issued CHECK (qty_issued IS NULL OR qty_issued BETWEEN 1 AND qty_requested),
  CONSTRAINT chk_job_parts_status CHECK ((status = 'issued') = (qty_issued IS NOT NULL)),
  CONSTRAINT chk_job_parts_custody CHECK (qty_used >= 0 AND qty_returned >= 0 AND qty_used + qty_returned <= COALESCE(qty_issued, 0)),
  CONSTRAINT chk_job_parts_cost CHECK (unit_cost IS NULL OR unit_cost >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Serials issued on a parts line: issued (in custody) -> used (installed) | returned (back in stock).
CREATE TABLE job_order_part_serials (
  part_id   INT UNSIGNED NOT NULL,
  serial_id INT UNSIGNED NOT NULL,
  state     ENUM('issued','used','returned') NOT NULL DEFAULT 'issued',
  PRIMARY KEY (part_id, serial_id),
  KEY idx_job_part_serials_serial (serial_id),
  CONSTRAINT fk_job_part_serials_part FOREIGN KEY (part_id) REFERENCES job_order_parts (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_job_part_serials_serial FOREIGN KEY (serial_id) REFERENCES product_serials (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Job types of a job (one or more; job_orders.job_type_id = the first) and helper technicians
-- (job_orders.technician_id = the lead). Migration 017.
CREATE TABLE job_order_types (
  job_order_id  INT UNSIGNED NOT NULL,
  lookup_id     INT UNSIGNED NOT NULL,
  PRIMARY KEY (job_order_id, lookup_id),
  KEY idx_job_order_types_lookup (lookup_id),
  CONSTRAINT fk_job_order_types_job FOREIGN KEY (job_order_id) REFERENCES job_orders (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_job_order_types_lookup FOREIGN KEY (lookup_id) REFERENCES lookups (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_order_technicians (
  job_order_id  INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  added_by      INT UNSIGNED NOT NULL,
  added_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (job_order_id, user_id),
  KEY idx_job_order_technicians_user (user_id),
  KEY idx_job_order_technicians_added_by (added_by),
  CONSTRAINT fk_job_order_technicians_job FOREIGN KEY (job_order_id) REFERENCES job_orders (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_job_order_technicians_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_job_order_technicians_added_by FOREIGN KEY (added_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales.job_order_id is added here because job_orders is created after sales.
ALTER TABLE sales
  ADD CONSTRAINT fk_sales_job_order FOREIGN KEY (job_order_id) REFERENCES job_orders (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- Purchase requests
--   requested -> approved (qty_approved per line, never by the requester)
--             -> ordered (every approved unit is on a purchase order)
--   requested -> rejected (note) | cancelled (reason; also approved while
--   nothing is on a purchase order yet).
-- ---------------------------------------------------------------------
CREATE TABLE purchase_requests (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pr_no          VARCHAR(30)  NOT NULL,
  branch_id      INT UNSIGNED NOT NULL,
  status         ENUM('requested','approved','ordered','rejected','cancelled') NOT NULL DEFAULT 'requested',
  needed_by      DATE         NULL,
  purpose        VARCHAR(255) NULL,
  job_order_id   INT UNSIGNED NULL,
  total_qty      INT          NOT NULL DEFAULT 0,
  requested_by   INT UNSIGNED NOT NULL,
  requested_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_by     INT UNSIGNED NULL,
  decided_at     DATETIME     NULL,
  decision_note  VARCHAR(255) NULL,
  cancelled_by   INT UNSIGNED NULL,
  cancelled_at   DATETIME     NULL,
  cancel_reason  VARCHAR(255) NULL,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_purchase_requests_no (pr_no),
  KEY idx_purchase_requests_branch (branch_id, status, requested_at),
  KEY idx_purchase_requests_job (job_order_id),
  KEY idx_purchase_requests_requested_by (requested_by),
  KEY idx_purchase_requests_decided_by (decided_by),
  KEY idx_purchase_requests_cancelled_by (cancelled_by),
  CONSTRAINT fk_purchase_requests_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_purchase_requests_job FOREIGN KEY (job_order_id) REFERENCES job_orders (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_purchase_requests_requested_by FOREIGN KEY (requested_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_purchase_requests_decided_by FOREIGN KEY (decided_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_purchase_requests_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_request_lines (
  id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  request_id     INT UNSIGNED      NOT NULL,
  product_id     INT UNSIGNED      NOT NULL,
  end_user       VARCHAR(100)      NULL,
  qty_requested  INT               NOT NULL,
  qty_approved   INT               NULL,
  sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_purchase_request_lines_product (request_id, product_id),
  KEY idx_purchase_request_lines_product (product_id),
  CONSTRAINT fk_purchase_request_lines_request FOREIGN KEY (request_id) REFERENCES purchase_requests (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_purchase_request_lines_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_purchase_request_lines_qty CHECK (qty_requested > 0 AND (qty_approved IS NULL OR qty_approved BETWEEN 0 AND qty_requested))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Purchase orders (PO Internal)
--   draft (no number, editable) -> pending (sent for approval)
--   -> approved (po_no PO-<branch>-<year>-NNNNNN, printable, sent to the
--   supplier; never approved by its creator) -> partial -> received
--   (every line fully received through posted RRs). closed = the rest will
--   not come (reason). cancelled = approved but nothing received (reason).
--   pending -> draft again (returned with a note).
--   Delivery goes to the branch's POS location (warehouse_id, location_id).
-- ---------------------------------------------------------------------
CREATE TABLE purchase_orders (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  po_no           VARCHAR(30)   NULL,
  branch_id       INT UNSIGNED  NOT NULL,
  warehouse_id    INT UNSIGNED  NOT NULL,
  location_id     INT UNSIGNED  NOT NULL,
  supplier_id     INT UNSIGNED  NOT NULL,
  status          ENUM('draft','pending','approved','partial','received','closed','cancelled') NOT NULL DEFAULT 'draft',
  order_date      DATE          NOT NULL,
  expected_date   DATE          NULL,
  payment_terms   VARCHAR(60)   NULL,
  contact_person  VARCHAR(100)  NULL,
  contact_number  VARCHAR(60)   NULL,
  ship_to         VARCHAR(255)  NULL,
  forwarder       VARCHAR(100)  NULL,
  notes           VARCHAR(500)  NULL,
  total_qty       INT           NOT NULL DEFAULT 0,
  total_amount    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  created_by      INT UNSIGNED  NOT NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_by    INT UNSIGNED  NULL,
  submitted_at    DATETIME      NULL,
  return_note     VARCHAR(255)  NULL,
  approved_by     INT UNSIGNED  NULL,
  approved_at     DATETIME      NULL,
  closed_by       INT UNSIGNED  NULL,
  closed_at       DATETIME      NULL,
  close_reason    VARCHAR(255)  NULL,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_purchase_orders_no (po_no),
  KEY idx_purchase_orders_branch (branch_id, status, order_date),
  KEY idx_purchase_orders_supplier (supplier_id),
  KEY idx_purchase_orders_location (location_id, warehouse_id, branch_id),
  KEY idx_purchase_orders_expected (expected_date),
  KEY idx_purchase_orders_created_by (created_by),
  KEY idx_purchase_orders_submitted_by (submitted_by),
  KEY idx_purchase_orders_approved_by (approved_by),
  KEY idx_purchase_orders_closed_by (closed_by),
  CONSTRAINT fk_purchase_orders_location FOREIGN KEY (location_id, warehouse_id, branch_id)
    REFERENCES storage_locations (id, warehouse_id, branch_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_purchase_orders_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_purchase_orders_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_purchase_orders_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_purchase_orders_approved_by FOREIGN KEY (approved_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_purchase_orders_closed_by FOREIGN KEY (closed_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_purchase_orders_no CHECK ((po_no IS NULL) = (status IN ('draft','pending')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_order_lines (
  id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  po_id         INT UNSIGNED      NOT NULL,
  product_id    INT UNSIGNED      NOT NULL,
  end_user      VARCHAR(100)      NULL,
  qty_ordered   INT               NOT NULL,
  qty_received  INT               NOT NULL DEFAULT 0,
  unit_cost     DECIMAL(12,4)     NOT NULL,
  line_total    DECIMAL(14,2)     NOT NULL,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_purchase_order_lines_product (po_id, product_id),
  KEY idx_purchase_order_lines_product (product_id),
  CONSTRAINT fk_purchase_order_lines_po FOREIGN KEY (po_id) REFERENCES purchase_orders (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_purchase_order_lines_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_purchase_order_lines_qty CHECK (qty_ordered > 0 AND qty_received BETWEEN 0 AND qty_ordered),
  CONSTRAINT chk_purchase_order_lines_cost CHECK (unit_cost >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PR lines a PO line orders (one PO line per product can cover several requests).
CREATE TABLE purchase_order_request_lines (
  po_line_id       INT UNSIGNED NOT NULL,
  request_line_id  INT UNSIGNED NOT NULL,
  qty              INT          NOT NULL,
  PRIMARY KEY (po_line_id, request_line_id),
  KEY idx_po_request_lines_request (request_line_id),
  CONSTRAINT fk_po_request_lines_po_line FOREIGN KEY (po_line_id) REFERENCES purchase_order_lines (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_po_request_lines_request_line FOREIGN KEY (request_line_id) REFERENCES purchase_request_lines (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_po_request_lines_qty CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- receiving_reports.po_id / receiving_items.po_line_id FKs (purchase tables are created after receiving).
ALTER TABLE receiving_reports
  ADD CONSTRAINT fk_receiving_po FOREIGN KEY (po_id) REFERENCES purchase_orders (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE receiving_items
  ADD CONSTRAINT fk_receiving_items_po_line FOREIGN KEY (po_line_id) REFERENCES purchase_order_lines (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- Customer orders (PO Outgoing)
--   draft (no number) -> pending (for confirmation) -> confirmed (order_no
--   CO-<branch>-<year>-NNNNNN, never by its preparer; reserves qty_ordered -
--   qty_delivered of every line at location_id) -> partial -> delivered ->
--   completed (everything delivered and billed). closed = the rest will not be
--   delivered (reason; frees the reservation). cancelled = confirmed, nothing
--   delivered (reason). pending -> draft (returned with a note).
--   Prices are VAT-exclusive like the POS; suggested_price = products.price
--   when the line was saved, price_reason when lower.
-- ---------------------------------------------------------------------
CREATE TABLE customer_orders (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  order_no          VARCHAR(30)   NULL,
  branch_id         INT UNSIGNED  NOT NULL,
  warehouse_id      INT UNSIGNED  NOT NULL,
  location_id       INT UNSIGNED  NOT NULL,
  customer_id       INT UNSIGNED  NOT NULL,
  quotation_id      INT UNSIGNED  NULL,
  customer_name     VARCHAR(100)  NOT NULL,
  customer_address  VARCHAR(255)  NULL,
  customer_po_no    VARCHAR(60)   NOT NULL,
  customer_po_date  DATE          NULL,
  end_user          VARCHAR(150)  NULL,
  place_of_delivery VARCHAR(255)  NULL,
  delivery_term     VARCHAR(100)  NULL,
  due_date          DATE          NULL,
  payment_term      VARCHAR(100)  NULL,
  procurement_mode  VARCHAR(60)   NULL,
  award_ref         VARCHAR(100)  NULL,
  notes             VARCHAR(500)  NULL,
  status            ENUM('draft','pending','confirmed','partial','delivered','completed','closed','cancelled') NOT NULL DEFAULT 'draft',
  total_qty         INT           NOT NULL DEFAULT 0,
  subtotal          DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  created_by        INT UNSIGNED  NOT NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_by      INT UNSIGNED  NULL,
  submitted_at      DATETIME      NULL,
  return_note       VARCHAR(255)  NULL,
  confirmed_by      INT UNSIGNED  NULL,
  confirmed_at      DATETIME      NULL,
  closed_by         INT UNSIGNED  NULL,
  closed_at         DATETIME      NULL,
  close_reason      VARCHAR(255)  NULL,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_orders_no (order_no),
  KEY idx_customer_orders_branch (branch_id, status, created_at),
  KEY idx_customer_orders_location (location_id, warehouse_id, branch_id),
  KEY idx_customer_orders_customer (customer_id),
  KEY idx_customer_orders_po (customer_po_no),
  KEY idx_customer_orders_due (due_date),
  KEY idx_customer_orders_created_by (created_by),
  KEY idx_customer_orders_submitted_by (submitted_by),
  KEY idx_customer_orders_confirmed_by (confirmed_by),
  KEY idx_customer_orders_closed_by (closed_by),
  KEY idx_customer_orders_quotation (quotation_id),
  CONSTRAINT fk_customer_orders_location FOREIGN KEY (location_id, warehouse_id, branch_id)
    REFERENCES storage_locations (id, warehouse_id, branch_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_orders_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_orders_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_orders_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_orders_closed_by FOREIGN KEY (closed_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_customer_orders_no CHECK ((order_no IS NULL) = (status IN ('draft','pending')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_order_lines (
  id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  order_id         INT UNSIGNED      NOT NULL,
  product_id       INT UNSIGNED      NOT NULL,
  qty_ordered      INT               NOT NULL,
  qty_delivered    INT               NOT NULL DEFAULT 0,
  qty_billed       INT               NOT NULL DEFAULT 0,
  unit_price       DECIMAL(12,2)     NOT NULL,
  suggested_price  DECIMAL(12,2)     NOT NULL,
  price_reason     VARCHAR(255)      NULL,
  line_total       DECIMAL(14,2)     NOT NULL,
  sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_order_lines_product (order_id, product_id),
  KEY idx_customer_order_lines_product (product_id),
  CONSTRAINT fk_customer_order_lines_order FOREIGN KEY (order_id) REFERENCES customer_orders (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_order_lines_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_customer_order_lines_qty CHECK (qty_ordered > 0 AND qty_delivered BETWEEN 0 AND qty_ordered
                                                 AND qty_billed BETWEEN 0 AND qty_delivered),
  CONSTRAINT chk_customer_order_lines_price CHECK (unit_price >= 0 AND suggested_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Delivery receipts
--   released (stock left the order's location: movement 'delivery', cost =
--   branch average snapshot; serials 'delivered') -> delivered (received by,
--   date, acceptance / IAR reference). released and not billed -> cancelled
--   (goods back to stock: 'delivery_return'). sale_id = the bill.
-- ---------------------------------------------------------------------
CREATE TABLE customer_deliveries (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  dr_no           VARCHAR(30)   NOT NULL,
  order_id        INT UNSIGNED  NOT NULL,
  branch_id       INT UNSIGNED  NOT NULL,
  warehouse_id    INT UNSIGNED  NOT NULL,
  location_id     INT UNSIGNED  NOT NULL,
  status          ENUM('released','delivered','cancelled') NOT NULL DEFAULT 'released',
  delivered_by    VARCHAR(100)  NULL,
  notes           VARCHAR(255)  NULL,
  total_qty       INT           NOT NULL DEFAULT 0,
  total_cost      DECIMAL(14,2) NULL,
  sale_id         INT UNSIGNED  NULL,
  released_by     INT UNSIGNED  NOT NULL,
  released_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_by     VARCHAR(150)  NULL,
  received_date   DATE          NULL,
  acceptance_ref  VARCHAR(60)   NULL,
  confirmed_by    INT UNSIGNED  NULL,
  confirmed_at    DATETIME      NULL,
  cancelled_by    INT UNSIGNED  NULL,
  cancelled_at    DATETIME      NULL,
  cancel_reason   VARCHAR(255)  NULL,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_deliveries_no (dr_no),
  KEY idx_customer_deliveries_order (order_id, status),
  KEY idx_customer_deliveries_branch (branch_id, status, released_at),
  KEY idx_customer_deliveries_location (location_id, warehouse_id, branch_id),
  KEY idx_customer_deliveries_sale (sale_id),
  KEY idx_customer_deliveries_released_by (released_by),
  KEY idx_customer_deliveries_confirmed_by (confirmed_by),
  KEY idx_customer_deliveries_cancelled_by (cancelled_by),
  CONSTRAINT fk_customer_deliveries_order FOREIGN KEY (order_id) REFERENCES customer_orders (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_deliveries_location FOREIGN KEY (location_id, warehouse_id, branch_id)
    REFERENCES storage_locations (id, warehouse_id, branch_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_deliveries_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_deliveries_released_by FOREIGN KEY (released_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_deliveries_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_deliveries_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_delivery_lines (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  delivery_id    INT UNSIGNED  NOT NULL,
  order_line_id  INT UNSIGNED  NOT NULL,
  product_id     INT UNSIGNED  NOT NULL,
  qty            INT           NOT NULL,
  unit_cost      DECIMAL(12,4) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_delivery_lines (delivery_id, order_line_id),
  KEY idx_customer_delivery_lines_order_line (order_line_id),
  KEY idx_customer_delivery_lines_product (product_id),
  CONSTRAINT fk_customer_delivery_lines_delivery FOREIGN KEY (delivery_id) REFERENCES customer_deliveries (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_delivery_lines_order_line FOREIGN KEY (order_line_id) REFERENCES customer_order_lines (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_delivery_lines_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_customer_delivery_lines_qty CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_delivery_serials (
  line_id    INT UNSIGNED NOT NULL,
  serial_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (line_id, serial_id),
  KEY idx_customer_delivery_serials_serial (serial_id),
  CONSTRAINT fk_customer_delivery_serials_line FOREIGN KEY (line_id) REFERENCES customer_delivery_lines (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_delivery_serials_serial FOREIGN KEY (serial_id) REFERENCES product_serials (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales.customer_order_id FK (customer_orders is created after sales).
ALTER TABLE sales
  ADD CONSTRAINT fk_sales_customer_order FOREIGN KEY (customer_order_id) REFERENCES customer_orders (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- Quotations (QT-<branch>-<year>-NNNNNN at create; never deleted)
--   draft (editable) -> sent -> won (a customer order was made from it:
--   order_id) / lost (reason) ; draft / sent -> cancelled (reason);
--   sent -> draft (revise). Prices VAT-exclusive like the orders.
-- ---------------------------------------------------------------------
CREATE TABLE quotations (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  quote_no          VARCHAR(30)   NOT NULL,
  branch_id         INT UNSIGNED  NOT NULL,
  customer_id       INT UNSIGNED  NOT NULL,
  customer_name     VARCHAR(100)  NOT NULL,
  customer_address  VARCHAR(255)  NULL,
  attention         VARCHAR(100)  NULL,
  rfq_no            VARCHAR(60)   NULL,
  rfq_date          DATE          NULL,
  end_user          VARCHAR(150)  NULL,
  quote_date        DATE          NOT NULL,
  valid_until       DATE          NULL,
  delivery_term     VARCHAR(100)  NULL,
  payment_term      VARCHAR(100)  NULL,
  warranty          VARCHAR(100)  NULL,
  notes             VARCHAR(500)  NULL,
  status            ENUM('draft','sent','won','lost','cancelled') NOT NULL DEFAULT 'draft',
  total_qty         INT           NOT NULL DEFAULT 0,
  subtotal          DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  order_id          INT UNSIGNED  NULL,
  created_by        INT UNSIGNED  NOT NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_by           INT UNSIGNED  NULL,
  sent_at           DATETIME      NULL,
  closed_by         INT UNSIGNED  NULL,
  closed_at         DATETIME      NULL,
  close_reason      VARCHAR(255)  NULL,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_quotations_no (quote_no),
  KEY idx_quotations_branch (branch_id, status, created_at),
  KEY idx_quotations_customer (customer_id),
  KEY idx_quotations_rfq (rfq_no),
  KEY idx_quotations_order (order_id),
  KEY idx_quotations_created_by (created_by),
  KEY idx_quotations_sent_by (sent_by),
  KEY idx_quotations_closed_by (closed_by),
  CONSTRAINT fk_quotations_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_quotations_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_quotations_order FOREIGN KEY (order_id) REFERENCES customer_orders (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_quotations_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_quotations_sent_by FOREIGN KEY (sent_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_quotations_closed_by FOREIGN KEY (closed_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quotation_lines (
  id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  quotation_id     INT UNSIGNED      NOT NULL,
  product_id       INT UNSIGNED      NOT NULL,
  quantity         INT               NOT NULL,
  unit_price       DECIMAL(12,2)     NOT NULL,
  suggested_price  DECIMAL(12,2)     NOT NULL,
  price_reason     VARCHAR(255)      NULL,
  line_total       DECIMAL(14,2)     NOT NULL,
  sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_quotation_lines_product (quotation_id, product_id),
  KEY idx_quotation_lines_product (product_id),
  CONSTRAINT fk_quotation_lines_quotation FOREIGN KEY (quotation_id) REFERENCES quotations (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_quotation_lines_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_quotation_lines_qty CHECK (quantity > 0),
  CONSTRAINT chk_quotation_lines_price CHECK (unit_price >= 0 AND suggested_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- customer_orders.quotation_id FK (quotations is created after customer_orders).
ALTER TABLE customer_orders
  ADD CONSTRAINT fk_customer_orders_quotation FOREIGN KEY (quotation_id) REFERENCES quotations (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- Collections of on-account bills (collection receipts CR-<branch>-<year>-NNNNNN)
--   posted at once (one payment of one customer applied to its bills at the
--   branch: cash + EWT (BIR 2307) + VAT withheld (BIR 2306) per bill; the
--   bill's sales.settled_amount grows by them) -> cancelled (reason).
--   form_2307: pending while a withholding certificate is due, received (date).
-- ---------------------------------------------------------------------
CREATE TABLE collections (
  id                     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  collection_no          VARCHAR(30)   NOT NULL,
  branch_id              INT UNSIGNED  NOT NULL,
  customer_id            INT UNSIGNED  NOT NULL,
  customer_name          VARCHAR(100)  NOT NULL,
  collection_date        DATE          NOT NULL,
  method                 ENUM('cash','check','bank','gcash') NOT NULL,
  reference              VARCHAR(60)   NULL,
  bank_name              VARCHAR(60)   NULL,
  check_date             DATE          NULL,
  check_status           ENUM('none','on_hand','deposited','cleared','bounced') NOT NULL DEFAULT 'none',
  deposited_at           DATE          NULL,
  cleared_at             DATE          NULL,
  amount_received        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ewt_total              DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  vat_withheld_total     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total_credited         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  form_2307              ENUM('none','pending','received') NOT NULL DEFAULT 'none',
  form_2307_received_at  DATE          NULL,
  form_2307_by           INT UNSIGNED  NULL,
  notes                  VARCHAR(255)  NULL,
  status                 ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
  created_by             INT UNSIGNED  NOT NULL,
  created_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelled_by           INT UNSIGNED  NULL,
  cancelled_at           DATETIME      NULL,
  cancel_reason          VARCHAR(255)  NULL,
  updated_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_collections_no (collection_no),
  KEY idx_collections_branch (branch_id, status, collection_date),
  KEY idx_collections_customer (customer_id),
  KEY idx_collections_2307 (form_2307, branch_id),
  KEY idx_collections_created_by (created_by),
  KEY idx_collections_cancelled_by (cancelled_by),
  KEY idx_collections_2307_by (form_2307_by),
  KEY idx_collections_checks (check_status, branch_id, check_date),
  CONSTRAINT fk_collections_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_collections_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_collections_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_collections_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_collections_2307_by FOREIGN KEY (form_2307_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_collections_amounts CHECK (amount_received >= 0 AND ewt_total >= 0 AND vat_withheld_total >= 0
                                            AND total_credited = amount_received + ewt_total + vat_withheld_total)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE collection_lines (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  collection_id  INT UNSIGNED  NOT NULL,
  sale_id        INT UNSIGNED  NOT NULL,
  amount         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ewt_amount     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  vat_withheld   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  UNIQUE KEY uq_collection_lines (collection_id, sale_id),
  KEY idx_collection_lines_sale (sale_id),
  CONSTRAINT fk_collection_lines_collection FOREIGN KEY (collection_id) REFERENCES collections (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_collection_lines_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_collection_lines_amounts CHECK (amount >= 0 AND ewt_amount >= 0 AND vat_withheld >= 0
                                                 AND amount + ewt_amount + vat_withheld > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Supplier invoices (accounts payable, AP-<branch>-<year>-NNNNNN), one live per posted
-- receiving report: open -> paid (paid_amount = posted disbursement lines, cash +
-- EWT withheld); open with nothing paid -> cancelled (reason).
-- ---------------------------------------------------------------------
CREATE TABLE supplier_invoices (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  ap_no            VARCHAR(30)   NOT NULL,
  branch_id        INT UNSIGNED  NOT NULL,
  supplier_id      INT UNSIGNED  NOT NULL,
  receiving_id     INT UNSIGNED  NOT NULL,
  invoice_no       VARCHAR(60)   NOT NULL,
  invoice_date     DATE          NOT NULL,
  due_date         DATE          NOT NULL,
  amount           DECIMAL(14,2) NOT NULL,
  paid_amount      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  status           ENUM('open','paid','cancelled') NOT NULL DEFAULT 'open',
  notes            VARCHAR(255)  NULL,
  created_by       INT UNSIGNED  NOT NULL,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelled_by     INT UNSIGNED  NULL,
  cancelled_at     DATETIME      NULL,
  cancel_reason    VARCHAR(255)  NULL,
  updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_supplier_invoices_no (ap_no),
  KEY idx_supplier_invoices_branch (branch_id, status, due_date),
  KEY idx_supplier_invoices_supplier (supplier_id, status),
  KEY idx_supplier_invoices_receiving (receiving_id),
  KEY idx_supplier_invoices_created_by (created_by),
  KEY idx_supplier_invoices_cancelled_by (cancelled_by),
  CONSTRAINT fk_supplier_invoices_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_supplier_invoices_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_supplier_invoices_receiving FOREIGN KEY (receiving_id) REFERENCES receiving_reports (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_supplier_invoices_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_supplier_invoices_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_supplier_invoices_amounts CHECK (amount > 0 AND paid_amount >= 0 AND paid_amount <= amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Disbursement vouchers (DV-<branch>-<year>-NNNNNN): one payment to one supplier applied
-- to its open invoices at the branch (cash + EWT per invoice) -> cancelled (reason).
-- check_status for checks issued: issued -> cleared.
-- ---------------------------------------------------------------------
CREATE TABLE disbursements (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  dv_no            VARCHAR(30)   NOT NULL,
  branch_id        INT UNSIGNED  NOT NULL,
  supplier_id      INT UNSIGNED  NOT NULL,
  supplier_name    VARCHAR(120)  NOT NULL,
  payment_date     DATE          NOT NULL,
  method           ENUM('cash','check','bank','gcash') NOT NULL,
  reference        VARCHAR(60)   NULL,
  bank_name        VARCHAR(60)   NULL,
  check_date       DATE          NULL,
  check_status     ENUM('none','issued','cleared') NOT NULL DEFAULT 'none',
  cleared_at       DATE          NULL,
  amount_paid      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ewt_total        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total_settled    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  particulars      VARCHAR(255)  NULL,
  status           ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
  created_by       INT UNSIGNED  NOT NULL,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelled_by     INT UNSIGNED  NULL,
  cancelled_at     DATETIME      NULL,
  cancel_reason    VARCHAR(255)  NULL,
  updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_disbursements_no (dv_no),
  KEY idx_disbursements_branch (branch_id, status, payment_date),
  KEY idx_disbursements_supplier (supplier_id),
  KEY idx_disbursements_checks (check_status, branch_id),
  KEY idx_disbursements_created_by (created_by),
  KEY idx_disbursements_cancelled_by (cancelled_by),
  CONSTRAINT fk_disbursements_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_disbursements_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_disbursements_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_disbursements_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_disbursements_amounts CHECK (amount_paid >= 0 AND ewt_total >= 0 AND total_settled = amount_paid + ewt_total)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE disbursement_lines (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  disbursement_id  INT UNSIGNED  NOT NULL,
  invoice_id       INT UNSIGNED  NOT NULL,
  amount           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ewt_amount       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  UNIQUE KEY uq_disbursement_lines (disbursement_id, invoice_id),
  KEY idx_disbursement_lines_invoice (invoice_id),
  CONSTRAINT fk_disbursement_lines_disbursement FOREIGN KEY (disbursement_id) REFERENCES disbursements (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_disbursement_lines_invoice FOREIGN KEY (invoice_id) REFERENCES supplier_invoices (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_disbursement_lines_amounts CHECK (amount >= 0 AND ewt_amount >= 0 AND amount + ewt_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Optional attachments (photos / scanned PDFs) on PO Internal, PO Outgoing
-- and collection receipts (migration 016). Files in storage/attachments/,
-- served by pages/attachment.php after the document access check.
-- ---------------------------------------------------------------------
CREATE TABLE document_attachments (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  doc_type       ENUM('purchase_order','customer_order','collection') NOT NULL,
  doc_id         INT UNSIGNED  NOT NULL,
  branch_id      INT UNSIGNED  NOT NULL,
  label          VARCHAR(40)   NOT NULL,
  filename       VARCHAR(64)   NOT NULL,
  original_name  VARCHAR(150)  NOT NULL,
  mime           VARCHAR(40)   NOT NULL,
  size_bytes     INT UNSIGNED  NOT NULL,
  uploaded_by    INT UNSIGNED  NOT NULL,
  uploaded_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_by     INT UNSIGNED  NULL,
  deleted_at     DATETIME      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attachments_filename (filename),
  KEY idx_attachments_doc (doc_type, doc_id, deleted_at),
  KEY idx_attachments_branch (branch_id),
  KEY idx_attachments_uploaded_by (uploaded_by),
  KEY idx_attachments_deleted_by (deleted_by),
  CONSTRAINT fk_attachments_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_attachments_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_attachments_deleted_by FOREIGN KEY (deleted_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_attachments_size CHECK (size_bytes > 0)
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
  receiving_id        INT UNSIGNED    NULL,
  inventory_doc_id    INT UNSIGNED    NULL,
  stock_transfer_id   INT UNSIGNED    NULL,
  job_order_id        INT UNSIGNED    NULL,
  customer_delivery_id INT UNSIGNED   NULL,
  branch_id           INT UNSIGNED    NOT NULL,
  warehouse_id        INT UNSIGNED    NOT NULL,
  location_id         INT UNSIGNED    NOT NULL,
  type                ENUM('initial','sale','restock','adjustment','void','receiving','transfer','issue','write_off','count','transfer_out','transfer_in','job_issue','job_return','delivery','delivery_return') NOT NULL,
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
  KEY idx_movements_receiving (receiving_id),
  KEY idx_movements_inventory_doc (inventory_doc_id),
  KEY idx_movements_stock_transfer (stock_transfer_id),
  KEY idx_movements_job_order (job_order_id),
  KEY idx_movements_customer_delivery (customer_delivery_id),
  CONSTRAINT fk_movements_location FOREIGN KEY (location_id, warehouse_id, branch_id)
    REFERENCES storage_locations (id, warehouse_id, branch_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_movements_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_movements_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_movements_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_movements_receiving FOREIGN KEY (receiving_id) REFERENCES receiving_reports (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_movements_inventory_doc FOREIGN KEY (inventory_doc_id) REFERENCES inventory_docs (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_movements_stock_transfer FOREIGN KEY (stock_transfer_id) REFERENCES stock_transfers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_movements_job_order FOREIGN KEY (job_order_id) REFERENCES job_orders (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_movements_customer_delivery FOREIGN KEY (customer_delivery_id) REFERENCES customer_deliveries (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- In-app notifications (migration 018): one row per event, recipients
-- (users with the right permission at the branch + super admins, never
-- the person who did it) with their own read time.
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  branch_id   INT UNSIGNED    NULL,
  module      VARCHAR(30)     NOT NULL,
  event       VARCHAR(60)     NOT NULL,
  icon        VARCHAR(20)     NOT NULL,
  tone        VARCHAR(10)     NOT NULL DEFAULT 'blue',
  message     VARCHAR(255)    NOT NULL,
  ref         VARCHAR(60)     NULL,
  link        VARCHAR(255)    NULL,
  actor_id    INT UNSIGNED    NULL,
  actor_name  VARCHAR(100)    NULL,
  PRIMARY KEY (id),
  KEY idx_notifications_created (created_at),
  KEY idx_notifications_branch (branch_id),
  KEY idx_notifications_actor (actor_id),
  CONSTRAINT fk_notifications_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_recipients (
  notification_id  BIGINT UNSIGNED NOT NULL,
  user_id          INT UNSIGNED    NOT NULL,
  read_at          DATETIME        NULL,
  PRIMARY KEY (user_id, notification_id),
  KEY idx_notification_recipients_unread (user_id, read_at),
  KEY idx_notification_recipients_notification (notification_id),
  CONSTRAINT fk_notification_recipients_notification FOREIGN KEY (notification_id) REFERENCES notifications (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_notification_recipients_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE CASCADE
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
  KEY idx_audit_date (occurred_at),
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

-- Letterhead details from EXECOM's purchase order form (same as migrations/012).
UPDATE branches SET address = 'Perimeter Freedom Park, Maramag, Bukidnon', contact_no = '+63 917 157 9168 / 088 828 4767' WHERE code = 'MAR';
UPDATE branches SET address = 'Jose Un Building, Fortich Street, Malaybalay City', contact_no = '+63 917 845 8198 / 088 813 3925' WHERE code = 'MLB';
UPDATE branches SET address = '123 Pacana Street, Corner Tiano, Cagayan De Oro City' WHERE code = 'CDO';

-- One MAIN warehouse + one sellable GENERAL location per branch (ids = branch ids).
INSERT INTO warehouses (branch_id, code, name, is_default)
SELECT id, 'MAIN', 'Main Warehouse', 1 FROM branches ORDER BY id;

INSERT INTO storage_locations (warehouse_id, branch_id, code, name, is_sellable, is_default)
SELECT id, branch_id, 'GENERAL', 'General Stock', 1, 1 FROM warehouses ORDER BY id;

-- Plus DAMAGED + DISPLAY per warehouse, never sellable/default (same rows and ids as
-- migrations/006 gives a Phase 7a install).
INSERT INTO storage_locations (id, warehouse_id, branch_id, code, name, kind, is_sellable, is_default) VALUES
  ( 6, 1, 1, 'DAMAGED', 'Damaged Stock',  'damaged', 0, 0),
  ( 7, 1, 1, 'DISPLAY', 'Display / Demo', 'display', 0, 0),
  ( 8, 2, 2, 'DAMAGED', 'Damaged Stock',  'damaged', 0, 0),
  ( 9, 2, 2, 'DISPLAY', 'Display / Demo', 'display', 0, 0),
  (10, 3, 3, 'DAMAGED', 'Damaged Stock',  'damaged', 0, 0),
  (11, 3, 3, 'DISPLAY', 'Display / Demo', 'display', 0, 0),
  (12, 4, 4, 'DAMAGED', 'Damaged Stock',  'damaged', 0, 0),
  (13, 4, 4, 'DISPLAY', 'Display / Demo', 'display', 0, 0),
  (14, 5, 5, 'DAMAGED', 'Damaged Stock',  'damaged', 0, 0),
  (15, 5, 5, 'DISPLAY', 'Display / Demo', 'display', 0, 0);

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
  (18, 'audit_logs.view',     'Audit Log', 'View the audit log',                                       180),
  (19, 'products.cost',       'Inventory', 'See and edit unit cost',                                    95),
  (20, 'master_data.manage',  'Master Data', 'Manage categories, brands, models, units, customer types and service lists', 96),
  (21, 'suppliers.view',      'Suppliers', 'View suppliers',                                            97),
  (22, 'suppliers.manage',    'Suppliers', 'Add, edit and deactivate suppliers',                        98),
  (23, 'receiving.view',      'Receiving', 'View receiving reports',                                    82),
  (24, 'receiving.manage',    'Receiving', 'Create and edit receiving drafts',                          83),
  (25, 'receiving.post',      'Receiving', 'Post receiving reports - adds stock and sets cost',         84),
  (26, 'receiving.cancel',    'Receiving', 'Cancel posted receiving reports',                           85),
  (27, 'serials.view',        'Inventory', 'Look up serial numbers',                                    86),
  (28, 'inventory.integrity', 'Inventory', 'Run stock integrity checks',                                87),
  (29, 'inventory.transfer',  'Inventory', 'Move stock between locations of a branch',                  88),
  (30, 'inventory.damage',    'Inventory', 'Mark stock damaged and write off damaged stock',            89),
  (31, 'inventory.issue',     'Inventory', 'Issue stock for internal use and display units',           91),
  (32, 'counts.create',       'Inventory', 'Create stock counts and enter counted quantities',          92),
  (33, 'counts.approve',      'Inventory', 'Approve or cancel stock counts',                            93),
  (34, 'warehouses.manage',   'Branches',  'Manage warehouses and storage locations',                  155),
  (35, 'transfers.request',   'Transfers', 'Request stock from another branch',                        101),
  (36, 'transfers.approve',   'Transfers', 'Approve or cancel requests for this branch''s stock',       102),
  (37, 'transfers.release',   'Transfers', 'Release approved transfers (stock leaves the branch)',     103),
  (38, 'transfers.receive',   'Transfers', 'Receive incoming transfers (stock enters the branch)',     104),
  (39, 'pos.change_price',    'POS',       'Change the selling price within the role limit (reason when lower)', 11),
  (40, 'pos.discount',        'POS',       'Give a sale discount within the role limit',                12),
  (41, 'pos.price_override',  'POS',       'Approve prices / discounts beyond the limits or below cost', 13),
  (42, 'pos.view_cost',       'POS',       'Show unit cost and margin on the POS (toggle)',             14),
  (43, 'job_orders.view',     'Job Orders', 'View all job orders of the branch',                       105),
  (44, 'job_orders.create',   'Job Orders', 'Take in devices (new job orders) and edit intake details', 106),
  (45, 'job_orders.update',   'Job Orders', 'Work on assigned jobs: diagnosis, quotation, repair status, notes', 107),
  (46, 'job_orders.assign',   'Job Orders', 'Assign technicians, act on any job of the branch, cancel jobs', 108),
  (47, 'job_parts.issue',     'Job Orders', 'Issue parts to jobs and take back unused parts',          109),
  (48, 'job_orders.release',  'Job Orders', 'Bill completed jobs and release devices to the customer',  110),
  (49, 'purchasing.request',  'Purchasing', 'Create purchase requests (PR) for items the branch needs',  120),
  (50, 'purchasing.approve',  'Purchasing', 'Approve or reject purchase requests and purchase orders',   121),
  (51, 'purchasing.order',    'Purchasing', 'Prepare purchase orders to suppliers (PO Internal), close or cancel them', 122),
  (52, 'customer_orders.manage',  'Customer Orders', 'Enter customer purchase orders (PO Outgoing) and send them for confirmation', 130),
  (53, 'customer_orders.approve', 'Customer Orders', 'Confirm customer orders (reserves stock), close or cancel them', 131),
  (54, 'customer_orders.deliver', 'Customer Orders', 'Release delivery receipts (stock leaves the branch) and record the delivery', 132),
  (55, 'customer_orders.bill',    'Customer Orders', 'Bill delivered customer orders (cash, GCash, card or on account)', 133),
  (56, 'collections.cancel',      'Collections', 'Cancel collection receipts (the bills are open again)', 135),
  (57, 'collections.manage',      'Collections', 'Record collections of on-account bills (cash, check, bank, withholding taxes)', 134),
  (58, 'payables.cancel',         'Payables', 'Cancel supplier invoices and disbursement vouchers', 138),
  (59, 'payables.manage',         'Payables', 'Record supplier invoices and pay them (disbursement vouchers); shows costs', 137),
  (60, 'sales.charge',            'Collections', 'Sell on account at the POS and on job bills (credit customers, within their limit)', 136);

-- super_admin: is_super = 1 means every permission (no role_permissions rows).
INSERT INTO roles (id, code, name, description, is_system, is_super) VALUES
  (1, 'super_admin',  'Super Administrator',  'Full access to every module and every branch.', 1, 1),
  (2, 'branch_admin', 'Branch Administrator', 'Runs a branch: sales and voids, customers, stock adjustments, reports and branch users.', 1, 0),
  (3, 'cashier',      'Cashier',              'Sells at the POS and looks after customers.', 1, 0),
  (4, 'technician',   'Technician',           'Repairs devices: job orders assigned to them and the branch''s new jobs; looks up customers and stock.', 1, 0);

-- POS limits (percent): price below suggested / sale discount without approval (super admin: unlimited).
UPDATE roles SET max_price_drop = 20.00, max_discount = 20.00 WHERE code = 'branch_admin';
UPDATE roles SET max_price_drop = 5.00,  max_discount = 5.00  WHERE code = 'cashier';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE (r.code = 'branch_admin' AND p.perm_key IN ('pos.access', 'sales.view', 'sales.cancel', 'customers.view',
         'customers.edit', 'customers.delete', 'inventory.view', 'inventory.adjust', 'reports.view', 'users.view',
         'users.manage', 'audit_logs.view', 'suppliers.view', 'suppliers.manage', 'products.cost',
         'receiving.view', 'receiving.manage', 'receiving.post', 'receiving.cancel', 'serials.view',
         'inventory.integrity', 'inventory.transfer', 'inventory.damage', 'inventory.issue', 'counts.create',
         'counts.approve', 'warehouses.manage', 'transfers.request', 'transfers.approve', 'transfers.release',
         'transfers.receive', 'pos.change_price', 'pos.discount', 'pos.price_override', 'pos.view_cost',
         'job_orders.view', 'job_orders.create', 'job_orders.update', 'job_orders.assign', 'job_parts.issue',
         'job_orders.release', 'purchasing.request', 'purchasing.approve', 'purchasing.order', 'customer_orders.manage',
         'customer_orders.approve', 'customer_orders.deliver', 'customer_orders.bill', 'collections.manage',
         'collections.cancel', 'sales.charge', 'payables.manage', 'payables.cancel'))
   OR (r.code = 'cashier' AND p.perm_key IN ('pos.access', 'sales.view', 'customers.view', 'customers.edit',
         'inventory.view', 'serials.view', 'pos.change_price', 'pos.discount', 'job_orders.view', 'job_orders.create',
         'job_orders.release', 'purchasing.request', 'customer_orders.manage', 'customer_orders.bill',
         'collections.manage'))
   OR (r.code = 'technician' AND p.perm_key IN ('customers.view', 'inventory.view', 'serials.view', 'job_orders.create',
         'job_orders.update', 'purchasing.request'))
ORDER BY r.id, p.id;

-- Master data seeds (same rows and ids as migrations/004).
INSERT INTO units (id, code, name, sort_order) VALUES
  (1, 'PC',   'Piece', 1),
  (2, 'BOX',  'Box',   2),
  (3, 'SET',  'Set',   3),
  (4, 'PACK', 'Pack',  4),
  (5, 'REAM', 'Ream',  5),
  (6, 'ROLL', 'Roll',  6),
  (7, 'M',    'Meter', 7),
  (8, 'LOT',  'Lot',   8);

INSERT INTO customer_types (id, name, sort_order) VALUES
  (1, 'Walk-in / Individual', 1),
  (2, 'Government',           2),
  (3, 'Private Company',      3),
  (4, 'Reseller',             4),
  (5, 'School',               5);

INSERT INTO lookups (id, list, name, sort_order) VALUES
  ( 1, 'device_type',      'Laptop',                 1),
  ( 2, 'device_type',      'Desktop',                2),
  ( 3, 'device_type',      'Printer',                3),
  ( 4, 'device_type',      'Monitor',                4),
  ( 5, 'device_type',      'Network Device',         5),
  ( 6, 'device_type',      'Other',                  6),
  ( 7, 'job_type',         'Repair',                 1),
  ( 8, 'job_type',         'Cleaning / Maintenance', 2),
  ( 9, 'job_type',         'Installation',           3),
  (10, 'job_type',         'Check-up / Diagnosis',   4),
  (11, 'service_category', 'Hardware',               1),
  (12, 'service_category', 'Software',               2),
  (13, 'service_category', 'Network',                3),
  (14, 'service_category', 'Printer',                4),
  (15, 'warranty_type',    'No Warranty',            1),
  (16, 'warranty_type',    'Store Warranty',         2),
  (17, 'warranty_type',    'Supplier Warranty',      3),
  (18, 'warranty_type',    'Manufacturer Warranty',  4);
-- Intake checklists (Master Data: Accessories, Device Conditions, Common Problems) + more job / device types.
INSERT IGNORE INTO lookups (list, name, sort_order) VALUES
  ('accessory', 'Charger / Adapter', 1), ('accessory', 'Power cable', 2), ('accessory', 'Bag / Case / Sleeve', 3),
  ('accessory', 'Battery (removable)', 4), ('accessory', 'Mouse', 5), ('accessory', 'Keyboard', 6),
  ('accessory', 'USB cable', 7), ('accessory', 'HDMI / VGA cable', 8), ('accessory', 'Network (LAN) cable', 9),
  ('accessory', 'Original box', 10), ('accessory', 'Manual / Documents', 11), ('accessory', 'Warranty card / Receipt', 12),
  ('accessory', 'SD card / Flash drive', 13), ('accessory', 'External hard drive', 14), ('accessory', 'SIM card', 15),
  ('accessory', 'Stylus / Pen', 16), ('accessory', 'Remote control', 17), ('accessory', 'Ink / Toner cartridge', 18),
  ('accessory', 'Paper tray', 19), ('accessory', 'Antennas', 20), ('accessory', 'Screws / Brackets', 21),
  ('accessory', 'Headset / Earphones', 22), ('accessory', 'Webcam', 23), ('accessory', 'None (unit only)', 99),

  ('device_condition', 'Good / No visible damage', 1), ('device_condition', 'Minor scratches', 2),
  ('device_condition', 'Deep scratches / Scuffs', 3), ('device_condition', 'Dents', 4),
  ('device_condition', 'Cracked screen', 5), ('device_condition', 'Screen lines / Dead pixels', 6),
  ('device_condition', 'Cracked casing / Bezel', 7), ('device_condition', 'Broken hinge', 8),
  ('device_condition', 'Missing keys', 9), ('device_condition', 'Missing screws', 10),
  ('device_condition', 'Missing rubber feet', 11), ('device_condition', 'Loose / Damaged ports', 12),
  ('device_condition', 'Swollen battery', 13), ('device_condition', 'Liquid damage signs', 14),
  ('device_condition', 'Burnt smell / Burn marks', 15), ('device_condition', 'Very dusty / Dirty', 16),
  ('device_condition', 'Rust / Corrosion', 17), ('device_condition', 'Stickers / Labels on unit', 18),
  ('device_condition', 'Warranty seal broken', 19), ('device_condition', 'Previously opened / repaired', 20),
  ('device_condition', 'Does not power on (on arrival)', 21), ('device_condition', 'Missing parts / covers', 22),

  ('problem', 'No power / Will not turn on', 1), ('problem', 'No display / Black screen', 2),
  ('problem', 'Display flickering / Lines', 3), ('problem', 'Slow performance', 4),
  ('problem', 'Overheating / Shuts down by itself', 5), ('problem', 'Will not boot / Operating system error', 6),
  ('problem', 'Blue screen / Keeps restarting', 7), ('problem', 'Virus / Pop-ups / Malware', 8),
  ('problem', 'Battery not charging', 9), ('problem', 'Battery drains fast', 10),
  ('problem', 'Charging port loose', 11), ('problem', 'Keyboard not working / Missing keys', 12),
  ('problem', 'Touchpad / Mouse not working', 13), ('problem', 'No sound / Speaker problem', 14),
  ('problem', 'Camera / Microphone not working', 15), ('problem', 'Wi-Fi / Network problem', 16),
  ('problem', 'Bluetooth problem', 17), ('problem', 'USB ports not working', 18),
  ('problem', 'Noisy fan / Strange noise', 19), ('problem', 'Hard drive / SSD failure', 20),
  ('problem', 'Data backup / recovery needed', 21), ('problem', 'Software / MS Office installation', 22),
  ('problem', 'Windows reinstall / upgrade', 23), ('problem', 'Driver problem', 24),
  ('problem', 'Account locked / password reset', 25), ('problem', 'Printer: not printing', 26),
  ('problem', 'Printer: lines / faded print', 27), ('problem', 'Printer: paper jam', 28),
  ('problem', 'Printer: ink / toner error', 29), ('problem', 'Scanner not working', 30),
  ('problem', 'Router: no internet / drops', 31), ('problem', 'Dropped / Physical damage', 32),
  ('problem', 'Liquid spill', 33), ('problem', 'Preventive maintenance / cleaning', 34),

  ('job_type', 'Software / OS Installation', 11), ('job_type', 'Virus Removal', 12),
  ('job_type', 'Data Backup / Recovery', 13), ('job_type', 'Hardware Upgrade (RAM / SSD)', 14),
  ('job_type', 'Parts Replacement', 15), ('job_type', 'Screen Replacement', 16),
  ('job_type', 'Battery Replacement', 17), ('job_type', 'Keyboard Replacement', 18),
  ('job_type', 'Network Setup / Configuration', 19), ('job_type', 'Printer Service', 20),
  ('job_type', 'Warranty Claim / RMA', 21), ('job_type', 'On-site Service', 22),
  ('job_type', 'PC Assembly / Build', 23),

  ('device_type', 'Tablet', 11), ('device_type', 'Smartphone', 12), ('device_type', 'All-in-One PC', 13),
  ('device_type', 'Projector', 14), ('device_type', 'UPS', 15), ('device_type', 'Scanner', 16),
  ('device_type', 'CCTV / DVR', 17), ('device_type', 'Server', 18), ('device_type', 'Gaming Console', 19);


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
  ('receipt_footer', 'Thank you for choosing EXECOM Logistics! Keep this receipt for warranty claims.'),
  ('job_quote_threshold', '1000.00');

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

-- Branch average cost starts at the default cost (as migrations/005 seeds it).
INSERT INTO product_branches (product_id, branch_id, avg_cost)
SELECT sb.product_id, sb.branch_id, MAX(p.unit_cost)
  FROM stock_balances sb
  JOIN products p ON p.id = sb.product_id
 GROUP BY sb.product_id, sb.branch_id
 ORDER BY sb.product_id, sb.branch_id;

-- Sample product illustrations (files in assets/uploads/products/)
UPDATE products SET image = CONCAT('sample-', LOWER(code), '.png');

-- Every sample product is sold per piece (unit 1 = PC), as migrations/004 backfills.
UPDATE products SET unit_id = 1;
