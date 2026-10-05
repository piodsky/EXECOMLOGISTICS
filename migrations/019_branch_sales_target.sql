-- =====================================================================
--  Migration 019: monthly sales target per branch (Dashboard "Target vs
--  actual"). branches.monthly_target DECIMAL(14,2) NULL = no target;
--  edited in Settings -> Branches (branches.manage). Net sales incl. VAT,
--  like the Dashboard's "This Month".
--
--  Safety: additive; idempotent (information_schema guard). Back up first:
--    C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-019.sql
--
--  Run as root (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\019_branch_sales_target.sql
-- =====================================================================

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branches' AND COLUMN_NAME = 'monthly_target');
SET @sql := IF(@col = 0, 'ALTER TABLE branches ADD COLUMN monthly_target DECIMAL(14,2) NULL AFTER tin_branch_code', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
