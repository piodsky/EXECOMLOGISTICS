-- =====================================================================
--  Phase 6 migration: master data (brands, models, units, customer types,
--  suppliers, contacts, simple lookup lists) + 4 permissions.
--
--  What it does (additive only; no table or column is dropped or renamed):
--    * New tables: brands, product_models, units, customer_types, suppliers,
--      supplier_contacts, customer_contacts, lookups.
--    * Seeds units (PC = Piece ...), customer types and the lookup lists
--      device_type / job_type / service_category / warranty_type.
--    * products: + brand_id, model_id, unit_id (all NULL-able, FK RESTRICT),
--      unit_cost, track_serial, warranty_days, specs (defaulted).
--      Every existing product gets unit_id = PC; nothing else is backfilled.
--      products.price keeps its meaning (= suggested selling price).
--    * customers: + customer_type_id (NULL, FK RESTRICT), tin (NULL).
--    * categories: unchanged (icon / sort_order / is_active already exist).
--    * Permissions master_data.manage, suppliers.view, suppliers.manage,
--      products.cost (must match config/permissions.php); branch_admin gets
--      suppliers.view, suppliers.manage and products.cost.
--
--  Safety:
--    * Idempotent: safe to run twice (IF NOT EXISTS, information_schema
--      guards, NOT EXISTS guarded seeds). Seeds only fill an empty table
--      (lookups: an empty list), so a re-run never re-creates a deleted
--      entry. Default grants are only given to permissions created by THIS
--      run, so a re-run never re-grants something an admin revoked.
--    * Existing rows stay valid: every new column is NULL-able or defaulted.
--      sales / sale_items / stock_movements are not touched.
--    * Backwards compatible with the Phase 5 PHP code (old code ignores the
--      new columns/tables), so it can run before the Phase 6 code ships.
--    * Back up first (outside the web root):
--        C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-004.sql
--
--  Run (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\004_master_data.sql
-- =====================================================================

SET @OLD_SQL_MODE = @@SESSION.sql_mode;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. New tables (company-wide master data, no branch_id)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS brands (
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
CREATE TABLE IF NOT EXISTS product_models (
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

CREATE TABLE IF NOT EXISTS units (
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

CREATE TABLE IF NOT EXISTS customer_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(60)  NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(20)  NOT NULL,
  name           VARCHAR(120) NOT NULL,
  tin            VARCHAR(20)  NULL,
  address        VARCHAR(255) NULL,
  phone          VARCHAR(30)  NULL,
  email          VARCHAR(120) NULL,
  payment_terms  VARCHAR(60)  NULL,
  notes          VARCHAR(255) NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_code (code),
  KEY idx_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_contacts (
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

CREATE TABLE IF NOT EXISTS customer_contacts (
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

-- Generic simple lists; list keys are registered in config/master-data.php.
CREATE TABLE IF NOT EXISTS lookups (
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

-- ---------------------------------------------------------------------
-- 2. Reference data (each block only seeds an empty table / list)
-- ---------------------------------------------------------------------
INSERT INTO units (id, code, name, sort_order)
SELECT t.id, t.code, t.name, t.id FROM (
            SELECT 1 AS id, 'PC' AS code, 'Piece' AS name
  UNION ALL SELECT 2, 'BOX',  'Box'
  UNION ALL SELECT 3, 'SET',  'Set'
  UNION ALL SELECT 4, 'PACK', 'Pack'
  UNION ALL SELECT 5, 'REAM', 'Ream'
  UNION ALL SELECT 6, 'ROLL', 'Roll'
  UNION ALL SELECT 7, 'M',    'Meter'
  UNION ALL SELECT 8, 'LOT',  'Lot'
) t
WHERE NOT EXISTS (SELECT 1 FROM units)
ORDER BY t.id;

INSERT INTO customer_types (id, name, sort_order)
SELECT t.id, t.name, t.id FROM (
            SELECT 1 AS id, 'Walk-in / Individual' AS name
  UNION ALL SELECT 2, 'Government'
  UNION ALL SELECT 3, 'Private Company'
  UNION ALL SELECT 4, 'Reseller'
  UNION ALL SELECT 5, 'School'
) t
WHERE NOT EXISTS (SELECT 1 FROM customer_types)
ORDER BY t.id;

INSERT INTO lookups (list, name, sort_order)
SELECT t.list, t.name, t.sort_order FROM (
            SELECT 'device_type' AS list, 'Laptop' AS name, 1 AS sort_order, 1 AS seq
  UNION ALL SELECT 'device_type',      'Desktop',                  2,  2
  UNION ALL SELECT 'device_type',      'Printer',                  3,  3
  UNION ALL SELECT 'device_type',      'Monitor',                  4,  4
  UNION ALL SELECT 'device_type',      'Network Device',           5,  5
  UNION ALL SELECT 'device_type',      'Other',                    6,  6
  UNION ALL SELECT 'job_type',         'Repair',                   1,  7
  UNION ALL SELECT 'job_type',         'Cleaning / Maintenance',   2,  8
  UNION ALL SELECT 'job_type',         'Installation',             3,  9
  UNION ALL SELECT 'job_type',         'Check-up / Diagnosis',     4, 10
  UNION ALL SELECT 'service_category', 'Hardware',                 1, 11
  UNION ALL SELECT 'service_category', 'Software',                 2, 12
  UNION ALL SELECT 'service_category', 'Network',                  3, 13
  UNION ALL SELECT 'service_category', 'Printer',                  4, 14
  UNION ALL SELECT 'warranty_type',    'No Warranty',              1, 15
  UNION ALL SELECT 'warranty_type',    'Store Warranty',           2, 16
  UNION ALL SELECT 'warranty_type',    'Supplier Warranty',        3, 17
  UNION ALL SELECT 'warranty_type',    'Manufacturer Warranty',    4, 18
) t
WHERE NOT EXISTS (SELECT 1 FROM lookups l WHERE l.list = t.list)
ORDER BY t.seq;

-- Permissions (must match config/permissions.php). Remember which keys are
-- new in this run: only those get the default branch_admin grants.
DROP TEMPORARY TABLE IF EXISTS tmp_004_new_perms;
CREATE TEMPORARY TABLE tmp_004_new_perms (perm_key VARCHAR(50) NOT NULL PRIMARY KEY)
  ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_004_new_perms (perm_key)
SELECT t.perm_key FROM (
            SELECT 'master_data.manage' AS perm_key
  UNION ALL SELECT 'suppliers.view'
  UNION ALL SELECT 'suppliers.manage'
  UNION ALL SELECT 'products.cost'
) t
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.perm_key = t.perm_key);

START TRANSACTION;
INSERT INTO permissions (perm_key, module, label, sort_order)
SELECT t.perm_key, t.module, t.label, t.sort_order FROM (
            SELECT 'products.cost' AS perm_key, 'Inventory' AS module, 'See and edit unit cost' AS label, 95 AS sort_order
  UNION ALL SELECT 'master_data.manage', 'Master Data', 'Manage categories, brands, models, units, customer types and service lists', 96
  UNION ALL SELECT 'suppliers.view',     'Suppliers',   'View suppliers',                       97
  UNION ALL SELECT 'suppliers.manage',   'Suppliers',   'Add, edit and deactivate suppliers',   98
) t
WHERE t.perm_key IN (SELECT perm_key FROM tmp_004_new_perms)
ORDER BY t.sort_order;

-- master_data.manage: super admin only (is_super covers it, no rows).
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code = 'branch_admin'
  AND p.perm_key IN ('suppliers.view', 'suppliers.manage', 'products.cost')
  AND p.perm_key IN (SELECT perm_key FROM tmp_004_new_perms)
ORDER BY r.id, p.id;
COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_004_new_perms;

-- ---------------------------------------------------------------------
-- 3. products: brand / model / unit, cost, serial tracking, warranty, specs
-- ---------------------------------------------------------------------
ALTER TABLE products
  ADD COLUMN IF NOT EXISTS brand_id      INT UNSIGNED      NULL AFTER category_id,
  ADD COLUMN IF NOT EXISTS model_id      INT UNSIGNED      NULL AFTER brand_id,
  ADD COLUMN IF NOT EXISTS unit_id       INT UNSIGNED      NULL AFTER model_id,
  ADD COLUMN IF NOT EXISTS specs         VARCHAR(500)      NULL AFTER description,
  ADD COLUMN IF NOT EXISTS unit_cost     DECIMAL(10,2)     NOT NULL DEFAULT 0.00 AFTER price,
  ADD COLUMN IF NOT EXISTS track_serial  TINYINT(1)        NOT NULL DEFAULT 0 AFTER reorder_level,
  ADD COLUMN IF NOT EXISTS warranty_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER track_serial,
  ADD INDEX IF NOT EXISTS idx_products_brand (brand_id),
  ADD INDEX IF NOT EXISTS idx_products_model_brand (model_id, brand_id),
  ADD INDEX IF NOT EXISTS idx_products_unit (unit_id);

ALTER TABLE products
  ADD CONSTRAINT fk_products_brand FOREIGN KEY IF NOT EXISTS (brand_id) REFERENCES brands (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  ADD CONSTRAINT fk_products_model FOREIGN KEY IF NOT EXISTS (model_id, brand_id)
    REFERENCES product_models (id, brand_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  ADD CONSTRAINT fk_products_unit FOREIGN KEY IF NOT EXISTS (unit_id) REFERENCES units (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

-- CHECK constraints have no IF NOT EXISTS in 10.4: guard via information_schema.
SET @ddl = IF(EXISTS (SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
                       WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
                         AND CONSTRAINT_NAME = 'chk_products_unit_cost'),
              'DO 0',
              'ALTER TABLE products ADD CONSTRAINT chk_products_unit_cost CHECK (unit_cost >= 0)');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(EXISTS (SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
                       WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
                         AND CONSTRAINT_NAME = 'chk_products_warranty_days'),
              'DO 0',
              'ALTER TABLE products ADD CONSTRAINT chk_products_warranty_days CHECK (warranty_days <= 3650)');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- A model needs its brand (the composite FK alone skips rows with brand_id NULL).
SET @ddl = IF(EXISTS (SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
                       WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
                         AND CONSTRAINT_NAME = 'chk_products_model_brand'),
              'DO 0',
              'ALTER TABLE products ADD CONSTRAINT chk_products_model_brand CHECK (model_id IS NULL OR brand_id IS NOT NULL)');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Every existing product is sold per piece.
UPDATE products
   SET unit_id = (SELECT u.id FROM units u WHERE u.code = 'PC')
 WHERE unit_id IS NULL
   AND EXISTS (SELECT 1 FROM units u WHERE u.code = 'PC');

-- ---------------------------------------------------------------------
-- 4. customers: customer type + TIN
-- ---------------------------------------------------------------------
ALTER TABLE customers
  ADD COLUMN IF NOT EXISTS customer_type_id INT UNSIGNED NULL AFTER address,
  ADD COLUMN IF NOT EXISTS tin              VARCHAR(20)  NULL AFTER customer_type_id,
  ADD INDEX IF NOT EXISTS idx_customers_type (customer_type_id);

ALTER TABLE customers
  ADD CONSTRAINT fk_customers_type FOREIGN KEY IF NOT EXISTS (customer_type_id) REFERENCES customer_types (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

SET SESSION sql_mode = @OLD_SQL_MODE;
