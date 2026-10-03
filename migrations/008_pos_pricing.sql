-- =====================================================================
--  Phase 9 migration: POS pricing - actual vs suggested price, per-role
--  price / discount limits, admin approval at the till + 4 permissions.
--
--  What it does (additive only; no table or column is dropped or renamed):
--    * roles: + max_price_drop, max_discount (DECIMAL(5,2), percent, 0-100).
--      Defaults set only when the columns are created by THIS run:
--      branch_admin 20 / 20, cashier 5 / 5 (others 0; super admin is unlimited).
--    * sale_items: + suggested_price (products.price at the time of sale; NULL
--      on older sales), price_reason, price_approved_by (FK users, SET NULL).
--      unit_price keeps its meaning: the price actually charged (before VAT).
--    * sales: + discount_approved_by (FK users, SET NULL).
--    * New table price_approvals: one-time approvals typed by an admin at the
--      till (token stored as SHA-256), for one line price or a sale discount.
--    * Permissions pos.change_price, pos.discount (cashier + branch_admin),
--      pos.price_override, pos.view_cost (branch_admin) - must match
--      config/permissions.php.
--
--  Safety:
--    * Idempotent: safe to run twice (IF NOT EXISTS, information_schema
--      guards). Default limits / grants only for columns / keys created by
--      THIS run, so a re-run never resets something an admin changed.
--    * Existing rows stay valid (new columns are NULL-able or defaulted).
--      Backwards compatible with the Phase 8 PHP code (it ignores the new
--      columns and table).
--    * Back up first (outside the web root):
--        C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-008.sql
--
--  Run (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\008_pos_pricing.sql
-- =====================================================================

SET @OLD_SQL_MODE = @@SESSION.sql_mode;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Role limits (percent below the suggested price / sale discount)
-- ---------------------------------------------------------------------
SET @new_limits = NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'max_price_drop');

ALTER TABLE roles
  ADD COLUMN IF NOT EXISTS max_price_drop DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER is_active,
  ADD COLUMN IF NOT EXISTS max_discount   DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER max_price_drop;

SET @ddl = IF(EXISTS (SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
                       WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND CONSTRAINT_NAME = 'chk_roles_limits'),
              'DO 0',
              'ALTER TABLE roles ADD CONSTRAINT chk_roles_limits CHECK (max_price_drop BETWEEN 0 AND 100 AND max_discount BETWEEN 0 AND 100)');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE roles SET max_price_drop = 20.00, max_discount = 20.00 WHERE @new_limits AND code = 'branch_admin';
UPDATE roles SET max_price_drop = 5.00,  max_discount = 5.00  WHERE @new_limits AND code = 'cashier';

-- ---------------------------------------------------------------------
-- 2. Sale lines / sales: what was suggested, why it changed, who approved
-- ---------------------------------------------------------------------
ALTER TABLE sale_items
  ADD COLUMN IF NOT EXISTS suggested_price   DECIMAL(10,2) NULL AFTER unit_price,
  ADD COLUMN IF NOT EXISTS price_reason      VARCHAR(255)  NULL AFTER suggested_price,
  ADD COLUMN IF NOT EXISTS price_approved_by INT UNSIGNED  NULL AFTER price_reason,
  ADD INDEX IF NOT EXISTS idx_items_price_approved_by (price_approved_by);

ALTER TABLE sale_items
  ADD CONSTRAINT fk_items_price_approved_by FOREIGN KEY IF NOT EXISTS (price_approved_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS discount_approved_by INT UNSIGNED NULL AFTER discount_amount,
  ADD INDEX IF NOT EXISTS idx_sales_discount_approved_by (discount_approved_by);

ALTER TABLE sales
  ADD CONSTRAINT fk_sales_discount_approved_by FOREIGN KEY IF NOT EXISTS (discount_approved_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- 3. One-time approvals typed at the till (5 minutes, one use)
--   product_id + price  = approval of one line's price
--   discount_percent    = approval of a sale discount
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS price_approvals (
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
-- 4. Permissions (must match config/permissions.php). Only keys new in
--    this run get the default grants.
-- ---------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_008_new_perms;
CREATE TEMPORARY TABLE tmp_008_new_perms (perm_key VARCHAR(50) NOT NULL PRIMARY KEY)
  ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_008_new_perms (perm_key)
SELECT t.perm_key FROM (
            SELECT 'pos.change_price' AS perm_key
  UNION ALL SELECT 'pos.discount'
  UNION ALL SELECT 'pos.price_override'
  UNION ALL SELECT 'pos.view_cost'
) t
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.perm_key = t.perm_key);

START TRANSACTION;
INSERT INTO permissions (perm_key, module, label, sort_order)
SELECT t.perm_key, t.module, t.label, t.sort_order FROM (
            SELECT 'pos.change_price' AS perm_key, 'POS' AS module, 'Change the selling price within the role limit (reason when lower)' AS label, 11 AS sort_order
  UNION ALL SELECT 'pos.discount',       'POS', 'Give a sale discount within the role limit',                                12
  UNION ALL SELECT 'pos.price_override', 'POS', 'Approve prices / discounts beyond the limits or below cost',                13
  UNION ALL SELECT 'pos.view_cost',      'POS', 'Show unit cost and margin on the POS (toggle)',                            14
) t
WHERE t.perm_key IN (SELECT perm_key FROM tmp_008_new_perms)
ORDER BY t.sort_order;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE ((r.code = 'branch_admin' AND p.perm_key IN ('pos.change_price', 'pos.discount', 'pos.price_override', 'pos.view_cost'))
    OR (r.code = 'cashier' AND p.perm_key IN ('pos.change_price', 'pos.discount')))
  AND p.perm_key IN (SELECT perm_key FROM tmp_008_new_perms)
ORDER BY r.id, p.id;
COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_008_new_perms;

SET SESSION sql_mode = @OLD_SQL_MODE;
