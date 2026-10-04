-- =====================================================================
--  Phase 13c migration: Collections (accounts receivable) + Quotations.
--    Collections: payments of "On account" bills (sales.payment_type
--    'charge') with the taxes the customer withholds (expanded withholding
--    tax for BIR Form 2307, final VAT withheld for BIR Form 2306).
--    Quotations: EXECOM's price quotation for a customer's RFQ; a won
--    quotation becomes a customer order (PO Outgoing).
--
--  What it does (additive only; no table or column is dropped or renamed):
--    * New tables: collections + collection_lines, quotations +
--      quotation_lines.
--    * sales: + settled_amount (cash collected + taxes withheld on an
--      on-account bill; balance = total - settled_amount).
--    * customer_orders: + quotation_id (the quotation it came from).
--    * Permissions collections.manage (branch_admin, cashier) and
--      collections.cancel (branch_admin). Quotations use
--      customer_orders.manage. Must match config/permissions.php.
--
--  Safety:
--    * Idempotent: safe to run twice (IF NOT EXISTS, information_schema
--      guards, NOT EXISTS guarded inserts). Default grants are only given to
--      permissions created by THIS run.
--    * Backwards compatible with the Phase 13b PHP code.
--    * Back up first (outside the web root):
--        C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-014.sql
--
--  Run as root (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\014_collections_quotations.sql
-- =====================================================================

SET @OLD_SQL_MODE = @@SESSION.sql_mode;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Sales: what was settled on an on-account bill
-- ---------------------------------------------------------------------
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'settled_amount'),
              'DO 0',
              'ALTER TABLE sales ADD COLUMN settled_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER change_amount, ADD KEY idx_sales_receivable (payment_type, status, branch_id, customer_id)');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2. Collections (collection receipts CR-<branch>-<year>-NNNNNN)
--   posted at once (one payment of one customer, applied to one or more of
--   its on-account bills at the branch) -> cancelled (reason; the bills are
--   open again). Per bill: cash applied + EWT (2307) + VAT withheld (2306);
--   total_credited = their sum. form_2307: pending while a withholding
--   certificate is still to be received, received (date).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS collections (
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

CREATE TABLE IF NOT EXISTS collection_lines (
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
-- 3. Quotations (QT-<branch>-<year>-NNNNNN at create; never deleted)
--   draft (editable) -> sent -> won (a customer order was made from it:
--   order_id) / lost (reason) ; draft / sent -> cancelled (reason);
--   sent -> draft (revise). Prices VAT-exclusive like the orders.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS quotations (
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

CREATE TABLE IF NOT EXISTS quotation_lines (
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

-- ---------------------------------------------------------------------
-- 4. Customer orders: the quotation it came from
-- ---------------------------------------------------------------------
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'customer_orders' AND COLUMN_NAME = 'quotation_id'),
              'DO 0',
              'ALTER TABLE customer_orders ADD COLUMN quotation_id INT UNSIGNED NULL AFTER customer_id, ADD KEY idx_customer_orders_quotation (quotation_id), ADD CONSTRAINT fk_customer_orders_quotation FOREIGN KEY (quotation_id) REFERENCES quotations (id) ON UPDATE CASCADE ON DELETE RESTRICT');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 5. Permissions (must match config/permissions.php). Only keys new in
--    this run get the default grants.
-- ---------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_014_new_perms;
CREATE TEMPORARY TABLE tmp_014_new_perms (perm_key VARCHAR(50) NOT NULL PRIMARY KEY)
  ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_014_new_perms (perm_key)
SELECT t.perm_key FROM (
            SELECT 'collections.manage' AS perm_key
  UNION ALL SELECT 'collections.cancel'
) t
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.perm_key = t.perm_key);

START TRANSACTION;
INSERT INTO permissions (perm_key, module, label, sort_order)
SELECT t.perm_key, t.module, t.label, t.sort_order FROM (
            SELECT 'collections.manage' AS perm_key, 'Collections' AS module, 'Record collections of on-account bills (cash, check, bank, withholding taxes)' AS label, 134 AS sort_order
  UNION ALL SELECT 'collections.cancel', 'Collections', 'Cancel collection receipts (the bills are open again)', 135
) t
WHERE t.perm_key IN (SELECT perm_key FROM tmp_014_new_perms)
ORDER BY t.perm_key;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE p.perm_key IN (SELECT perm_key FROM tmp_014_new_perms)
  AND ((r.code = 'branch_admin' AND p.perm_key IN ('collections.manage', 'collections.cancel'))
    OR (r.code = 'cashier'      AND p.perm_key = 'collections.manage'))
ORDER BY r.id, p.id;
COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_014_new_perms;

SET SESSION sql_mode = @OLD_SQL_MODE;
