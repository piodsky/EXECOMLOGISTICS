-- =====================================================================
--  Migration 017: job order intake checklists, several job types and
--  several technicians per job (lead + helpers).
--
--  What it does (additive only; nothing is dropped or renamed):
--    * job_order_types: the job types of a job (one or more). Every existing
--      job gets its current job type copied in. job_orders.job_type_id stays
--      (= the first type) so older reports keep working.
--    * job_order_technicians: helper technicians of a job. job_orders.
--      technician_id stays the LEAD technician.
--    * job_orders.accessories / device_condition: 255 -> 500 characters
--      (filled from the intake checkboxes).
--    * lookups: new Master Data lists accessory, device_condition, problem
--      (the intake checkboxes) and more job types / device types. Inserted
--      only where the name is not there yet (INSERT IGNORE on list + name).
--
--  Safety: idempotent (IF NOT EXISTS, INSERT IGNORE, MODIFY to the same
--  type); backwards compatible with the previous PHP code. Back up first:
--    C:\xampp\mysql\bin\mysqldump.exe -u root execomlogistics_db > %TEMP%\execom-backups\execomlogistics_db-before-017.sql
--
--  Run as root (cmd.exe; from PowerShell wrap it in cmd /c "..."):
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\017_job_intake_checklists.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS job_order_types (
  job_order_id  INT UNSIGNED NOT NULL,
  lookup_id     INT UNSIGNED NOT NULL,
  PRIMARY KEY (job_order_id, lookup_id),
  KEY idx_job_order_types_lookup (lookup_id),
  CONSTRAINT fk_job_order_types_job FOREIGN KEY (job_order_id) REFERENCES job_orders (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_job_order_types_lookup FOREIGN KEY (lookup_id) REFERENCES lookups (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_order_technicians (
  job_order_id  INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  added_by      INT UNSIGNED NOT NULL,
  added_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (job_order_id, user_id),
  KEY idx_job_order_technicians_user (user_id),
  KEY idx_job_order_technicians_added_by (added_by),
  CONSTRAINT fk_job_order_technicians_job FOREIGN KEY (job_order_id) REFERENCES job_orders (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_job_order_technicians_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_job_order_technicians_added_by FOREIGN KEY (added_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE job_orders
  MODIFY accessories      VARCHAR(500) NULL,
  MODIFY device_condition VARCHAR(500) NULL;

INSERT IGNORE INTO job_order_types (job_order_id, lookup_id)
SELECT id, job_type_id FROM job_orders WHERE job_type_id IS NOT NULL ORDER BY id;

-- Intake checklists (Master Data: Accessories, Device Conditions, Common Problems) + more job / device types.
INSERT IGNORE INTO lookups (list, name, sort_order) VALUES
  ('accessory', 'Charger / Adapter', 1), ('accessory', 'Power cable', 2), ('accessory', 'Bag / Case / Sleeve', 3),
  ('accessory', 'Battery (removable)', 4), ('accessory', 'Mouse', 5), ('accessory', 'Keyboard', 6),
  ('accessory', 'USB cable', 7), ('accessory', 'HDMI / VGA cable', 8), ('accessory', 'Network (LAN) cable', 9),
  ('accessory', 'Original box', 10), ('accessory', 'Manual / Documents', 11), ('accessory', 'Warranty card / Receipt', 12),
  ('accessory', 'SD card / Flash drive', 13), ('accessory', 'External hard drive', 14), ('accessory', 'SIM card', 15),
  ('accessory', 'Stylus / Pen', 16), ('accessory', 'Remote control', 17), ('accessory', 'Ink / Toner cartridge', 18),
  ('accessory', 'Paper tray', 19), ('accessory', 'Antennas', 20), ('accessory', 'Screws / Brackets', 21),
  ('accessory', 'Headset / Earphones', 22), ('accessory', 'Webcam', 23), ('accessory', 'None (unit only)', 99),

  ('device_condition', 'Good / No visible damage', 1), ('device_condition', 'Minor scratches', 2),
  ('device_condition', 'Deep scratches / Scuffs', 3), ('device_condition', 'Dents', 4),
  ('device_condition', 'Cracked screen', 5), ('device_condition', 'Screen lines / Dead pixels', 6),
  ('device_condition', 'Cracked casing / Bezel', 7), ('device_condition', 'Broken hinge', 8),
  ('device_condition', 'Missing keys', 9), ('device_condition', 'Missing screws', 10),
  ('device_condition', 'Missing rubber feet', 11), ('device_condition', 'Loose / Damaged ports', 12),
  ('device_condition', 'Swollen battery', 13), ('device_condition', 'Liquid damage signs', 14),
  ('device_condition', 'Burnt smell / Burn marks', 15), ('device_condition', 'Very dusty / Dirty', 16),
  ('device_condition', 'Rust / Corrosion', 17), ('device_condition', 'Stickers / Labels on unit', 18),
  ('device_condition', 'Warranty seal broken', 19), ('device_condition', 'Previously opened / repaired', 20),
  ('device_condition', 'Does not power on (on arrival)', 21), ('device_condition', 'Missing parts / covers', 22),

  ('problem', 'No power / Will not turn on', 1), ('problem', 'No display / Black screen', 2),
  ('problem', 'Display flickering / Lines', 3), ('problem', 'Slow performance', 4),
  ('problem', 'Overheating / Shuts down by itself', 5), ('problem', 'Will not boot / Operating system error', 6),
  ('problem', 'Blue screen / Keeps restarting', 7), ('problem', 'Virus / Pop-ups / Malware', 8),
  ('problem', 'Battery not charging', 9), ('problem', 'Battery drains fast', 10),
  ('problem', 'Charging port loose', 11), ('problem', 'Keyboard not working / Missing keys', 12),
  ('problem', 'Touchpad / Mouse not working', 13), ('problem', 'No sound / Speaker problem', 14),
  ('problem', 'Camera / Microphone not working', 15), ('problem', 'Wi-Fi / Network problem', 16),
  ('problem', 'Bluetooth problem', 17), ('problem', 'USB ports not working', 18),
  ('problem', 'Noisy fan / Strange noise', 19), ('problem', 'Hard drive / SSD failure', 20),
  ('problem', 'Data backup / recovery needed', 21), ('problem', 'Software / MS Office installation', 22),
  ('problem', 'Windows reinstall / upgrade', 23), ('problem', 'Driver problem', 24),
  ('problem', 'Account locked / password reset', 25), ('problem', 'Printer: not printing', 26),
  ('problem', 'Printer: lines / faded print', 27), ('problem', 'Printer: paper jam', 28),
  ('problem', 'Printer: ink / toner error', 29), ('problem', 'Scanner not working', 30),
  ('problem', 'Router: no internet / drops', 31), ('problem', 'Dropped / Physical damage', 32),
  ('problem', 'Liquid spill', 33), ('problem', 'Preventive maintenance / cleaning', 34),

  ('job_type', 'Software / OS Installation', 11), ('job_type', 'Virus Removal', 12),
  ('job_type', 'Data Backup / Recovery', 13), ('job_type', 'Hardware Upgrade (RAM / SSD)', 14),
  ('job_type', 'Parts Replacement', 15), ('job_type', 'Screen Replacement', 16),
  ('job_type', 'Battery Replacement', 17), ('job_type', 'Keyboard Replacement', 18),
  ('job_type', 'Network Setup / Configuration', 19), ('job_type', 'Printer Service', 20),
  ('job_type', 'Warranty Claim / RMA', 21), ('job_type', 'On-site Service', 22),
  ('job_type', 'PC Assembly / Build', 23),

  ('device_type', 'Tablet', 11), ('device_type', 'Smartphone', 12), ('device_type', 'All-in-One PC', 13),
  ('device_type', 'Projector', 14), ('device_type', 'UPS', 15), ('device_type', 'Scanner', 16),
  ('device_type', 'CCTV / DVR', 17), ('device_type', 'Server', 18), ('device_type', 'Gaming Console', 19);
