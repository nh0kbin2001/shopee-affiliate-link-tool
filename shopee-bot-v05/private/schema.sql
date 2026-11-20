-- Shopee cashback TEST bot — fresh schema v0.3 (Telegram buttons + Zalo OA).
-- PHP 8.1+ 64-bit, MySQL/InnoDB. All tables default to utf8mb4 so Vietnamese
-- messages store correctly even when the database default is latin1.
-- User keys are namespaced strings: 'tg:<numeric_id>', 'zalo:<sender_id>'.
CREATE TABLE cb_users (
  id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  bank TEXT NULL DEFAULT NULL,
  last_link_at BIGINT NOT NULL DEFAULT 0,
  pending_action VARCHAR(20) NOT NULL DEFAULT '',
  pending_at BIGINT NOT NULL DEFAULT 0,
  first_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE cb_links (
  token CHAR(20) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  user_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  origin VARCHAR(200) NOT NULL,
  url TEXT NOT NULL,
  share_bps INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_links_user_origin (user_id, origin),
  KEY idx_links_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE cb_updates (
  id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE cb_events (
  id VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  digest CHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE cb_conversions (
  id VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  token CHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state VARCHAR(20) NOT NULL,
  commission BIGINT NOT NULL,
  share BIGINT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_conv_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE cb_ledger (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  conversion_id VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  amount BIGINT NOT NULL,
  event_id VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE cb_withdrawals (
  id CHAR(16) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  user_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  amount BIGINT NOT NULL,
  bank VARCHAR(300) NOT NULL,
  state VARCHAR(20) NOT NULL,
  reference VARCHAR(150) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_wd_user_state (user_id, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE cb_outbox (
  id VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  channel VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'tg',
  target VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  message TEXT NOT NULL,
  markup TEXT NULL DEFAULT NULL,
  sent TINYINT NOT NULL DEFAULT 0,
  attempts INT NOT NULL DEFAULT 0,
  next_try BIGINT NOT NULL DEFAULT 0,
  sent_at TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_outbox_pending (sent, next_try)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE cb_audit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ref VARCHAR(200) NOT NULL DEFAULT '',
  detail TEXT NOT NULL,
  KEY idx_audit_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
CREATE TABLE cb_notifications (
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
CREATE TABLE cb_clicks (
  token CHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_clicks_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
