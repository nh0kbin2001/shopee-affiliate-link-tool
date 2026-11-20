<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__.'/core.php';
try {
    $c = cfg();
    if (!str_starts_with($c['webhook_url'], 'https://')) throw new RuntimeException('HTTPS required.');
    // Ensure v0.5 tables exist (idempotent), BEFORE any network call so a
    // Telegram/network failure still leaves the schema in place.
    $d = db($c);
    q($d, "CREATE TABLE IF NOT EXISTS cb_notifications (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      user_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      kind VARCHAR(20) NOT NULL,
      title VARCHAR(120) NOT NULL,
      body VARCHAR(500) NOT NULL,
      amount BIGINT NULL DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      read_at DATETIME NULL DEFAULT NULL,
      KEY idx_notif_user (user_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    q($d, "CREATE TABLE IF NOT EXISTS cb_clicks (
      token CHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_clicks_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "v0.5 tables ensured (cb_notifications, cb_clicks).\n";
    $mig = __DIR__.'/migrate-v06-v07.sql';
    if (!is_file($mig)) throw new RuntimeException('Missing migrate-v06-v07.sql next to setup.php.');
    $sql = (string)file_get_contents($mig);
    $sql = preg_replace('~^--.*$~m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) q($d, $stmt);
    if (!q($d, "SHOW TABLES LIKE 'cb_link_jobs'")->fetch()) throw new RuntimeException('cb_link_jobs was not created.');
    // v0.7.1 durable queue columns for tables created by the first v0.7 build.
    $have = array_column(q($d, 'SHOW COLUMNS FROM cb_link_jobs')->fetchAll(), 'Field');
    $add = ['user_id' => "ADD COLUMN user_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL AFTER origin",
            'share_bps' => "ADD COLUMN share_bps INT NULL DEFAULT NULL AFTER user_id",
            'async' => "ADD COLUMN async TINYINT NOT NULL DEFAULT 0 AFTER share_bps"];
    foreach ($add as $col => $ddl) if (!in_array($col, $have, true)) q($d, 'ALTER TABLE cb_link_jobs '.$ddl);
    if (!q($d, "SHOW INDEX FROM cb_link_jobs WHERE Key_name='idx_jobs_user'")->fetch()) q($d, 'ALTER TABLE cb_link_jobs ADD KEY idx_jobs_user (user_id, state)');
    echo "v0.7 table ensured (cb_link_jobs).\n";
    telegram($c, 'setWebhook', ['url' => $c['webhook_url'], 'secret_token' => $c['webhook_secret'], 'allowed_updates' => ['message', 'callback_query'], 'max_connections' => 2, 'drop_pending_updates' => false]);
    echo "Telegram webhook registered (messages + button presses). Run /id, then set user/chat allowlists locally.\n";
    echo "Zalo: register https://YOUR_SUBDOMAIN/zalo_webhook.php?secret=<webhook_secret> manually in the Zalo app dashboard.\n";
} catch (Throwable $e) { fwrite(STDERR, "Setup failed: ".($e instanceof RuntimeException ? $e->getMessage() : get_class($e))."\nCheck config, token, HTTPS, cURL and outbound access.\n"); exit(1); }
