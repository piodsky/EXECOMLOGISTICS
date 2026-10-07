-- =====================================================================
--  Migration 020: a different selling price per branch.
--    * product_branch_prices (product_id, branch_id, price): the branch's
--      suggested price; no row = use the company price (products.price).
--      Its own table (not product_branches) so a price never creates or
--      touches a branch cost row.
--    * Permission products.branch_price (branch_admin: own branches; super
--      admin: every branch).
--
--  Safety: additive; idempotent (IF NOT EXISTS / NOT EXISTS guards).
--  Back up first:
--    C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-020.sql
--
--  Run as root (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\020_branch_prices.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS product_branch_prices (
  product_id  INT UNSIGNED  NOT NULL,
  branch_id   INT UNSIGNED  NOT NULL,
  price       DECIMAL(10,2) NOT NULL,
  updated_by  INT UNSIGNED  NULL,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (product_id, branch_id),
  KEY idx_product_branch_prices_branch (branch_id),
  CONSTRAINT fk_product_branch_prices_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_product_branch_prices_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_product_branch_prices_user FOREIGN KEY (updated_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_product_branch_prices_price CHECK (price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TEMPORARY TABLE IF EXISTS tmp_020_new_perms;
CREATE TEMPORARY TABLE tmp_020_new_perms (perm_key VARCHAR(50) NOT NULL PRIMARY KEY)
  ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_020_new_perms (perm_key)
SELECT 'products.branch_price' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.perm_key = 'products.branch_price');

START TRANSACTION;
INSERT INTO permissions (perm_key, module, label, sort_order)
SELECT 'products.branch_price', 'Inventory', 'Set branch selling prices (branches the user works in)', 94 FROM DUAL
WHERE 'products.branch_price' IN (SELECT perm_key FROM tmp_020_new_perms);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE p.perm_key IN (SELECT perm_key FROM tmp_020_new_perms)
  AND r.code = 'branch_admin'
ORDER BY r.id, p.id;
COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_020_new_perms;
