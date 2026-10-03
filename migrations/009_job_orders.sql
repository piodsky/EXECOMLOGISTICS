-- =====================================================================
--  Phase 10a migration: job orders & technicians (intake, assignment,
--  diagnosis, quotation approval, repair statuses) + 4 permissions.
--  Parts custody, billing via the POS and release follow in Phase 10b.
--
--  What it does (additive only; no table or column is dropped or renamed):
--    * New tables: job_orders (numbered JO-<BRANCH>-<YEAR>-NNNNNN through
--      document_sequences), job_order_events (status history + notes).
--    * Setting job_quote_threshold (default 1000.00): an estimate above it
--      needs the customer's approval before the repair starts.
--    * Permissions job_orders.view, job_orders.create, job_orders.update,
--      job_orders.assign (must match config/permissions.php). Defaults:
--      branch_admin all four; cashier view + create; technician create +
--      update (own jobs + the branch's unassigned new jobs).
--
--  Safety:
--    * Idempotent: safe to run twice (IF NOT EXISTS, NOT EXISTS guarded
--      inserts). Default grants are only given to permissions created by
--      THIS run.
--    * Backwards compatible with the Phase 9 PHP code (it ignores the new
--      tables, setting and permissions).
--    * Back up first (outside the web root):
--        C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-009.sql
--
--  Run (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\009_job_orders.sql
-- =====================================================================

SET @OLD_SQL_MODE = @@SESSION.sql_mode;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Job orders
--   new -> assigned -> diagnosing -> (for_approval ->) in_repair
--   in_repair <-> waiting_parts, in_repair -> for_testing -> completed
--   for_testing -> in_repair (test failed); for_approval -> completed
--   (customer declined); new / assigned -> cancelled.
--   released / closed are used from Phase 10b.
--   customer_name / customer_phone are snapshots (walk-ins have no
--   customer_id). serial_id + warranty_until: the device was sold by us.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job_orders (
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
  accessories          VARCHAR(255)  NULL,
  device_condition     VARCHAR(255)  NULL,
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
  completed_by         INT UNSIGNED  NULL,
  completed_at         DATETIME      NULL,
  cancelled_by         INT UNSIGNED  NULL,
  cancelled_at         DATETIME      NULL,
  cancel_reason        VARCHAR(255)  NULL,
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
  CONSTRAINT chk_job_orders_estimate CHECK (estimate IS NULL OR estimate >= 0),
  CONSTRAINT chk_job_orders_assigned CHECK (status IN ('new','cancelled') OR technician_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Timeline: every status change (from_status -> to_status) and note.
CREATE TABLE IF NOT EXISTS job_order_events (
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

-- ---------------------------------------------------------------------
-- 2. Setting: quotation approval threshold (existing value kept)
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value)
SELECT 'job_quote_threshold', '1000.00'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'job_quote_threshold');

-- ---------------------------------------------------------------------
-- 3. Permissions (must match config/permissions.php). Only keys new in
--    this run get the default grants.
-- ---------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_009_new_perms;
CREATE TEMPORARY TABLE tmp_009_new_perms (perm_key VARCHAR(50) NOT NULL PRIMARY KEY)
  ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_009_new_perms (perm_key)
SELECT t.perm_key FROM (
            SELECT 'job_orders.view' AS perm_key
  UNION ALL SELECT 'job_orders.create'
  UNION ALL SELECT 'job_orders.update'
  UNION ALL SELECT 'job_orders.assign'
) t
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.perm_key = t.perm_key);

START TRANSACTION;
INSERT INTO permissions (perm_key, module, label, sort_order)
SELECT t.perm_key, t.module, t.label, t.sort_order FROM (
            SELECT 'job_orders.view' AS perm_key, 'Job Orders' AS module, 'View all job orders of the branch' AS label, 105 AS sort_order
  UNION ALL SELECT 'job_orders.create', 'Job Orders', 'Take in devices (new job orders) and edit intake details',              106
  UNION ALL SELECT 'job_orders.update', 'Job Orders', 'Work on assigned jobs: diagnosis, quotation, repair status, notes',      107
  UNION ALL SELECT 'job_orders.assign', 'Job Orders', 'Assign technicians, act on any job of the branch, cancel jobs',          108
) t
WHERE t.perm_key IN (SELECT perm_key FROM tmp_009_new_perms)
ORDER BY t.perm_key;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE p.perm_key IN (SELECT perm_key FROM tmp_009_new_perms)
  AND ((r.code = 'branch_admin' AND p.perm_key IN ('job_orders.view', 'job_orders.create', 'job_orders.update', 'job_orders.assign'))
    OR (r.code = 'cashier'      AND p.perm_key IN ('job_orders.view', 'job_orders.create'))
    OR (r.code = 'technician'   AND p.perm_key IN ('job_orders.create', 'job_orders.update')))
ORDER BY r.id, p.id;

UPDATE roles SET description = 'Repairs devices: job orders assigned to them and the branch''s new jobs; looks up customers and stock.'
 WHERE code = 'technician' AND description = 'Looks up customers and stock.';
COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_009_new_perms;

SET SESSION sql_mode = @OLD_SQL_MODE;
