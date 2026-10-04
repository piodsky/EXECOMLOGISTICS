-- =====================================================================
--  Phase 13b migration: Customer Orders (outgoing sales / stock out).
--    PO Outgoing = the customer's purchase order (government / private)
--    -> confirmed (stock RESERVED at the branch POS location) -> Delivery
--    Receipts (stock out, serials 'delivered') -> billing (a sale linked to
--    the order, no second stock deduction; cash / GCash / card / on account).
--
--  What it does (additive only; no table or column is dropped or renamed):
--    * New tables: customer_orders + customer_order_lines, customer_deliveries
--      + customer_delivery_lines + customer_delivery_serials.
--    * sales: + customer_order_id; payment_type gains 'charge' (on account,
--      collected later).
--    * stock_movements: type gains 'delivery' / 'delivery_return'; +
--      customer_delivery_id.
--    * product_serials.status gains 'delivered' (with the customer).
--    * Permissions customer_orders.manage (branch_admin, cashier),
--      customer_orders.approve + customer_orders.deliver (branch_admin),
--      customer_orders.bill (branch_admin, cashier). Must match
--      config/permissions.php.
--
--  Safety:
--    * Idempotent: safe to run twice (IF NOT EXISTS, information_schema
--      guards, NOT EXISTS guarded inserts). Default grants are only given to
--      permissions created by THIS run.
--    * Backwards compatible with the Phase 13a PHP code.
--    * Back up first (outside the web root):
--        C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-013.sql
--
--  Run as root (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\013_customer_orders.sql
-- =====================================================================

SET @OLD_SQL_MODE = @@SESSION.sql_mode;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Customer orders (PO Outgoing)
--   draft (no number) -> pending (for confirmation) -> confirmed (order_no
--   CO-<branch>-<year>-NNNNNN, never by its preparer; reserves qty_ordered -
--   qty_delivered of every line at location_id) -> partial -> delivered ->
--   completed (everything delivered and billed). closed = the rest will not be
--   delivered (reason; frees the reservation). cancelled = confirmed, nothing
--   delivered (reason). pending -> draft (returned with a note).
--   Prices are VAT-exclusive like the POS; suggested_price = products.price
--   when the line was saved, price_reason when lower.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_orders (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  order_no          VARCHAR(30)   NULL,
  branch_id         INT UNSIGNED  NOT NULL,
  warehouse_id      INT UNSIGNED  NOT NULL,
  location_id       INT UNSIGNED  NOT NULL,
  customer_id       INT UNSIGNED  NOT NULL,
  customer_name     VARCHAR(100)  NOT NULL,
  customer_address  VARCHAR(255)  NULL,
  customer_po_no    VARCHAR(60)   NOT NULL,
  customer_po_date  DATE          NULL,
  end_user          VARCHAR(150)  NULL,
  place_of_delivery VARCHAR(255)  NULL,
  delivery_term     VARCHAR(100)  NULL,
  due_date          DATE          NULL,
  payment_term      VARCHAR(100)  NULL,
  procurement_mode  VARCHAR(60)   NULL,
  award_ref         VARCHAR(100)  NULL,
  notes             VARCHAR(500)  NULL,
  status            ENUM('draft','pending','confirmed','partial','delivered','completed','closed','cancelled') NOT NULL DEFAULT 'draft',
  total_qty         INT           NOT NULL DEFAULT 0,
  subtotal          DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  created_by        INT UNSIGNED  NOT NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_by      INT UNSIGNED  NULL,
  submitted_at      DATETIME      NULL,
  return_note       VARCHAR(255)  NULL,
  confirmed_by      INT UNSIGNED  NULL,
  confirmed_at      DATETIME      NULL,
  closed_by         INT UNSIGNED  NULL,
  closed_at         DATETIME      NULL,
  close_reason      VARCHAR(255)  NULL,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_orders_no (order_no),
  KEY idx_customer_orders_branch (branch_id, status, created_at),
  KEY idx_customer_orders_location (location_id, warehouse_id, branch_id),
  KEY idx_customer_orders_customer (customer_id),
  KEY idx_customer_orders_po (customer_po_no),
  KEY idx_customer_orders_due (due_date),
  KEY idx_customer_orders_created_by (created_by),
  KEY idx_customer_orders_submitted_by (submitted_by),
  KEY idx_customer_orders_confirmed_by (confirmed_by),
  KEY idx_customer_orders_closed_by (closed_by),
  CONSTRAINT fk_customer_orders_location FOREIGN KEY (location_id, warehouse_id, branch_id)
    REFERENCES storage_locations (id, warehouse_id, branch_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_orders_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_orders_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_orders_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_orders_closed_by FOREIGN KEY (closed_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_customer_orders_no CHECK ((order_no IS NULL) = (status IN ('draft','pending')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_order_lines (
  id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  order_id         INT UNSIGNED      NOT NULL,
  product_id       INT UNSIGNED      NOT NULL,
  qty_ordered      INT               NOT NULL,
  qty_delivered    INT               NOT NULL DEFAULT 0,
  qty_billed       INT               NOT NULL DEFAULT 0,
  unit_price       DECIMAL(12,2)     NOT NULL,
  suggested_price  DECIMAL(12,2)     NOT NULL,
  price_reason     VARCHAR(255)      NULL,
  line_total       DECIMAL(14,2)     NOT NULL,
  sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_order_lines_product (order_id, product_id),
  KEY idx_customer_order_lines_product (product_id),
  CONSTRAINT fk_customer_order_lines_order FOREIGN KEY (order_id) REFERENCES customer_orders (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_order_lines_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_customer_order_lines_qty CHECK (qty_ordered > 0 AND qty_delivered BETWEEN 0 AND qty_ordered
                                                 AND qty_billed BETWEEN 0 AND qty_delivered),
  CONSTRAINT chk_customer_order_lines_price CHECK (unit_price >= 0 AND suggested_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Delivery receipts
--   released (stock left the order's location: movement 'delivery', cost =
--   branch average snapshot; serials 'delivered') -> delivered (received by,
--   date, acceptance / IAR reference). released and not billed -> cancelled
--   (goods back to stock: 'delivery_return'). sale_id = the bill.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_deliveries (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  dr_no           VARCHAR(30)   NOT NULL,
  order_id        INT UNSIGNED  NOT NULL,
  branch_id       INT UNSIGNED  NOT NULL,
  warehouse_id    INT UNSIGNED  NOT NULL,
  location_id     INT UNSIGNED  NOT NULL,
  status          ENUM('released','delivered','cancelled') NOT NULL DEFAULT 'released',
  delivered_by    VARCHAR(100)  NULL,
  notes           VARCHAR(255)  NULL,
  total_qty       INT           NOT NULL DEFAULT 0,
  total_cost      DECIMAL(14,2) NULL,
  sale_id         INT UNSIGNED  NULL,
  released_by     INT UNSIGNED  NOT NULL,
  released_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_by     VARCHAR(150)  NULL,
  received_date   DATE          NULL,
  acceptance_ref  VARCHAR(60)   NULL,
  confirmed_by    INT UNSIGNED  NULL,
  confirmed_at    DATETIME      NULL,
  cancelled_by    INT UNSIGNED  NULL,
  cancelled_at    DATETIME      NULL,
  cancel_reason   VARCHAR(255)  NULL,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_deliveries_no (dr_no),
  KEY idx_customer_deliveries_order (order_id, status),
  KEY idx_customer_deliveries_branch (branch_id, status, released_at),
  KEY idx_customer_deliveries_location (location_id, warehouse_id, branch_id),
  KEY idx_customer_deliveries_sale (sale_id),
  KEY idx_customer_deliveries_released_by (released_by),
  KEY idx_customer_deliveries_confirmed_by (confirmed_by),
  KEY idx_customer_deliveries_cancelled_by (cancelled_by),
  CONSTRAINT fk_customer_deliveries_order FOREIGN KEY (order_id) REFERENCES customer_orders (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_deliveries_location FOREIGN KEY (location_id, warehouse_id, branch_id)
    REFERENCES storage_locations (id, warehouse_id, branch_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_deliveries_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_deliveries_released_by FOREIGN KEY (released_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_deliveries_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_deliveries_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_delivery_lines (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  delivery_id    INT UNSIGNED  NOT NULL,
  order_line_id  INT UNSIGNED  NOT NULL,
  product_id     INT UNSIGNED  NOT NULL,
  qty            INT           NOT NULL,
  unit_cost      DECIMAL(12,4) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_delivery_lines (delivery_id, order_line_id),
  KEY idx_customer_delivery_lines_order_line (order_line_id),
  KEY idx_customer_delivery_lines_product (product_id),
  CONSTRAINT fk_customer_delivery_lines_delivery FOREIGN KEY (delivery_id) REFERENCES customer_deliveries (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_delivery_lines_order_line FOREIGN KEY (order_line_id) REFERENCES customer_order_lines (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_delivery_lines_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_customer_delivery_lines_qty CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_delivery_serials (
  line_id    INT UNSIGNED NOT NULL,
  serial_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (line_id, serial_id),
  KEY idx_customer_delivery_serials_serial (serial_id),
  CONSTRAINT fk_customer_delivery_serials_line FOREIGN KEY (line_id) REFERENCES customer_delivery_lines (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_customer_delivery_serials_serial FOREIGN KEY (serial_id) REFERENCES product_serials (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Sales: the bill of a customer order; payment 'charge' (on account)
-- ---------------------------------------------------------------------
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'customer_order_id'),
              'DO 0',
              'ALTER TABLE sales ADD COLUMN customer_order_id INT UNSIGNED NULL AFTER job_order_id, ADD KEY idx_sales_customer_order (customer_order_id), ADD CONSTRAINT fk_sales_customer_order FOREIGN KEY (customer_order_id) REFERENCES customer_orders (id) ON UPDATE CASCADE ON DELETE RESTRICT');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'payment_type' AND COLUMN_TYPE LIKE '%''charge''%'),
              'DO 0',
              'ALTER TABLE sales MODIFY COLUMN payment_type ENUM(''cash'',''gcash'',''card'',''charge'') NOT NULL DEFAULT ''cash''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 4. Stock movements: delivery / delivery_return + customer_delivery_id
-- ---------------------------------------------------------------------
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'type' AND COLUMN_TYPE LIKE '%''delivery_return''%'),
              'DO 0',
              'ALTER TABLE stock_movements MODIFY COLUMN type ENUM(''initial'',''sale'',''restock'',''adjustment'',''void'',''receiving'',''transfer'',''issue'',''write_off'',''count'',''transfer_out'',''transfer_in'',''job_issue'',''job_return'',''delivery'',''delivery_return'') NOT NULL');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'customer_delivery_id'),
              'DO 0',
              'ALTER TABLE stock_movements ADD COLUMN customer_delivery_id INT UNSIGNED NULL AFTER job_order_id, ADD KEY idx_movements_customer_delivery (customer_delivery_id), ADD CONSTRAINT fk_movements_customer_delivery FOREIGN KEY (customer_delivery_id) REFERENCES customer_deliveries (id) ON UPDATE CASCADE ON DELETE RESTRICT');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 5. Serials: 'delivered' (with the customer on a delivery receipt)
-- ---------------------------------------------------------------------
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'product_serials' AND COLUMN_NAME = 'status' AND COLUMN_TYPE LIKE '%''delivered''%'),
              'DO 0',
              'ALTER TABLE product_serials MODIFY COLUMN status ENUM(''in_stock'',''sold'',''removed'',''in_transit'',''in_custody'',''installed'',''delivered'') NOT NULL DEFAULT ''in_stock''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 6. Permissions (must match config/permissions.php). Only keys new in
--    this run get the default grants.
-- ---------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_013_new_perms;
CREATE TEMPORARY TABLE tmp_013_new_perms (perm_key VARCHAR(50) NOT NULL PRIMARY KEY)
  ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_013_new_perms (perm_key)
SELECT t.perm_key FROM (
            SELECT 'customer_orders.manage' AS perm_key
  UNION ALL SELECT 'customer_orders.approve'
  UNION ALL SELECT 'customer_orders.deliver'
  UNION ALL SELECT 'customer_orders.bill'
) t
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.perm_key = t.perm_key);

START TRANSACTION;
INSERT INTO permissions (perm_key, module, label, sort_order)
SELECT t.perm_key, t.module, t.label, t.sort_order FROM (
            SELECT 'customer_orders.manage' AS perm_key, 'Customer Orders' AS module, 'Enter customer purchase orders (PO Outgoing) and send them for confirmation' AS label, 130 AS sort_order
  UNION ALL SELECT 'customer_orders.approve', 'Customer Orders', 'Confirm customer orders (reserves stock), close or cancel them', 131
  UNION ALL SELECT 'customer_orders.deliver', 'Customer Orders', 'Release delivery receipts (stock leaves the branch) and record the delivery', 132
  UNION ALL SELECT 'customer_orders.bill',    'Customer Orders', 'Bill delivered customer orders (cash, GCash, card or on account)', 133
) t
WHERE t.perm_key IN (SELECT perm_key FROM tmp_013_new_perms)
ORDER BY t.perm_key;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE p.perm_key IN (SELECT perm_key FROM tmp_013_new_perms)
  AND ((r.code = 'branch_admin' AND p.perm_key IN ('customer_orders.manage', 'customer_orders.approve', 'customer_orders.deliver', 'customer_orders.bill'))
    OR (r.code = 'cashier'      AND p.perm_key IN ('customer_orders.manage', 'customer_orders.bill')))
ORDER BY r.id, p.id;
COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_013_new_perms;

SET SESSION sql_mode = @OLD_SQL_MODE;
