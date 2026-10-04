-- =====================================================================
--  Phase 13d migration: Billing terms + post-dated checks (receivables)
--  and Payables (supplier invoices + disbursements), after the old
--  NewEXECOM Billing / Collections / Payables / Disbursements modules.
--
--  What it does (additive only; no table or column is dropped or renamed):
--    * customers: + credit_days (0 = cash only: no "on account" at the POS /
--      job bills), + credit_limit (NULL = no limit).
--    * suppliers: + terms_days (supplier invoice due date).
--    * sales: + due_date (on-account bills). Existing on-account bills get
--      bill date + 30 days.
--    * collections: + check_status (on_hand -> deposited -> cleared, or
--      bounced) + deposited_at / cleared_at. Existing posted check
--      collections are marked cleared.
--    * New tables: supplier_invoices (AP-<branch>-<year>-NNNNNN, one per
--      posted receiving report), disbursements + disbursement_lines
--      (DV-<branch>-<year>-NNNNNN).
--    * Permissions sales.charge, payables.manage, payables.cancel
--      (branch_admin). Must match config/permissions.php.
--
--  Safety:
--    * Idempotent: safe to run twice (IF NOT EXISTS, information_schema
--      guards, NOT EXISTS guarded inserts). Default grants are only given to
--      permissions created by THIS run; backfills only touch NULL / 'none'.
--    * Backwards compatible with the Phase 13c PHP code.
--    * Back up first (outside the web root):
--        C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-015.sql
--
--  Run as root (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\015_billing_payables.sql
-- =====================================================================

SET @OLD_SQL_MODE = @@SESSION.sql_mode;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Credit terms
-- ---------------------------------------------------------------------
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'credit_days'),
              'DO 0',
              'ALTER TABLE customers ADD COLUMN credit_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER tin, ADD COLUMN credit_limit DECIMAL(14,2) NULL AFTER credit_days');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'terms_days'),
              'DO 0',
              'ALTER TABLE suppliers ADD COLUMN terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER payment_terms');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'due_date'),
              'DO 0',
              'ALTER TABLE sales ADD COLUMN due_date DATE NULL AFTER settled_amount');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE sales SET due_date = DATE(created_at) + INTERVAL 30 DAY WHERE payment_type = 'charge' AND due_date IS NULL;

-- ---------------------------------------------------------------------
-- 2. Post-dated / received checks on collections
-- ---------------------------------------------------------------------
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'collections' AND COLUMN_NAME = 'check_status'),
              'DO 0',
              'ALTER TABLE collections ADD COLUMN check_status ENUM(''none'',''on_hand'',''deposited'',''cleared'',''bounced'') NOT NULL DEFAULT ''none'' AFTER check_date, ADD COLUMN deposited_at DATE NULL AFTER check_status, ADD COLUMN cleared_at DATE NULL AFTER deposited_at, ADD KEY idx_collections_checks (check_status, branch_id, check_date)');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE collections SET check_status = 'cleared', cleared_at = collection_date
 WHERE method = 'check' AND status = 'posted' AND check_status = 'none';

