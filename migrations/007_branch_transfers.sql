-- =====================================================================
--  Phase 8 migration: branch-to-branch transfers (request -> approve ->
--  release / in transit -> receive, or cancel before release) + 4 permissions.
--
--  What it does (additive only; no table or column is dropped or renamed):
--    * New tables: stock_transfers (header, numbered BT-<FROM>-<YEAR>-NNNNNN
--      through document_sequences of the sending branch), stock_transfer_lines,
--      stock_transfer_serials.
--    * stock_movements: type gains 'transfer_out', 'transfer_in' (appended to
--      the ENUM); + stock_transfer_id (NULL, FK stock_transfers, RESTRICT).
--    * product_serials.status gains 'in_transit' (appended): a released
--      serial is in neither branch until it is received.
--    * Permissions transfers.request, transfers.approve, transfers.release,
--      transfers.receive (must match config/permissions.php). branch_admin
--      gets all four; cashier and technician get none.
--
--  Safety:
--    * Idempotent: safe to run twice (IF NOT EXISTS, information_schema
--      guards, NOT EXISTS guarded inserts). Default grants are only given to
--      permissions created by THIS run.
--    * Existing rows stay valid; no existing value is changed. Backwards
--      compatible with the Phase 7b PHP code (it ignores the new tables,
--      ENUM values and column), so it can run before the Phase 8 code ships.
--    * Back up first (outside the web root):
--        C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-007.sql
--
--  Run (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\007_branch_transfers.sql
-- =====================================================================

SET @OLD_SQL_MODE = @@SESSION.sql_mode;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Transfer documents
--   requested -> approved -> released (in transit) -> received
--   requested / approved -> cancelled
--   from_* location: the sending branch's POS location, set at release.
--   to_*   location: the receiving branch's POS location, set at receive.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_transfers (
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
CREATE TABLE IF NOT EXISTS stock_transfer_lines (
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
CREATE TABLE IF NOT EXISTS stock_transfer_serials (
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
-- 2. stock_movements: new types + link; product_serials: in_transit
-- ---------------------------------------------------------------------
SET @ddl = IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements'
                         AND COLUMN_NAME = 'type' AND COLUMN_TYPE LIKE '%''transfer_in''%'),
              'DO 0',
              'ALTER TABLE stock_movements MODIFY COLUMN type ENUM(''initial'',''sale'',''restock'',''adjustment'',''void'',''receiving'',''transfer'',''issue'',''write_off'',''count'',''transfer_out'',''transfer_in'') NOT NULL');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE stock_movements
  ADD COLUMN IF NOT EXISTS stock_transfer_id INT UNSIGNED NULL AFTER inventory_doc_id,
  ADD INDEX IF NOT EXISTS idx_movements_stock_transfer (stock_transfer_id);

ALTER TABLE stock_movements
  ADD CONSTRAINT fk_movements_stock_transfer FOREIGN KEY IF NOT EXISTS (stock_transfer_id) REFERENCES stock_transfers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

SET @ddl = IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_serials'
                         AND COLUMN_NAME = 'status' AND COLUMN_TYPE LIKE '%''in_transit''%'),
              'DO 0',
              'ALTER TABLE product_serials MODIFY COLUMN status ENUM(''in_stock'',''sold'',''removed'',''in_transit'') NOT NULL DEFAULT ''in_stock''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 3. Permissions (must match config/permissions.php). Only keys new in
--    this run get the default grants.
-- ---------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_007_new_perms;
CREATE TEMPORARY TABLE tmp_007_new_perms (perm_key VARCHAR(50) NOT NULL PRIMARY KEY)
  ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_007_new_perms (perm_key)
SELECT t.perm_key FROM (
            SELECT 'transfers.request' AS perm_key
  UNION ALL SELECT 'transfers.approve'
  UNION ALL SELECT 'transfers.release'
  UNION ALL SELECT 'transfers.receive'
) t
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.perm_key = t.perm_key);

START TRANSACTION;
INSERT INTO permissions (perm_key, module, label, sort_order)
SELECT t.perm_key, t.module, t.label, t.sort_order FROM (
            SELECT 'transfers.request' AS perm_key, 'Transfers' AS module, 'Request stock from another branch' AS label, 101 AS sort_order
  UNION ALL SELECT 'transfers.approve', 'Transfers', 'Approve or cancel requests for this branch''s stock',         102
  UNION ALL SELECT 'transfers.release', 'Transfers', 'Release approved transfers (stock leaves the branch)',         103
  UNION ALL SELECT 'transfers.receive', 'Transfers', 'Receive incoming transfers (stock enters the branch)',          104
) t
WHERE t.perm_key IN (SELECT perm_key FROM tmp_007_new_perms)
ORDER BY t.perm_key;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code = 'branch_admin'
  AND p.perm_key IN ('transfers.request', 'transfers.approve', 'transfers.release', 'transfers.receive')
  AND p.perm_key IN (SELECT perm_key FROM tmp_007_new_perms)
ORDER BY r.id, p.id;
COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_007_new_perms;

SET SESSION sql_mode = @OLD_SQL_MODE;
