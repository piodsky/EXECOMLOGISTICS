-- =====================================================================
--  Phase 5 migration: branches, branch stock, roles & permissions, audit log.
--
--  What it does (additive only; no table or column is dropped or renamed):
--    * New tables: branches, warehouses, storage_locations, stock_balances,
--      roles, permissions, role_permissions, user_branches,
--      customer_branches, audit_logs.
--    * Seeds 5 branches (MAR = main store), one MAIN warehouse + one GENERAL
--      sellable location per branch, the permission registry and the
--      4 system roles with their default grants.
--    * users.role ENUM('admin','cashier') -> VARCHAR(30) role code
--      (admin -> super_admin, cashier -> cashier) with FK to roles.code.
--    * Adds branch_id (NOT NULL) to users, sales, customers and
--      branch/warehouse/location (+ location_qty_after) to stock_movements.
--      ALL existing rows are assigned to MAR (main store); every customer
--      gets a visibility link to its home branch; every product gets one
--      stock_balances row at MAR/MAIN/GENERAL with qty = products.stock.
--      Existing stock_movements keep their quantity/stock_after untouched.
--
--  Safety:
--    * Idempotent: safe to run twice (IF NOT EXISTS, information_schema
--      guards, NOT EXISTS / IS NULL guarded seeds and backfills). Seeds of
--      branches, warehouses, locations, roles + default grants and stock
--      balances only run while their table is still empty, so a re-run never
--      re-creates a deleted branch or re-grants a revoked permission.
--    * Runs in strict mode, so a failed backfill stops the script with an
--      error instead of silently writing 0 into a NOT NULL column.
--    * NOT backwards compatible with the Phase 1-4 PHP code (role 'admin'
--      becomes 'super_admin'; sales/customers/users need branch_id).
--      Deploy it together with the Phase 5 code.
--    * Back up first (outside the web root):
--        C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %USERPROFILE%\execom-before-003.sql
--
--  Run (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\003_branches_permissions.sql
-- =====================================================================

SET @OLD_SQL_MODE = @@SESSION.sql_mode;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. New tables
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS branches (
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

CREATE TABLE IF NOT EXISTS warehouses (
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

-- (warehouse_id, branch_id) must match the warehouse's own branch.
CREATE TABLE IF NOT EXISTS storage_locations (
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

-- Per-location stock. products.stock stays the company total (= SUM(qty)).
CREATE TABLE IF NOT EXISTS stock_balances (
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

CREATE TABLE IF NOT EXISTS roles (
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

CREATE TABLE IF NOT EXISTS permissions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  perm_key    VARCHAR(50)  NOT NULL,
  module      VARCHAR(30)  NOT NULL,
  label       VARCHAR(120) NOT NULL,
  sort_order  SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_key (perm_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id        INT UNSIGNED NOT NULL,
  permission_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  KEY idx_role_permissions_permission (permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extra branches granted to a user (the home branch is users.branch_id).
CREATE TABLE IF NOT EXISTS user_branches (
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

-- Branches where a (shared) customer is visible; always includes the home branch.
CREATE TABLE IF NOT EXISTS customer_branches (
  customer_id  INT UNSIGNED NOT NULL,
  branch_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (customer_id, branch_id),
  KEY idx_customer_branches_branch (branch_id, customer_id),
  CONSTRAINT fk_customer_branches_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_customer_branches_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who did what. username/role are snapshots (they survive user edits/deletes).
CREATE TABLE IF NOT EXISTS audit_logs (
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

-- ---------------------------------------------------------------------
-- 2. Reference data (each block only seeds an empty table)
-- ---------------------------------------------------------------------
-- Address / contact / TIN stay NULL until the owner fills them in.
INSERT INTO branches (id, code, name, is_main)
SELECT t.id, t.code, t.name, t.is_main FROM (
            SELECT 1 AS id, 'MAR' AS code, 'Maramag City' AS name, 1 AS is_main
  UNION ALL SELECT 2, 'MLB', 'Malaybalay City',      0
  UNION ALL SELECT 3, 'CDO', 'Cagayan de Oro City',  0
  UNION ALL SELECT 4, 'DAV', 'Davao City',           0
  UNION ALL SELECT 5, 'VAL', 'Valencia City',        0
) t
WHERE NOT EXISTS (SELECT 1 FROM branches);

INSERT INTO warehouses (branch_id, code, name, is_default)
SELECT b.id, 'MAIN', 'Main Warehouse', 1 FROM branches b
WHERE NOT EXISTS (SELECT 1 FROM warehouses)
ORDER BY b.id;

INSERT INTO storage_locations (warehouse_id, branch_id, code, name, is_sellable, is_default)
SELECT w.id, w.branch_id, 'GENERAL', 'General Stock', 1, 1 FROM warehouses w
WHERE w.code = 'MAIN' AND NOT EXISTS (SELECT 1 FROM storage_locations)
ORDER BY w.id;

-- Permission registry (must match config/permissions.php). Adds missing keys only.
INSERT INTO permissions (perm_key, module, label, sort_order)
SELECT t.perm_key, t.module, t.label, t.sort_order FROM (
            SELECT 'pos.access' AS perm_key, 'POS' AS module, 'Open the POS and complete sales' AS label, 10 AS sort_order
  UNION ALL SELECT 'sales.view',          'Sales',     'View sales history and receipts',                           20
  UNION ALL SELECT 'sales.cancel',        'Sales',     'Void sales',                                                30
  UNION ALL SELECT 'customers.view',      'Customers', 'View customers',                                            40
  UNION ALL SELECT 'customers.edit',      'Customers', 'Add and edit customers',                                    50
  UNION ALL SELECT 'customers.delete',    'Customers', 'Deactivate and delete customers',                           60
  UNION ALL SELECT 'inventory.view',      'Inventory', 'View products and stock (read-only)',                       70
  UNION ALL SELECT 'inventory.adjust',    'Inventory', 'Adjust stock',                                              80
  UNION ALL SELECT 'products.manage',     'Inventory', 'Add, edit, deactivate and delete products',                 90
  UNION ALL SELECT 'reports.view',        'Reports',   'View reports and export CSV',                              100
  UNION ALL SELECT 'users.view',          'Users',     'View users',                                               110
  UNION ALL SELECT 'users.manage',        'Users',     'Add and edit users, reset passwords, activate/deactivate', 120
  UNION ALL SELECT 'users.delete',        'Users',     'Delete users',                                             130
  UNION ALL SELECT 'roles.manage',        'Roles',     'Manage roles and permissions',                             140
  UNION ALL SELECT 'branches.manage',     'Branches',  'Manage branch details',                                    150
  UNION ALL SELECT 'branches.access_all', 'Branches',  'Access all branches (view and switch)',                    160
  UNION ALL SELECT 'settings.manage',     'Settings',  'Manage company settings',                                  170
  UNION ALL SELECT 'audit_logs.view',     'Audit Log', 'View the audit log',                                       180
) t
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.perm_key = t.perm_key)
ORDER BY t.sort_order;

-- Roles + default grants: only on the first run (roles table empty), in one
-- transaction, so a re-run never re-grants something an admin revoked.
-- super_admin has is_super = 1 (implicitly every permission, no rows needed).
SET @seed_roles = (SELECT COUNT(*) = 0 FROM roles);
START TRANSACTION;
INSERT INTO roles (id, code, name, description, is_system, is_super)
SELECT t.id, t.code, t.name, t.description, 1, t.is_super FROM (
            SELECT 1 AS id, 'super_admin' AS code, 'Super Administrator' AS name,
                   'Full access to every module and every branch.' AS description, 1 AS is_super
  UNION ALL SELECT 2, 'branch_admin', 'Branch Administrator',
                   'Runs a branch: sales and voids, customers, stock adjustments, reports and branch users.', 0
  UNION ALL SELECT 3, 'cashier', 'Cashier',
                   'Sells at the POS and looks after customers.', 0
  UNION ALL SELECT 4, 'technician', 'Technician',
                   'Looks up customers and stock.', 0
) t
WHERE @seed_roles = 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE @seed_roles = 1 AND (
     (r.code = 'branch_admin' AND p.perm_key IN ('pos.access', 'sales.view', 'sales.cancel', 'customers.view',
        'customers.edit', 'customers.delete', 'inventory.view', 'inventory.adjust', 'reports.view', 'users.view',
        'users.manage', 'audit_logs.view'))
  OR (r.code = 'cashier' AND p.perm_key IN ('pos.access', 'sales.view', 'customers.view', 'customers.edit',
        'inventory.view'))
  OR (r.code = 'technician' AND p.perm_key IN ('customers.view', 'inventory.view'))
)
ORDER BY r.id, p.id;
COMMIT;

-- Main store default location: everything that exists today lives here.
SET @mar_branch = NULL, @mar_warehouse = NULL, @mar_location = NULL;
SELECT l.branch_id, l.warehouse_id, l.id INTO @mar_branch, @mar_warehouse, @mar_location
  FROM storage_locations l
  JOIN warehouses w ON w.id = l.warehouse_id AND w.branch_id = l.branch_id
  JOIN branches b ON b.id = l.branch_id
 WHERE b.code = 'MAR' AND w.is_default = 1 AND l.is_default = 1 AND l.is_sellable = 1
 ORDER BY w.id, l.id
 LIMIT 1;

-- ---------------------------------------------------------------------
-- 3. users: role ENUM -> role code, home branch
-- ---------------------------------------------------------------------
SET @ddl = IF((SELECT DATA_TYPE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role') = 'enum',
              'ALTER TABLE users MODIFY role VARCHAR(30) NOT NULL',
              'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Only legacy values are mapped: a custom role coded 'admin' (in roles) is left alone.
UPDATE users SET role = 'super_admin'
 WHERE role = 'admin' AND NOT EXISTS (SELECT 1 FROM roles WHERE code = 'admin');

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED NULL AFTER role,
  ADD INDEX IF NOT EXISTS idx_users_role (role),
  ADD INDEX IF NOT EXISTS idx_users_branch (branch_id);

UPDATE users SET branch_id = @mar_branch WHERE branch_id IS NULL;

SET @ddl = IF((SELECT IS_NULLABLE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'branch_id') = 'YES',
              'ALTER TABLE users MODIFY branch_id INT UNSIGNED NOT NULL',
              'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE users
  ADD CONSTRAINT fk_users_role FOREIGN KEY IF NOT EXISTS (role) REFERENCES roles (code)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  ADD CONSTRAINT fk_users_branch FOREIGN KEY IF NOT EXISTS (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- 4. sales: branch
-- ---------------------------------------------------------------------
ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED NULL AFTER sale_no,
  ADD INDEX IF NOT EXISTS idx_sales_branch_status_date (branch_id, status, created_at),
  ADD INDEX IF NOT EXISTS idx_sales_branch_user_date (branch_id, user_id, created_at);

UPDATE sales SET branch_id = @mar_branch WHERE branch_id IS NULL;

SET @ddl = IF((SELECT IS_NULLABLE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'branch_id') = 'YES',
              'ALTER TABLE sales MODIFY branch_id INT UNSIGNED NOT NULL',
              'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE sales
  ADD CONSTRAINT fk_sales_branch FOREIGN KEY IF NOT EXISTS (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- 5. customers: home branch + visibility links
-- ---------------------------------------------------------------------
ALTER TABLE customers
  ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED NULL AFTER address,
  ADD INDEX IF NOT EXISTS idx_customers_branch (branch_id);

UPDATE customers SET branch_id = @mar_branch WHERE branch_id IS NULL;

SET @ddl = IF((SELECT IS_NULLABLE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'branch_id') = 'YES',
              'ALTER TABLE customers MODIFY branch_id INT UNSIGNED NOT NULL',
              'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE customers
  ADD CONSTRAINT fk_customers_branch FOREIGN KEY IF NOT EXISTS (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

INSERT INTO customer_branches (customer_id, branch_id)
SELECT c.id, c.branch_id FROM customers c
WHERE NOT EXISTS (SELECT 1 FROM customer_branches cb WHERE cb.customer_id = c.id AND cb.branch_id = c.branch_id)
ORDER BY c.id;

-- ---------------------------------------------------------------------
-- 6. stock: per-location balances + location on every movement
-- ---------------------------------------------------------------------
INSERT INTO stock_balances (product_id, location_id, warehouse_id, branch_id, qty)
SELECT p.id, @mar_location, @mar_warehouse, @mar_branch, p.stock FROM products p
WHERE NOT EXISTS (SELECT 1 FROM stock_balances)
ORDER BY p.id;

ALTER TABLE stock_movements
  ADD COLUMN IF NOT EXISTS branch_id          INT UNSIGNED NULL AFTER sale_id,
  ADD COLUMN IF NOT EXISTS warehouse_id       INT UNSIGNED NULL AFTER branch_id,
  ADD COLUMN IF NOT EXISTS location_id        INT UNSIGNED NULL AFTER warehouse_id,
  ADD COLUMN IF NOT EXISTS location_qty_after INT          NULL AFTER stock_after,
  ADD INDEX IF NOT EXISTS idx_movements_branch_date (branch_id, created_at),
  ADD INDEX IF NOT EXISTS idx_movements_location (location_id, warehouse_id, branch_id);

-- Before Phase 5 there was one location, so its level equals the company level.
UPDATE stock_movements
   SET branch_id = @mar_branch, warehouse_id = @mar_warehouse, location_id = @mar_location,
       location_qty_after = stock_after
 WHERE branch_id IS NULL;

SET @ddl = IF((SELECT IS_NULLABLE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'branch_id') = 'YES',
              'ALTER TABLE stock_movements MODIFY branch_id INT UNSIGNED NOT NULL, MODIFY warehouse_id INT UNSIGNED NOT NULL, MODIFY location_id INT UNSIGNED NOT NULL',
              'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE stock_movements
  ADD CONSTRAINT fk_movements_location FOREIGN KEY IF NOT EXISTS (location_id, warehouse_id, branch_id)
    REFERENCES storage_locations (id, warehouse_id, branch_id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

SET SESSION sql_mode = @OLD_SQL_MODE;