-- ---------------------------------------------------------------------
-- 3. Supplier invoices (accounts payable), one per posted receiving report
--   open -> paid (paid_amount = total of posted disbursement lines, cash +
--   EWT withheld); open with nothing paid -> cancelled (reason).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS supplier_invoices (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  ap_no            VARCHAR(30)   NOT NULL,
  branch_id        INT UNSIGNED  NOT NULL,
  supplier_id      INT UNSIGNED  NOT NULL,
  receiving_id     INT UNSIGNED  NOT NULL,
  invoice_no       VARCHAR(60)   NOT NULL,
  invoice_date     DATE          NOT NULL,
  due_date         DATE          NOT NULL,
  amount           DECIMAL(14,2) NOT NULL,
  paid_amount      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  status           ENUM('open','paid','cancelled') NOT NULL DEFAULT 'open',
  notes            VARCHAR(255)  NULL,
  created_by       INT UNSIGNED  NOT NULL,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelled_by     INT UNSIGNED  NULL,
  cancelled_at     DATETIME      NULL,
  cancel_reason    VARCHAR(255)  NULL,
  updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_supplier_invoices_no (ap_no),
  KEY idx_supplier_invoices_branch (branch_id, status, due_date),
  KEY idx_supplier_invoices_supplier (supplier_id, status),
  KEY idx_supplier_invoices_receiving (receiving_id),
  KEY idx_supplier_invoices_created_by (created_by),
  KEY idx_supplier_invoices_cancelled_by (cancelled_by),
  CONSTRAINT fk_supplier_invoices_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_supplier_invoices_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_supplier_invoices_receiving FOREIGN KEY (receiving_id) REFERENCES receiving_reports (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_supplier_invoices_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_supplier_invoices_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_supplier_invoices_amounts CHECK (amount > 0 AND paid_amount >= 0 AND paid_amount <= amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Disbursements (payment vouchers to suppliers)
--   posted at once (one payment to one supplier applied to its open
--   invoices at the branch: cash + EWT withheld per invoice) -> cancelled
--   (reason; the invoices are open again). check_status for checks issued:
--   issued -> cleared.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS disbursements (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  dv_no            VARCHAR(30)   NOT NULL,
  branch_id        INT UNSIGNED  NOT NULL,
  supplier_id      INT UNSIGNED  NOT NULL,
  supplier_name    VARCHAR(120)  NOT NULL,
  payment_date     DATE          NOT NULL,
  method           ENUM('cash','check','bank','gcash') NOT NULL,
  reference        VARCHAR(60)   NULL,
  bank_name        VARCHAR(60)   NULL,
  check_date       DATE          NULL,
  check_status     ENUM('none','issued','cleared') NOT NULL DEFAULT 'none',
  cleared_at       DATE          NULL,
  amount_paid      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ewt_total        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total_settled    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  particulars      VARCHAR(255)  NULL,
  status           ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
  created_by       INT UNSIGNED  NOT NULL,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelled_by     INT UNSIGNED  NULL,
  cancelled_at     DATETIME      NULL,
  cancel_reason    VARCHAR(255)  NULL,
  updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_disbursements_no (dv_no),
  KEY idx_disbursements_branch (branch_id, status, payment_date),
  KEY idx_disbursements_supplier (supplier_id),
  KEY idx_disbursements_checks (check_status, branch_id),
  KEY idx_disbursements_created_by (created_by),
  KEY idx_disbursements_cancelled_by (cancelled_by),
  CONSTRAINT fk_disbursements_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_disbursements_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_disbursements_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_disbursements_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_disbursements_amounts CHECK (amount_paid >= 0 AND ewt_total >= 0 AND total_settled = amount_paid + ewt_total)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS disbursement_lines (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  disbursement_id  INT UNSIGNED  NOT NULL,
  invoice_id       INT UNSIGNED  NOT NULL,
  amount           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ewt_amount       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  UNIQUE KEY uq_disbursement_lines (disbursement_id, invoice_id),
  KEY idx_disbursement_lines_invoice (invoice_id),
  CONSTRAINT fk_disbursement_lines_disbursement FOREIGN KEY (disbursement_id) REFERENCES disbursements (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_disbursement_lines_invoice FOREIGN KEY (invoice_id) REFERENCES supplier_invoices (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_disbursement_lines_amounts CHECK (amount >= 0 AND ewt_amount >= 0 AND amount + ewt_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. Permissions (must match config/permissions.php). Only keys new in
--    this run get the default grants.
-- ---------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_015_new_perms;
CREATE TEMPORARY TABLE tmp_015_new_perms (perm_key VARCHAR(50) NOT NULL PRIMARY KEY)
  ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_015_new_perms (perm_key)
SELECT t.perm_key FROM (
            SELECT 'sales.charge' AS perm_key
  UNION ALL SELECT 'payables.manage'
  UNION ALL SELECT 'payables.cancel'
) t
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.perm_key = t.perm_key);

START TRANSACTION;
INSERT INTO permissions (perm_key, module, label, sort_order)
SELECT t.perm_key, t.module, t.label, t.sort_order FROM (
            SELECT 'sales.charge' AS perm_key, 'Collections' AS module, 'Sell on account at the POS and on job bills (credit customers, within their limit)' AS label, 136 AS sort_order
  UNION ALL SELECT 'payables.manage', 'Payables', 'Record supplier invoices and pay them (disbursement vouchers); shows costs', 137
  UNION ALL SELECT 'payables.cancel', 'Payables', 'Cancel supplier invoices and disbursement vouchers', 138
) t
WHERE t.perm_key IN (SELECT perm_key FROM tmp_015_new_perms)
ORDER BY t.perm_key;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE p.perm_key IN (SELECT perm_key FROM tmp_015_new_perms)
  AND r.code = 'branch_admin' AND p.perm_key IN ('sales.charge', 'payables.manage', 'payables.cancel')
ORDER BY r.id, p.id;
COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_015_new_perms;

SET SESSION sql_mode = @OLD_SQL_MODE;
