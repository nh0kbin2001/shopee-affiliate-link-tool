-- Migrate TEST database v0.1 -> v0.2. BACKUP FIRST (mysqldump or phpMyAdmin export).
-- Run once in phpMyAdmin SQL tab (or mysql CLI) on the existing TEST database.
-- Safe to run on a database that already matches v0.2 fresh schema? NO — run only on v0.1.

-- 1. Namespace existing Telegram numeric user ids to 'tg:<id>'.
UPDATE cb_users SET id = CONCAT('tg:', id);
ALTER TABLE cb_users
  MODIFY COLUMN id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ADD COLUMN last_link_at BIGINT NOT NULL DEFAULT 0,
  ADD COLUMN first_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

UPDATE cb_links SET user_id = CONCAT('tg:', user_id);
ALTER TABLE cb_links
  MODIFY COLUMN user_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD KEY idx_links_user_created (user_id, created_at);

ALTER TABLE cb_updates
  MODIFY COLUMN id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

ALTER TABLE cb_conversions
  ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD KEY idx_conv_token (token);

UPDATE cb_withdrawals SET user_id = CONCAT('tg:', user_id);
ALTER TABLE cb_withdrawals
  MODIFY COLUMN user_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD KEY idx_wd_user_state (user_id, state);

ALTER TABLE cb_outbox
  ADD COLUMN channel VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'tg',
  CHANGE COLUMN chat_id target VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ADD COLUMN sent_at TIMESTAMP NULL DEFAULT NULL;

CREATE TABLE cb_audit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ref VARCHAR(200) NOT NULL DEFAULT '',
  detail TEXT NOT NULL,
  KEY idx_audit_at (at)
) ENGINE=InnoDB;

-- NOTE: existing plaintext bank values stay as-is (legacy). Each user re-sends
-- /bank once to store the encrypted form. Legacy values trigger a warning
-- in admin approval messages until replaced.
