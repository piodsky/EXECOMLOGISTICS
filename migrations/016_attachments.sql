-- =====================================================================
--  Migration 016: optional attachments (photos / scanned PDFs) on
--  PO Internal (purchase_orders), PO Outgoing (customer_orders) and
--  collection receipts (collections, e.g. a photo of the check).
--
--  What it does (additive only):
--    * New table document_attachments. Files live in storage/attachments/
--      (never web-served directly; pages/attachment.php checks the user's
--      access to the document first). Deleting hides the row (deleted_at /
--      deleted_by) and removes the file.
--    * No permission rows: upload / view / delete follow the document's
--      existing permissions (see system/Attachments.php).
--
--  Safety: idempotent (IF NOT EXISTS); backwards compatible with the
--  previous PHP code. Back up first:
--    C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-016.sql
--
--  Run as root (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\016_attachments.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS document_attachments (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  doc_type       ENUM('purchase_order','customer_order','collection') NOT NULL,
  doc_id         INT UNSIGNED  NOT NULL,
  branch_id      INT UNSIGNED  NOT NULL,
  label          VARCHAR(40)   NOT NULL,
  filename       VARCHAR(64)   NOT NULL,
  original_name  VARCHAR(150)  NOT NULL,
  mime           VARCHAR(40)   NOT NULL,
  size_bytes     INT UNSIGNED  NOT NULL,
  uploaded_by    INT UNSIGNED  NOT NULL,
  uploaded_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_by     INT UNSIGNED  NULL,
  deleted_at     DATETIME      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attachments_filename (filename),
  KEY idx_attachments_doc (doc_type, doc_id, deleted_at),
  KEY idx_attachments_branch (branch_id),
  KEY idx_attachments_uploaded_by (uploaded_by),
  KEY idx_attachments_deleted_by (deleted_by),
  CONSTRAINT fk_attachments_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_attachments_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_attachments_deleted_by FOREIGN KEY (deleted_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_attachments_size CHECK (size_bytes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
