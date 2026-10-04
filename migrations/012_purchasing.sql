-- =====================================================================
--  Phase 13a migration: Purchasing (internal procurement / stock in).
--    Purchase Request (PR) -> PO Internal (purchase order to a supplier)
--    -> Receiving Report from the PO (partial deliveries) -> PO received.
--
--  What it does (additive only; no table or column is dropped or renamed):
--    * New tables: purchase_requests + purchase_request_lines,
--      purchase_orders + purchase_order_lines, purchase_order_request_lines
--      (which PR lines a PO line orders, and how many).
--    * receiving_reports: + po_id; receiving_items: + po_line_id (an RR made
--      from a PO; posting it raises purchase_order_lines.qty_received).
--    * Branch addresses / contact numbers of MAR, MLB and CDO from EXECOM's
--      own purchase order form (only where still empty). They print on the
--      document letterhead.
--    * Permissions purchasing.request (branch_admin, cashier, technician),
--      purchasing.approve and purchasing.order (branch_admin). Must match
--      config/permissions.php.
--
--  Safety:
--    * Idempotent: safe to run twice (IF NOT EXISTS, information_schema
--      guards, NOT EXISTS guarded inserts). Default grants are only given to
--      permissions created by THIS run.
--    * Backwards compatible with the Phase 12 PHP code.
--    * Back up first (outside the web root):
--        C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-012.sql
--
--  Run as root (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\012_purchasing.sql
-- =====================================================================

SET @OLD_SQL_MODE = @@SESSION.sql_mode;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Purchase requests
--   requested -> approved (qty_approved per line, never by the requester)
--             -> ordered (every approved unit is on a purchase order)
--   requested -> rejected (note) | cancelled (reason; also approved while
--   nothing is on a purchase order yet).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_requests (
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

CREATE TABLE IF NOT EXISTS purchase_request_lines (
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
-- 2. Purchase orders (PO Internal)
--   draft (no number, editable) -> pending (sent for approval)
--   -> approved (po_no PO-<branch>-<year>-NNNNNN, printable, sent to the
--   supplier; never approved by its creator) -> partial -> received
--   (every line fully received through posted RRs). closed = the rest will
--   not come (reason). cancelled = approved but nothing received (reason).
--   pending -> draft again (returned with a note).
--   Delivery goes to the branch's POS location (warehouse_id, location_id).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_orders (
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

CREATE TABLE IF NOT EXISTS purchase_order_lines (
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
CREATE TABLE IF NOT EXISTS purchase_order_request_lines (
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

-- ---------------------------------------------------------------------
-- 3. Receiving from a purchase order
-- ---------------------------------------------------------------------
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'receiving_reports' AND COLUMN_NAME = 'po_id'),
              'DO 0',
              'ALTER TABLE receiving_reports ADD COLUMN po_id INT UNSIGNED NULL AFTER supplier_id, ADD KEY idx_receiving_po (po_id), ADD CONSTRAINT fk_receiving_po FOREIGN KEY (po_id) REFERENCES purchase_orders (id) ON UPDATE CASCADE ON DELETE RESTRICT');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'receiving_items' AND COLUMN_NAME = 'po_line_id'),
              'DO 0',
              'ALTER TABLE receiving_items ADD COLUMN po_line_id INT UNSIGNED NULL AFTER product_id, ADD KEY idx_receiving_items_po_line (po_line_id), ADD CONSTRAINT fk_receiving_items_po_line FOREIGN KEY (po_line_id) REFERENCES purchase_order_lines (id) ON UPDATE CASCADE ON DELETE RESTRICT');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 4. Branch letterhead details (from EXECOM's purchase order form; only
--    where the owner has not filled them in yet).
-- ---------------------------------------------------------------------
UPDATE branches SET address = 'Perimeter Freedom Park, Maramag, Bukidnon'
 WHERE code = 'MAR' AND (address IS NULL OR address = '');
UPDATE branches SET contact_no = '+63 917 157 9168 / 088 828 4767'
 WHERE code = 'MAR' AND (contact_no IS NULL OR contact_no = '');
UPDATE branches SET address = 'Jose Un Building, Fortich Street, Malaybalay City'
 WHERE code = 'MLB' AND (address IS NULL OR address = '');
UPDATE branches SET contact_no = '+63 917 845 8198 / 088 813 3925'
 WHERE code = 'MLB' AND (contact_no IS NULL OR contact_no = '');
UPDATE branches SET address = '123 Pacana Street, Corner Tiano, Cagayan De Oro City'
 WHERE code = 'CDO' AND (address IS NULL OR address = '');

-- ---------------------------------------------------------------------
-- 5. Permissions (must match config/permissions.php). Only keys new in
--    this run get the default grants.
-- ---------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_012_new_perms;
CREATE TEMPORARY TABLE tmp_012_new_perms (perm_key VARCHAR(50) NOT NULL PRIMARY KEY)
  ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_012_new_perms (perm_key)
SELECT t.perm_key FROM (
            SELECT 'purchasing.request' AS perm_key
  UNION ALL SELECT 'purchasing.approve'
  UNION ALL SELECT 'purchasing.order'
) t
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.perm_key = t.perm_key);

START TRANSACTION;
INSERT INTO permissions (perm_key, module, label, sort_order)
SELECT t.perm_key, t.module, t.label, t.sort_order FROM (
            SELECT 'purchasing.request' AS perm_key, 'Purchasing' AS module, 'Create purchase requests (PR) for items the branch needs' AS label, 120 AS sort_order
  UNION ALL SELECT 'purchasing.approve', 'Purchasing', 'Approve or reject purchase requests and purchase orders', 121
  UNION ALL SELECT 'purchasing.order',   'Purchasing', 'Prepare purchase orders to suppliers (PO Internal), close or cancel them', 122
) t
WHERE t.perm_key IN (SELECT perm_key FROM tmp_012_new_perms)
ORDER BY t.perm_key;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE p.perm_key IN (SELECT perm_key FROM tmp_012_new_perms)
  AND ((r.code = 'branch_admin' AND p.perm_key IN ('purchasing.request', 'purchasing.approve', 'purchasing.order'))
    OR (r.code IN ('cashier', 'technician') AND p.perm_key = 'purchasing.request'))
ORDER BY r.id, p.id;
COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_012_new_perms;

SET SESSION sql_mode = @OLD_SQL_MODE;
