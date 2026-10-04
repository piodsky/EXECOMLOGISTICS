-- =====================================================================
--  Phase 12 migration: indexes only (no table, column or data change).
--    * customers.phone          duplicate-phone check (company-wide) + search
--    * audit_logs.occurred_at   newest-first log in "All branches" / dashboard
--    * job_orders (branch_id, created_at), completed_at, released_at
--                               job report periods
--
--  Safety: idempotent (ADD INDEX IF NOT EXISTS); safe to run twice; the
--  Phase 11 code works with or without these indexes.
--  Back up first (outside the web root) and run as root (the app user
--  execom_app cannot change the schema):
--    C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-011.sql
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\011_security_indexes.sql
-- =====================================================================

ALTER TABLE customers
  ADD INDEX IF NOT EXISTS idx_customers_phone (phone);

ALTER TABLE audit_logs
  ADD INDEX IF NOT EXISTS idx_audit_date (occurred_at);

ALTER TABLE job_orders
  ADD INDEX IF NOT EXISTS idx_job_orders_branch_created (branch_id, created_at),
  ADD INDEX IF NOT EXISTS idx_job_orders_completed (completed_at),
  ADD INDEX IF NOT EXISTS idx_job_orders_released (released_at);
