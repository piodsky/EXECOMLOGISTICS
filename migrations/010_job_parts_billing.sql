-- =====================================================================
--  Phase 10b migration: job parts (request -> issue to job custody -> used
--  / returned), billing on the job page (a normal sale that does not deduct
--  the parts again), release (paid / warranty / no charge) and back-jobs.
--
--  What it does (additive only; no table or column is dropped or renamed):
--    * New tables: job_order_parts (one row per parts request),
--      job_order_part_serials (serials issued on a request line).
--    * job_orders: + labor, release_type, released_to, release_note,
--      released_by, released_at, sale_id (current bill), parent_job_id
--      (back-job of).
--    * sales: + job_order_id (the job a bill belongs to; NULL = POS sale).
--      sale_items: + line_type item / part / labor (labour lines have no
--      product_id).
--    * stock_movements: type gains 'job_issue' (parts leave the POS location
--      into job custody) and 'job_return' (unused parts come back);
--      + job_order_id (FK).
--    * product_serials.status gains 'in_custody' (issued to a job) and
--      'installed' (used in a customer's device).
--    * Permissions job_parts.issue (branch_admin) and job_orders.release
--      (cashier + branch_admin). Must match config/permissions.php.
--
--  Safety:
--    * Idempotent: safe to run twice (IF NOT EXISTS, information_schema
--      guards, NOT EXISTS guarded inserts). Default grants are only given to
--      permissions created by THIS run.
--    * Backwards compatible with the Phase 10a PHP code.
--    * Back up first (outside the web root):
--        C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-010.sql
--
--  Run (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\010_job_parts_billing.sql
-- =====================================================================

SET @OLD_SQL_MODE = @@SESSION.sql_mode;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Job parts
--   requested (no stock change) -> issued: qty_issued (1..requested) left
--   the branch's POS location (job_issue, cost = branch average snapshot in
--   unit_cost) and is in job custody -> qty_used (installed, no movement) /
--   qty_returned (job_return back into the POS location at unit_cost).
--   In custody = qty_issued - qty_used - qty_returned (in no location).
--   requested -> cancelled (never issued).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job_order_parts (
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
CREATE TABLE IF NOT EXISTS job_order_part_serials (
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

-- ---------------------------------------------------------------------
-- 2. job_orders: labour, release, current bill, back-job link
-- ---------------------------------------------------------------------
ALTER TABLE job_orders
  ADD COLUMN IF NOT EXISTS labor         DECIMAL(12,2) NULL AFTER resolution,
  ADD COLUMN IF NOT EXISTS release_type  ENUM('paid','warranty','no_charge') NULL AFTER cancel_reason,
  ADD COLUMN IF NOT EXISTS released_to   VARCHAR(100)  NULL AFTER release_type,
  ADD COLUMN IF NOT EXISTS release_note  VARCHAR(255)  NULL AFTER released_to,
  ADD COLUMN IF NOT EXISTS released_by   INT UNSIGNED  NULL AFTER release_note,
  ADD COLUMN IF NOT EXISTS released_at   DATETIME      NULL AFTER released_by,
  ADD COLUMN IF NOT EXISTS sale_id       INT UNSIGNED  NULL AFTER released_at,
  ADD COLUMN IF NOT EXISTS parent_job_id INT UNSIGNED  NULL AFTER sale_id,
  ADD INDEX IF NOT EXISTS idx_job_orders_released_by (released_by),
  ADD INDEX IF NOT EXISTS idx_job_orders_sale (sale_id),
  ADD INDEX IF NOT EXISTS idx_job_orders_parent (parent_job_id);

ALTER TABLE job_orders
  ADD CONSTRAINT fk_job_orders_released_by FOREIGN KEY IF NOT EXISTS (released_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  ADD CONSTRAINT fk_job_orders_sale FOREIGN KEY IF NOT EXISTS (sale_id) REFERENCES sales (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  ADD CONSTRAINT fk_job_orders_parent FOREIGN KEY IF NOT EXISTS (parent_job_id) REFERENCES job_orders (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

SET @ddl = IF(EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                       WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'job_orders' AND CONSTRAINT_NAME = 'chk_job_orders_labor'),
              'DO 0',
              'ALTER TABLE job_orders ADD CONSTRAINT chk_job_orders_labor CHECK (labor IS NULL OR labor >= 0)');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                       WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'job_orders' AND CONSTRAINT_NAME = 'chk_job_orders_released'),
              'DO 0',
              'ALTER TABLE job_orders ADD CONSTRAINT chk_job_orders_released CHECK ((status IN (''released'',''closed'')) = (release_type IS NOT NULL))');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 3. Bills: sales.job_order_id + sale_items.line_type
-- ---------------------------------------------------------------------
ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS job_order_id INT UNSIGNED NULL AFTER customer_id,
  ADD INDEX IF NOT EXISTS idx_sales_job_order (job_order_id);

ALTER TABLE sales
  ADD CONSTRAINT fk_sales_job_order FOREIGN KEY IF NOT EXISTS (job_order_id) REFERENCES job_orders (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

ALTER TABLE sale_items
  ADD COLUMN IF NOT EXISTS line_type ENUM('item','part','labor') NOT NULL DEFAULT 'item' AFTER product_id;

-- ---------------------------------------------------------------------
-- 4. Stock movements + serial states
-- ---------------------------------------------------------------------
SET @ddl = IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements'
                         AND COLUMN_NAME = 'type' AND COLUMN_TYPE LIKE '%''job_return''%'),
              'DO 0',
              'ALTER TABLE stock_movements MODIFY COLUMN type ENUM(''initial'',''sale'',''restock'',''adjustment'',''void'',''receiving'',''transfer'',''issue'',''write_off'',''count'',''transfer_out'',''transfer_in'',''job_issue'',''job_return'') NOT NULL');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE stock_movements
  ADD COLUMN IF NOT EXISTS job_order_id INT UNSIGNED NULL AFTER stock_transfer_id,
  ADD INDEX IF NOT EXISTS idx_movements_job_order (job_order_id);

ALTER TABLE stock_movements
  ADD CONSTRAINT fk_movements_job_order FOREIGN KEY IF NOT EXISTS (job_order_id) REFERENCES job_orders (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

SET @ddl = IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_serials'
                         AND COLUMN_NAME = 'status' AND COLUMN_TYPE LIKE '%''installed''%'),
              'DO 0',
              'ALTER TABLE product_serials MODIFY COLUMN status ENUM(''in_stock'',''sold'',''removed'',''in_transit'',''in_custody'',''installed'') NOT NULL DEFAULT ''in_stock''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 5. Permissions (must match config/permissions.php). Only keys new in
--    this run get the default grants.
-- ---------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_010_new_perms;
CREATE TEMPORARY TABLE tmp_010_new_perms (perm_key VARCHAR(50) NOT NULL PRIMARY KEY)
  ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_010_new_perms (perm_key)
SELECT t.perm_key FROM (
            SELECT 'job_parts.issue' AS perm_key
  UNION ALL SELECT 'job_orders.release'
) t
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.perm_key = t.perm_key);

START TRANSACTION;
INSERT INTO permissions (perm_key, module, label, sort_order)
SELECT t.perm_key, t.module, t.label, t.sort_order FROM (
            SELECT 'job_parts.issue' AS perm_key, 'Job Orders' AS module, 'Issue parts to jobs and take back unused parts' AS label, 109 AS sort_order
  UNION ALL SELECT 'job_orders.release', 'Job Orders', 'Bill completed jobs and release devices to the customer', 110
) t
WHERE t.perm_key IN (SELECT perm_key FROM tmp_010_new_perms)
ORDER BY t.perm_key;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE p.perm_key IN (SELECT perm_key FROM tmp_010_new_perms)
  AND ((r.code = 'branch_admin' AND p.perm_key IN ('job_parts.issue', 'job_orders.release'))
    OR (r.code = 'cashier'      AND p.perm_key = 'job_orders.release'))
ORDER BY r.id, p.id;
COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_010_new_perms;

SET SESSION sql_mode = @OLD_SQL_MODE;
