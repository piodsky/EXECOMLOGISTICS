-- =====================================================================
--  Migration 018: in-app notifications (bell in the top bar).
--
--  What it does (additive only):
--    * notifications: one row per event (made from the audit log entry of a
--      transaction, see system/Notifications.php): message, document link,
--      module icon, branch, who did it.
--    * notification_recipients: who should see it (users with the right
--      permission at that branch + every super admin, never the person who
--      did it) and when each of them read it.
--
--  Safety: idempotent (IF NOT EXISTS); backwards compatible. Back up first:
--    C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-018.sql
--
--  Run as root (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\018_notifications.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS notifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  branch_id   INT UNSIGNED    NULL,
  module      VARCHAR(30)     NOT NULL,
  event       VARCHAR(60)     NOT NULL,
  icon        VARCHAR(20)     NOT NULL,
  tone        VARCHAR(10)     NOT NULL DEFAULT 'blue',
  message     VARCHAR(255)    NOT NULL,
  ref         VARCHAR(60)     NULL,
  link        VARCHAR(255)    NULL,
  actor_id    INT UNSIGNED    NULL,
  actor_name  VARCHAR(100)    NULL,
  PRIMARY KEY (id),
  KEY idx_notifications_created (created_at),
  KEY idx_notifications_branch (branch_id),
  KEY idx_notifications_actor (actor_id),
  CONSTRAINT fk_notifications_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_recipients (
  notification_id  BIGINT UNSIGNED NOT NULL,
  user_id          INT UNSIGNED    NOT NULL,
  read_at          DATETIME        NULL,
  PRIMARY KEY (user_id, notification_id),
  KEY idx_notification_recipients_unread (user_id, read_at),
  KEY idx_notification_recipients_notification (notification_id),
  CONSTRAINT fk_notification_recipients_notification FOREIGN KEY (notification_id) REFERENCES notifications (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_notification_recipients_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
