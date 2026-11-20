<?php
// Copy to config.php, outside public_html. PHP 8.1+ on 64-bit hosting.
// TEST mode only. Never commit config.php with real secrets.
return [
    'mode' => 'TEST',
    'dsn' => 'mysql:host=localhost;dbname=YOUR_DB;charset=utf8mb4',
    'db_user' => 'YOUR_DB_USER', 'db_password' => 'YOUR_DB_PASSWORD',
    'telegram_token' => 'YOUR_BOTFATHER_TOKEN',
    'bot_username' => '', // Optional: bot @username (no @). Used by the web login widget; fetched via getMe if empty.
    'webhook_secret' => 'REPLACE_WITH_RANDOM_64_HEX_CHARACTERS',
    'bank_key' => 'REPLACE_WITH_ANOTHER_RANDOM_64_HEX_CHARACTERS', // MUST differ from webhook_secret
    'webhook_url' => 'https://bot.example.com/webhook.php',
    'affiliate_id' => 'YOUR_NUMERIC_AFFILIATE_ID',
    'customer_share_bps' => 7000, // Example only; 7000 = 70%. Set agreed ratio.
    'min_withdraw_vnd' => 10000, // Minimum per withdrawal request
    'max_links_per_day' => 30, // Anti-spam quota per user per calendar day (server time)
    'link_cooldown_seconds' => 10, // Minimum seconds between link batches per user
    'outbox_retention_days' => 7, // Worker deletes sent outbox rows older than this
    // Namespaced user keys: Telegram 'tg:<numeric_id>', Zalo OA 'zalo:<sender_id>'.
    // Users get their key via /id, then the admin adds it here.
    'allowed_users' => ['tg:123456789', 'tg:987654321'],
    'admins' => ['tg:123456789'],
    'source_chats' => [], // Telegram group IDs allowed for link intake, e.g. [-1001234567890]
    'link_mode' => 'custom_link', // 'relay' = via Chrome extension in your logged-in affiliate tab; 'custom_link' = signed link via affiliate cookie (needed for attribution); 'an_redir' = legacy, unsigned
    'relay_key' => '', // link_mode 'relay': 64-hex secret shared with link-relay-extension (php -r 'echo bin2hex(random_bytes(32));')
    'relay_wait_seconds' => 12,
    // link_mode 'open_api' (Shopee Affiliate Open API): AppId + Secret from affiliate.shopee.vn > Open API.
    'open_api_app_id' => '',
    'open_api_secret' => '',
    'guest_mode' => true, // Web: allow 'try without login' (guest cookie); data is merged into the Telegram account on login
    'resolve_short_links' => true, // Resolve s.shopee.vn / shope.ee short links (bounded, Shopee hosts only)
    // Zalo OA (optional; leave access token '' to disable the Zalo channel).
    'zalo_access_token' => '',
    'zalo_app_id' => '',
    'zalo_app_secret' => '',
    'zalo_refresh_token' => '', // Used only by private/zalo_refresh.php CLI helper
];
