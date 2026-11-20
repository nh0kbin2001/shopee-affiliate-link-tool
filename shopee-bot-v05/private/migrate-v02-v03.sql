-- Migrate TEST database v0.2 -> v0.3. BACKUP FIRST. Run once in phpMyAdmin.
-- New in v0.3: button-press data entry (pending_action), per-message button
-- markup (outbox.markup). No config key changes. Money tables untouched.
ALTER TABLE cb_users
  ADD COLUMN pending_action VARCHAR(20) NOT NULL DEFAULT '',
  ADD COLUMN pending_at BIGINT NOT NULL DEFAULT 0;
ALTER TABLE cb_outbox
  ADD COLUMN markup TEXT NULL DEFAULT NULL;
-- If Vietnamese messages stored as ???, the tables are still latin1 from an
-- old import. Convert them (safe on populated tables; preserves content
-- already stored correctly, fixes future writes):
--   ALTER TABLE cb_users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   ALTER TABLE cb_links CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   ALTER TABLE cb_updates CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   ALTER TABLE cb_events CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   ALTER TABLE cb_conversions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   ALTER TABLE cb_ledger CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   ALTER TABLE cb_withdrawals CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   ALTER TABLE cb_outbox CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   ALTER TABLE cb_audit CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
