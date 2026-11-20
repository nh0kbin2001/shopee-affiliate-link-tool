-- Migrate TEST database v0.6 -> v0.7. BACKUP FIRST. Run once per DB (main + test).
-- Link relay queue: the Chrome extension (link-relay-extension/) running in the
-- admin's logged-in affiliate tab pulls jobs and pushes back signed short links.
-- Alternative: `php setup.php` creates this table idempotently.
CREATE TABLE IF NOT EXISTS cb_link_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  token CHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  origin VARCHAR(200) NOT NULL,
  user_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  share_bps INT NULL DEFAULT NULL,
  async TINYINT NOT NULL DEFAULT 0,
  state VARCHAR(10) NOT NULL DEFAULT 'queued',
  short_link TEXT NULL DEFAULT NULL,
  error VARCHAR(300) NULL DEFAULT NULL,
  attempts INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  claimed_at DATETIME NULL DEFAULT NULL,
  done_at DATETIME NULL DEFAULT NULL,
  UNIQUE KEY uq_jobs_token (token),
  KEY idx_jobs_state (state, id),
  KEY idx_jobs_user (user_id, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
