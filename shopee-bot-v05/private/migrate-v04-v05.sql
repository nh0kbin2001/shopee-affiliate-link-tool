-- Migrate TEST database v0.4 -> v0.5. BACKUP FIRST. Run once in phpMyAdmin.
-- New in v0.5: web notifications (cb_notifications) and link-click counter
-- (cb_clicks, optional). No config key changes. Money tables untouched.
-- Alternative: run `php private/setup.php` which creates these two tables
-- idempotently if you prefer not to paste SQL.
CREATE TABLE IF NOT EXISTS cb_notifications (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  kind VARCHAR(20) NOT NULL,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(500) NOT NULL,
  amount BIGINT NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL DEFAULT NULL,
  KEY idx_notif_user (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS cb_clicks (
  token CHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_clicks_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
