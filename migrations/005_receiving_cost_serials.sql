-- =====================================================================
--  Phase 7a migration: receiving reports (RR), branch moving-average cost,
--  serial numbers + 6 permissions.
--
--  What it does (additive only; no table or column is dropped or renamed):
--    * New tables: document_sequences (RR numbering per branch + year),
--      product_branches (avg_cost per product x branch), receiving_reports,
--      receiving_items, receiving_item_serials, product_serials,
--      sale_item_serials.
--    * product_branches is seeded with one row per product x branch that has
--      a stock_balances row; avg_cost = products.unit_cost (the default cost).
--    * sale_items: + unit_cost DECIMAL(12,4) NULL (cost snapshot);
--      sales: + cost_total DECIMAL(12,2) NULL. Existing rows stay NULL.
--    * stock_movements: type gains 'receiving' (appended to the ENUM);
--      + receiving_id (NULL, FK receiving_reports, RESTRICT).
--    * Permissions receiving.view, receiving.manage, receiving.post,
--      receiving.cancel, serials.view, inventory.integrity (must match
--      config/permissions.php). branch_admin gets all six; cashier and
--      technician get serials.view.
--    * products.unit_cost keeps its meaning (= default cost); untouched.
--
--  Safety:
--    * Idempotent: safe to run twice (IF NOT EXISTS, information_schema
--      guards, NOT EXISTS guarded seed). Default grants are only given to
--      permissions created by THIS run, so a re-run never re-grants something
--      an admin revoked.
--    * Existing rows stay valid: every new column is NULL-able; no existing
--      value is changed. sales / sale_items / stock_movements / products rows
--      are not rewritten (ALTERs only add columns / an ENUM value).
--    * Backwards compatible with the Phase 6 PHP code (old code ignores the
--      new columns/tables and never writes type 'receiving'), so it can run
--      before the Phase 7a code ships.
--    * Back up first (outside the web root):
--        C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-005.sql
--
--  Run (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\005_receiving_cost_serials.sql
-- =====================================================================

SET @OLD_SQL_MODE = @@SESSION.sql_mode;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Document numbering + branch moving-average cost
-- ---------------------------------------------------------------------
-- last_no = last number handed out for (branch, doc_type, year); RR uses
-- the posting year. Rows are locked FOR UPDATE while a document is posted.
CREATE TABLE IF NOT EXISTS document_sequences (
  branch_id   INT UNSIGNED      NOT NULL,
  doc_type    VARCHAR(20)       NOT NULL,
  year        SMALLINT UNSIGNED NOT NULL,
  last_no     INT UNSIGNED      NOT NULL DEFAULT 0,
  PRIMARY KEY (branch_id, doc_type, year),
  CONSTRAINT fk_docseq_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Moving-average cost of a product at a branch (4 dp). A missing row means
-- "not costed yet": the app creates it from products.unit_cost.
CREATE TABLE IF NOT EXISTS product_branches (
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
-- 2. Receiving reports
--   draft     = editable, no number, no stock effect
--   posted    = rr_no assigned, stock added, avg cost updated
--   cancelled = posted then reversed (cancelled_* + cancel_reason)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS receiving_reports (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  rr_no          VARCHAR(30)   NULL,
  branch_id      INT UNSIGNED  NOT NULL,
  warehouse_id   INT UNSIGNED  NOT NULL,
  location_id    INT UNSIGNED  NOT NULL,
  supplier_id    INT UNSIGNED  NOT NULL,
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
CREATE TABLE IF NOT EXISTS receiving_items (
  id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  receiving_id     INT UNSIGNED      NOT NULL,
  product_id       INT UNSIGNED      NOT NULL,
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
  CONSTRAINT fk_receiving_items_receiving FOREIGN KEY (receiving_id) REFERENCES receiving_reports (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_receiving_items_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_receiving_items_qty CHECK (quantity > 0),
  CONSTRAINT chk_receiving_items_cost CHECK (unit_cost IS NULL OR unit_cost >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Serials typed on an RR line (draft entry; kept as the permanent record).
CREATE TABLE IF NOT EXISTS receiving_item_serials (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  receiving_item_id  INT UNSIGNED NOT NULL,
  serial_no          VARCHAR(60)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_receiving_item_serials (receiving_item_id, serial_no),
  CONSTRAINT fk_receiving_item_serials_item FOREIGN KEY (receiving_item_id) REFERENCES receiving_items (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Serial numbers (one row per physical unit of a track_serial product)
--   in_stock = at (branch, warehouse, location); sold = on a completed sale;
--   removed  = reserved for Phase 7b.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_serials (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id         INT UNSIGNED NOT NULL,
  serial_no          VARCHAR(60)  NOT NULL,
  branch_id          INT UNSIGNED NOT NULL,
  warehouse_id       INT UNSIGNED NOT NULL,
  location_id        INT UNSIGNED NOT NULL,
  status             ENUM('in_stock','sold','removed') NOT NULL DEFAULT 'in_stock',
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
CREATE TABLE IF NOT EXISTS sale_item_serials (
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
-- 4. Cost snapshots on sales (NULL for sales made before Phase 7a)
-- ---------------------------------------------------------------------
ALTER TABLE sale_items
  ADD COLUMN IF NOT EXISTS unit_cost DECIMAL(12,4) NULL AFTER unit_price;

ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS cost_total DECIMAL(12,2) NULL AFTER total;

-- ---------------------------------------------------------------------
-- 5. stock_movements: type 'receiving' + link to the RR
-- ---------------------------------------------------------------------
-- Appending an ENUM value is an in-place change; skipped when already there.
SET @ddl = IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements'
                         AND COLUMN_NAME = 'type' AND COLUMN_TYPE LIKE '%''receiving''%'),
              'DO 0',
              'ALTER TABLE stock_movements MODIFY COLUMN type ENUM(''initial'',''sale'',''restock'',''adjustment'',''void'',''receiving'') NOT NULL');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE stock_movements
  ADD COLUMN IF NOT EXISTS receiving_id INT UNSIGNED NULL AFTER sale_id,
  ADD INDEX IF NOT EXISTS idx_movements_receiving (receiving_id);

ALTER TABLE stock_movements
  ADD CONSTRAINT fk_movements_receiving FOREIGN KEY IF NOT EXISTS (receiving_id) REFERENCES receiving_reports (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- 6. Seed product_branches from the existing branch stock
--    (avg_cost = default cost; existing rows are never overwritten)
-- ---------------------------------------------------------------------
INSERT INTO product_branches (product_id, branch_id, avg_cost)
SELECT sb.product_id, sb.branch_id, MAX(p.unit_cost)
  FROM stock_balances sb
  JOIN products p ON p.id = sb.product_id
 WHERE NOT EXISTS (SELECT 1 FROM product_branches pb
                    WHERE pb.product_id = sb.product_id AND pb.branch_id = sb.branch_id)
 GROUP BY sb.product_id, sb.branch_id
 ORDER BY sb.product_id, sb.branch_id;

-- ---------------------------------------------------------------------
-- 7. Permissions (must match config/permissions.php). Remember which keys
--    are new in this run: only those get the default grants.
-- ---------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_005_new_perms;
CREATE TEMPORARY TABLE tmp_005_new_perms (perm_key VARCHAR(50) NOT NULL PRIMARY KEY)
  ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_005_new_perms (perm_key)
SELECT t.perm_key FROM (
            SELECT 'receiving.view' AS perm_key
  UNION ALL SELECT 'receiving.manage'
  UNION ALL SELECT 'receiving.post'
  UNION ALL SELECT 'receiving.cancel'
  UNION ALL SELECT 'serials.view'
  UNION ALL SELECT 'inventory.integrity'
) t
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.perm_key = t.perm_key);

START TRANSACTION;
INSERT INTO permissions (perm_key, module, label, sort_order)
SELECT t.perm_key, t.module, t.label, t.sort_order FROM (
            SELECT 'receiving.view' AS perm_key, 'Receiving' AS module, 'View receiving reports' AS label, 82 AS sort_order
  UNION ALL SELECT 'receiving.manage',    'Receiving', 'Create and edit receiving drafts',             83
  UNION ALL SELECT 'receiving.post',      'Receiving', 'Post receiving reports - adds stock and sets cost', 84
  UNION ALL SELECT 'receiving.cancel',    'Receiving', 'Cancel posted receiving reports',              85
  UNION ALL SELECT 'serials.view',        'Inventory', 'Look up serial numbers',                       86
  UNION ALL SELECT 'inventory.integrity', 'Inventory', 'Run stock integrity checks',                   87
) t
WHERE t.perm_key IN (SELECT perm_key FROM tmp_005_new_perms)
ORDER BY t.sort_order;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE ((r.code = 'branch_admin' AND p.perm_key IN ('receiving.view', 'receiving.manage', 'receiving.post',
           'receiving.cancel', 'serials.view', 'inventory.integrity'))
    OR (r.code IN ('cashier', 'technician') AND p.perm_key = 'serials.view'))
  AND p.perm_key IN (SELECT perm_key FROM tmp_005_new_perms)
ORDER BY r.id, p.id;
COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_005_new_perms;

SET SESSION sql_mode = @OLD_SQL_MODE;
