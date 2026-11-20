-- Migrate TEST database v0.3 -> v0.4. BACKUP FIRST. Run once in phpMyAdmin.
-- New in v0.4: admin wallet adjustments (cb_adjustments) and editable settings
-- (cb_settings, e.g. warranty policy). No config key changes.
CREATE TABLE cb_settings (
  k VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  v TEXT NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE cb_adjustments (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  amount BIGINT NOT NULL,
  reason VARCHAR(300) NOT NULL DEFAULT '',
  actor VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_adj_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
