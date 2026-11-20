-- v0.7.1: durable relay queue. Only needed if cb_link_jobs was created BEFORE this
-- change (setup.php adds the columns automatically). In phpMyAdmin, skip any line
-- that reports "Duplicate column name" / "Duplicate key name".
ALTER TABLE cb_link_jobs ADD COLUMN user_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL AFTER origin;
ALTER TABLE cb_link_jobs ADD COLUMN share_bps INT NULL DEFAULT NULL AFTER user_id;
ALTER TABLE cb_link_jobs ADD COLUMN async TINYINT NOT NULL DEFAULT 0 AFTER share_bps;
ALTER TABLE cb_link_jobs ADD KEY idx_jobs_user (user_id, state);
