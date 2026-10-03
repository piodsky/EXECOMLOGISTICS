-- =====================================================================
--  Phase 7b migration: warehouse operations - location kinds (DAMAGED /
--  DISPLAY), stock documents (transfers, internal-use issues, write-offs,
--  stock counts) + 6 permissions.
--
--  What it does (additive only; no table or column is dropped or renamed):
--    * storage_locations: + kind ENUM('stock','damaged','display') NOT NULL
--      DEFAULT 'stock' (every existing location stays 'stock'). Two CHECKs:
--      a damaged/display location is never sellable or default, and the
--      codes DAMAGED / DISPLAY are reserved for exactly those kinds.
--    * Every existing warehouse gets two locations (only if missing):
--      DAMAGED 'Damaged Stock' (kind damaged) and DISPLAY 'Display / Demo'
--      (kind display); both not sellable, not default. The POS default
--      location of each branch is unchanged.
--    * New tables: inventory_docs (one header for transfer / issue /
--      writeoff / count; numbered TRF/ISS/WOF/CNT through
--      document_sequences), inventory_doc_lines, inventory_doc_serials.
--    * stock_movements: type gains 'transfer', 'issue', 'write_off', 'count'
--      (appended to the ENUM); + inventory_doc_id (NULL, FK inventory_docs,
--      RESTRICT).
--    * product_serials / document_sequences: no DDL (status 'removed' and
--      doc types TRF/ISS/WOF/CNT are used by the Phase 7b code).
--    * Permissions inventory.transfer, inventory.damage, inventory.issue,
--      counts.create, counts.approve, warehouses.manage (must match
--      config/permissions.php). branch_admin gets all six; cashier and
--      technician get none (the owner can grant them under Roles).
--
--  Safety:
--    * Idempotent: safe to run twice (IF NOT EXISTS, information_schema
--      guards, NOT EXISTS guarded inserts). Default grants are only given to
--      permissions created by THIS run, so a re-run never re-grants something
--      an admin revoked.
--    * Existing rows stay valid: the only new rows are the DAMAGED / DISPLAY
--      locations and the permissions; no existing value is changed.
--      stock_movements / stock_balances / products rows are not rewritten
--      (ALTERs only add a column / an ENUM value / an index / a FK).
--    * Backwards compatible with the Phase 7a PHP code: the new locations
--      are never sellable/default, so the POS, voids and receiving keep using
--      the GENERAL location; old code ignores the new column and tables.
--      It can run before the Phase 7b code ships.
--    * Back up first (outside the web root):
--        C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-006.sql
--
--  Run (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\006_warehouse_ops.sql
-- =====================================================================

SET @OLD_SQL_MODE = @@SESSION.sql_mode;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Location kinds
--   stock   = normal storage (GENERAL, bins); may be sellable / default
--   damaged = DAMAGED, one per warehouse; never sellable or default
--   display = DISPLAY, one per warehouse; never sellable or default
-- ---------------------------------------------------------------------
ALTER TABLE storage_locations
  ADD COLUMN IF NOT EXISTS kind ENUM('stock','damaged','display') NOT NULL DEFAULT 'stock' AFTER name;

-- A pre-existing location that already uses a reserved code and is not
-- sellable/default takes the matching kind (normally no rows).
UPDATE storage_locations
   SET kind = IF(code = 'DAMAGED', 'damaged', 'display')
 WHERE code IN ('DAMAGED', 'DISPLAY') AND kind = 'stock' AND is_sellable = 0 AND is_default = 0;

-- One DAMAGED + one DISPLAY location per warehouse (only where missing).
INSERT INTO storage_locations (warehouse_id, branch_id, code, name, kind, is_sellable, is_default)
SELECT t.warehouse_id, t.branch_id, t.code, t.name, t.kind, 0, 0 FROM (
            SELECT w.id AS warehouse_id, w.branch_id, 'DAMAGED' AS code, 'Damaged Stock' AS name, 'damaged' AS kind
              FROM warehouses w
  UNION ALL SELECT w.id, w.branch_id, 'DISPLAY', 'Display / Demo', 'display'
              FROM warehouses w
) t
WHERE NOT EXISTS (SELECT 1 FROM storage_locations l
                   WHERE l.warehouse_id = t.warehouse_id AND l.code = t.code)
ORDER BY t.warehouse_id, t.code;

-- CHECKs (added only when missing; existing rows are validated).
SET @ddl = IF(EXISTS (SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
                       WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'storage_locations'
                         AND CONSTRAINT_NAME = 'chk_locations_kind'),
              'DO 0',
              'ALTER TABLE storage_locations ADD CONSTRAINT chk_locations_kind CHECK (kind = ''stock'' OR (is_sellable = 0 AND is_default = 0))');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(EXISTS (SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
                       WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'storage_locations'
                         AND CONSTRAINT_NAME = 'chk_locations_kind_code'),
              'DO 0',
              'ALTER TABLE storage_locations ADD CONSTRAINT chk_locations_kind_code CHECK ((kind = ''stock'') = (code NOT IN (''DAMAGED'', ''DISPLAY'')))');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2. Stock documents
--   doc_type transfer = location -> location of the same branch (purpose
--                       move / damage / display / restore, set by the app
--                       from the location kinds); posted at creation
--            issue    = internal use (stock leaves the company); posted
--            writeoff = damaged stock written off; posted
--            count    = stock count: open -> submitted -> posted | cancelled
--   from_* = source location (count: the counted location); to_* = transfer
--   destination only. The composite FKs keep both locations in branch_id.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_docs (
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
CREATE TABLE IF NOT EXISTS inventory_doc_lines (
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
CREATE TABLE IF NOT EXISTS inventory_doc_serials (
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
-- 3. stock_movements: new types + link to the stock document
-- ---------------------------------------------------------------------
-- Appending ENUM values is an in-place change; skipped when already there.
SET @ddl = IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements'
                         AND COLUMN_NAME = 'type' AND COLUMN_TYPE LIKE '%''count''%'),
              'DO 0',
              'ALTER TABLE stock_movements MODIFY COLUMN type ENUM(''initial'',''sale'',''restock'',''adjustment'',''void'',''receiving'',''transfer'',''issue'',''write_off'',''count'') NOT NULL');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE stock_movements
  ADD COLUMN IF NOT EXISTS inventory_doc_id INT UNSIGNED NULL AFTER receiving_id,
  ADD INDEX IF NOT EXISTS idx_movements_inventory_doc (inventory_doc_id);

ALTER TABLE stock_movements
  ADD CONSTRAINT fk_movements_inventory_doc FOREIGN KEY IF NOT EXISTS (inventory_doc_id) REFERENCES inventory_docs (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- 4. Permissions (must match config/permissions.php). Remember which keys
--    are new in this run: only those get the default grants.
-- ---------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_006_new_perms;
CREATE TEMPORARY TABLE tmp_006_new_perms (perm_key VARCHAR(50) NOT NULL PRIMARY KEY)
  ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_006_new_perms (perm_key)
SELECT t.perm_key FROM (
            SELECT 'inventory.transfer' AS perm_key
  UNION ALL SELECT 'inventory.damage'
  UNION ALL SELECT 'inventory.issue'
  UNION ALL SELECT 'counts.create'
  UNION ALL SELECT 'counts.approve'
  UNION ALL SELECT 'warehouses.manage'
) t
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.perm_key = t.perm_key);

START TRANSACTION;
INSERT INTO permissions (perm_key, module, label, sort_order)
SELECT t.perm_key, t.module, t.label, t.sort_order FROM (
            SELECT 'inventory.transfer' AS perm_key, 'Inventory' AS module, 'Move stock between locations of a branch' AS label, 88 AS sort_order
  UNION ALL SELECT 'inventory.damage',  'Inventory', 'Mark stock damaged and write off damaged stock',   89
  UNION ALL SELECT 'inventory.issue',   'Inventory', 'Issue stock for internal use and display units',  91
  UNION ALL SELECT 'counts.create',     'Inventory', 'Create stock counts and enter counted quantities', 92
  UNION ALL SELECT 'counts.approve',    'Inventory', 'Approve or cancel stock counts',                   93
  UNION ALL SELECT 'warehouses.manage', 'Branches',  'Manage warehouses and storage locations',          155
) t
WHERE t.perm_key IN (SELECT perm_key FROM tmp_006_new_perms)
ORDER BY t.sort_order;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code = 'branch_admin'
  AND p.perm_key IN ('inventory.transfer', 'inventory.damage', 'inventory.issue',
                     'counts.create', 'counts.approve', 'warehouses.manage')
  AND p.perm_key IN (SELECT perm_key FROM tmp_006_new_perms)
ORDER BY r.id, p.id;
COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_006_new_perms;

SET SESSION sql_mode = @OLD_SQL_MODE;
