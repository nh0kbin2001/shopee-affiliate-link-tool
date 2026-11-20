# Deploy v0.7 lên subdomain shared hosting (TEST)

## Nâng cấp v0.6 → v0.7 (thêm 1 bảng cb_link_jobs)

Mới: link có chữ ký qua Custom Link, giải link rút gọn, **chế độ khách**, chế độ **relay**
(tạo link qua extension Chrome trong tab affiliate đang đăng nhập, xem
`link-relay-extension/README.md`), các sửa lỗi giao diện/báo thành công sai.

1. Sao lưu DB chính + DB test.
2. Upload (giữ `config.php`, KHÔNG ghi đè nó):
   - **Ghi đè** `~/cashback_private/`: `core.php`, `web.php`, `setup.php`, `tests.php`
   - **Thêm mới** `~/cashback_private/`: `migrate-v06-v07.sql`, `tests_ui.php`
   - **Ghi đè** document root: `index.php`, `login.php`, `dashboard.php`, `admin.php`,
     `assets/style.css`, `assets/app.js`
   - **Thêm mới** document root: `guest.php`, `api/relay.php`
3. Tạo bảng: `cd ~/cashback_private && php setup.php` (phải in `v0.7 table ensured (cb_link_jobs).`;
   hoặc import `migrate-v06-v07.sql` trong phpMyAdmin cho MỖI DB chính + test).
4. Thêm vào `config.php` (gõ đúng chính tả, sai sẽ báo lỗi cấu hình):
   `'resolve_short_links' => true,` `'guest_mode' => true,` và chọn **một** `link_mode`:
   - `'link_mode' => 'custom_link',` — server gọi Shopee bằng cookie (hiện bị Shopee chặn 403 / 90309999).
   - `'link_mode' => 'relay',` + `'relay_key' => '<64 hex>',` (`php -r 'echo bin2hex(random_bytes(32)),PHP_EOL;'`)
     + tuỳ chọn `'relay_wait_seconds' => 12,` — rồi cài `link-relay-extension` theo README của nó.
   - `'link_mode' => 'an_redir',` — link kiểu cũ, nhiều khả năng KHÔNG được tính hoa hồng.
5. Chế độ `custom_link`: `admin.php` → **Nguồn chuyển đổi** → dán cookie → Lưu (giờ có kiểm tra
   đọc lại, cookie tới 12.000 byte) → **Thử tạo link**. Chế độ `relay`: `admin.php` phải hiện
   "lần cuối extension liên lạc" màu xanh.
6. Terminal: dsn → DB test → `php tests.php` và `php tests_ui.php` (toàn PASS) → dsn về DB chính.
7. Kiểm tra: tab ẩn danh → "Dùng thử không cần đăng nhập" → dán link → nhận link (hoặc
   thông báo đỏ nêu rõ link nào lỗi) → tab Ví thấy lời mời đăng nhập → đăng nhập Telegram.

Hosting: nên để PHP-FPM `request_terminate_timeout` ≥ 30 giây (relay chờ tối đa 15 giây mỗi lượt).

Lưu ý: khách chưa đăng nhập không rút được tiền, không nhận tin Telegram. Tài khoản
Telegram vẫn phải nằm trong `allowed_users` thì mới đăng nhập (và gộp dữ liệu) được.
Xoá cookie trình duyệt sẽ làm mất dữ liệu khách chưa được gộp; link tạo trước khi bật
`guest_mode` cũng không nằm trong tài khoản khách.

---

# Deploy v0.6 lên subdomain shared hosting (TEST)

## Nâng cấp v0.5 → v0.6 (không cần đổi DB)

Chỉ thay giao diện + toggle tính năng (dùng bảng `cb_settings` đã có). Upload:

- **Ghi đè** document root: `dashboard.php`, `admin.php`
- **Ghi đè** private: `web.php`
- **Ghi đè** `assets/style.css`
- (Không đổi `core.php`, `setup.php`, `worker.php`, `tests.php`, schema, cron, config.)

Sau khi upload: mở `admin.php` → mục **Tính năng (thử nghiệm)** → tick bật **Deal cộng đồng**
/ **Mời bạn bè**, nhập nội dung Deal, Lưu. Khách sẽ thấy tab tương ứng. Ctrl+F5 để hết cache.

---

# Deploy v0.5 lên subdomain shared hosting (TEST)

## Nâng cấp v0.4 → v0.5 (đang chạy v0.4, giữ nguyên config/cron)

Tóm tắt: token màu + sáng/tối, bố cục tab, dán link trên web, hiện link + sao chép,
thông báo web (mức 1 + 2), tổng quan/dòng thời gian/lọc, xuất CSV, và các fix B1–B6.

1. Sao lưu DB chính + DB test.
2. Tạo 2 bảng mới (chọn 1 cách):
   - Cách A (khuyến nghị): `cd ~/cashback_private && php setup.php` — tự tạo `cb_notifications`, `cb_clicks` (idempotent), đồng thời đăng ký lại webhook.
   - Cách B: import `private/migrate-v04-v05.sql` cho MỖI DB (chính + test).
3. Upload (giữ `config.php`):
   - **Ghi đè** trong `~/cashback_private/`: `core.php`, `web.php`, `setup.php`, `worker.php`, `tests.php`
   - **Ghi đè** document root `bot.dailyhotdeals.biz/`: `index.php`, `login.php`, `dashboard.php`, `admin.php`
   - **Thêm mới / ghi đè** `assets/`: `style.css`, `theme.js`, `app.js`
   - **Thêm mới** document root: thư mục `api/` chứa `notifications.php`, và `go.php`
4. Terminal: dsn → DB test → `php tests.php` (kỳ vọng toàn PASS, có thêm ca `assertLinkQuota`, `conversion notifies money_in`) → dsn về DB chính.
5. Không cần chạy lại BotFather nếu đã `/setdomain`. Mở web: có nút đổi sáng/tối, thanh tab, màn Ví 2 bước.

Kiểm tra nhanh: dán link Shopee ở Tổng quan → nhận link hoàn tiền; chạy `import_test.php`
một đơn `settled` → trong ~60 giây tab đang mở hiện toast "Tiền về" và số trên chuông tăng.

---

# Deploy v0.4 lên subdomain shared hosting (TEST)

## Nâng cấp v0.3 → v0.4 (đang chạy v0.3, giữ nguyên config/cron)

1. Sao lưu DB chính + DB test (Export trong phpMyAdmin).
2. phpMyAdmin → chạy `private/migrate-v03-v04.sql` MỘT lần cho **mỗi DB** (chính + test).
3. Upload (giữ nguyên `config.php` đang dùng; các file KHÔNG đổi cứ để yên:
   `webhook.php`, `zalo_webhook.php`, `setup.php`, `worker.php`, `zalo.php`,
   `zalo_refresh.php`, `import_test.php`, cron):
   - **Ghi đè (file đã có, nội dung mới)**: trong `~/cashback_private/` — `core.php`, `tests.php`
   - **Thêm mới** vào `~/cashback_private/`: `web.php`
   - **Thêm mới** vào document root `bot.dailyhotdeals.biz/`: `_boot.php`, `index.php`,
     `login.php`, `dashboard.php`, `admin.php`, `logout.php`, và thư mục `assets/` chứa `style.css`
4. Thêm dòng `'bot_username' => 'affduongg_bot'` vào `config.php` (hoặc để trống, hệ thống tự gọi getMe).
5. Terminal: dsn → DB test → `php tests.php` (kỳ vọng toàn PASS) → dsn về DB chính.
6. BotFather → `/setdomain` → chọn bot → nhập `bot.dailyhotdeals.biz` (cho widget đăng nhập web).
7. Mở `https://bot.dailyhotdeals.biz/` → bấm "Log in with Telegram" → vào dashboard.
   Admin mở `admin.php` để duyệt tiền, nộp/trừ tiền, sửa chính sách.

---

Áp dụng cho hosting có cPanel hoặc DirectAdmin, PHP CloudLinux, cron, terminal.
Toàn bộ làm ở chế độ TEST, chưa dùng tiền thật.

## 0. Chuẩn bị subdomain + HTTPS

- **cPanel**: Domains → Create A New Domain → nhập `bot.tenmien.vn` → BỎ chọn
  "Share document root" → Submit. Document root ví dụ `/home/USER/bot.tenmien.vn`.
- **DirectAdmin**: Account Manager → Subdomain Management → nhập `bot` → Create.
  Document root thường là `/home/USER/domains/bot.tenmien.vn/public_html`.
- Nếu DNS ngoài (Cloudflare): thêm bản ghi A `bot` → IP hosting.
- Bật SSL miễn phí cho subdomain:
  cPanel: Security → SSL/TLS Status → chọn subdomain → Run AutoSSL.
  DirectAdmin: Account Manager → SSL Certificates → chọn subdomain → Let's Encrypt.
- Kiểm tra: mở `https://bot.tenmien.vn` (trang trống là bình thường ở bước này).

## 1. Chọn PHP và bật extension

- **cPanel**: Software → Select PHP Version → chọn **8.2 hoặc 8.3** →
  tick `pdo_mysql, curl, mbstring, openssl` (json có sẵn) → Set as current → Save.
- **DirectAdmin**: Advanced Features → Select PHP Version → tương tự.
- Ghi lại đường dẫn PHP CLI để dùng cho cron (xem bước 6):
  mở Terminal của hosting, chạy `which php` và `ls /opt/alt/`.
  Thường là `/opt/alt/php83/usr/bin/php` (CloudLinux) hoặc `/usr/local/bin/php`.

## 2. Tải code lên đúng vị trí

Giải nén `Shopee_Cashback_Telegram_Zalo_PHP_TEST_v0.2.zip` trên máy, rồi upload:

| Nguồn (trong zip) | Đích trên hosting |
|---|---|
| `public/*` (webhook.php, zalo_webhook.php, .htaccess) | Document root của subdomain (bước 0) |
| `private/*` | `/home/USER/cashback_private/` — NGOÀI public_html, tự tạo thư mục này |
| `tests.php`, `test_event.example.json` | `/home/USER/cashback_private/` (chạy CLI, không public) |

Dùng File Manager hoặc FTP. Không để file `.sql`, `.json`, backup trong document root.

## 3. Tạo database + import schema

- Tạo DB và user:
  cPanel: MySQL Databases → tạo `USER_cashback` + user + mật khẩu mạnh → Add User to Database (All Privileges).
  DirectAdmin: Account Manager → MySQL Management → tương tự.
- Import schema:
  - Cài mới: phpMyAdmin → chọn DB → Import → file `private/schema.sql`.
  - Nâng cấp từ v0.1: BACKUP DB trước (Export), rồi Import file
    `private/migrate-v01-v02.sql` MỘT lần duy nhất.

## 4. Tạo config.php

1. Trong `/home/USER/cashback_private/`, copy `config.example.php` → `config.php`.
2. Sinh 2 secret KHÁC NHAU (Terminal của hosting):
   `php -r 'echo bin2hex(random_bytes(32)),PHP_EOL;'` (chạy 2 lần).
3. Điền: dsn (`dbname=USER_cashback`), db_user, db_password, telegram_token
   (từ BotFather), webhook_secret, bank_key, webhook_url
   (`https://bot.tenmien.vn/webhook.php`), affiliate_id, tỷ lệ chia,
   min rút, hạn mức link, cooldown, outbox_retention_days, allowed_users
   (tạm để trống `[]`, bổ sung ở bước 8), admins, source_chats, Zalo (để trống
   nếu chưa dùng).
4. Đặt quyền file config: chuột phải `config.php` → Change Permissions → `600`.

## 5. Kiểm tra trên hosting trước khi mở webhook

Trong Terminal, kiểm tra cú pháp toàn bộ file PHP (thay USER và tên miền cho đúng):

```
cd /home/USER/cashback_private
for f in core.php zalo.php setup.php worker.php import_test.php zalo_refresh.php; do php -l $f; done
php -l /home/USER/bot.tenmien.vn/webhook.php
php -l /home/USER/bot.tenmien.vn/zalo_webhook.php
```

- Chạy test với **DB test riêng** (tên DB chứa chữ "test", tạo thêm 1 DB test):
  sửa tạm dsn trong config trỏ sang DB test → `php tests.php` → phải thấy
  toàn PASS → đổi dsn về DB chính. TUYỆT ĐỐI không chạy tests.php ở DB chính.

## 6. Đăng ký webhook Telegram + cron

```
cd /home/USER/cashback_private
php setup.php
```

- Kiểm tra webhook đã nhận đúng chưa (mở 1 lần trên trình duyệt riêng tư rồi đóng):
  `https://api.telegram.org/bot<TOKEN>/getWebhookInfo`
  phải thấy `url` đúng subdomain và `pending_update_count` nhỏ.
- Tạo cron (cPanel: Advanced → Cron Jobs; DirectAdmin: Advanced Features → Cron Jobs),
  Common Settings: Every Minute:

```
/opt/alt/php83/usr/bin/php /home/USER/cashback_private/worker.php >/dev/null 2>&1
```

  Thay đường PHP CLI và USER cho đúng máy (xem bước 1). Nếu nhà cung cấp giới hạn
  tối thiểu 5–15 phút thì tin nhắn sẽ trễ tương ứng — vẫn test được.

## 7. Kênh Zalo OA (nếu dùng)

1. Có OA + app tại developers.zalo.me, liên kết OA–app; lấy access_token,
   refresh_token, app_id, app_secret điền vào config.
2. Trong dashboard Zalo, đăng ký callback:
   `https://bot.tenmien.vn/zalo_webhook.php?secret=<webhook_secret>`
   (lấy đúng giá trị webhook_secret trong config.php).
3. Mỗi người follow OA → bot trả key `zalo:...` → admin thêm vào allowed_users.
4. Token hết hạn: `php zalo_refresh.php` → chép 2 token mới vào config.

## 8. Mở quyền và chạy thử end-to-end

1. Hai người chat riêng với bot Telegram (và follow OA nếu dùng Zalo), gõ `/id`.
2. Admin điền key vào `allowed_users` trong config.php (`tg:...`, `zalo:...`),
   admins là key chủ. Không cần chạy lại setup.
3. Gửi link sản phẩm Shopee → bot trả link tracking (ghi `[TEST — tracking chưa xác minh]`).
4. Lấy token từ `/links`, sửa `test_event.example.json`, chạy:
   `php import_test.php /home/USER/test-event.json`
5. Chờ cron (~1 phút) → khách nhận tin đơn, admin nhận tin settled.
6. `/balance` → `/bank ...` → `/withdraw 10000` → admin `/requests`
   → `/approve` → `/paid ... TEST_REF`. Kiểm tra số dư sau mỗi bước.
7. Nâng cấp từ v0.1: mỗi user gửi lại `/bank` một lần để chuyển sang bản mã hóa.

## 9. Xử lý sự cố thường gặp

| Hiện tượng | Kiểm tra |
|---|---|
| Webhook 403 | `webhook_url`/secret trong config khác với đã đăng ký → chạy lại `setup.php`; Zalo: sai `?secret=` |
| Bot im lặng, tin đến chậm | Cron đã chạy chưa (log cron panel); `getWebhookInfo` xem pending; nhóm Telegram: bot chưa tắt privacy (BotFather → /setprivacy → Disable) hoặc thiếu quyền đọc tin |
| 503 / Retry | DB sai user/pass, DB đầy (host 1,8 GB), PHP thiếu extension → xem Error Log trong panel |
| Lỗi đọc bank (legacy) | DB từ v0.1: nhắc user gửi lại `/bank` |
| Zalo không trả lời | Token hết hạn (`zalo_refresh.php`); người gửi chưa follow OA; sai callback URL |
| Nghi Imunify360/ModSecurity chặn | Mở Metrics → Errors / ModSecurity Tools xem log, hoặc hỏi nhà cung cấp whitelist subdomain |
| PHP web và PHP CLI khác version | `php -v` trong Terminal so với Select PHP Version; cron phải dùng đúng binary 8.2/8.3 |

An toàn nhắc lại: config.php quyền 600, ngoài public_html, không commit secret;
dữ liệu bank giai đoạn test dùng giá trị giả; giữ nguyên mode TEST.

## v0.7.1 — Durable relay queue, relay watchdog, Open API mode

Upload (overwrite): `private/core.php`, `private/web.php`, `private/setup.php`, `private/worker.php`,
`private/config.example.php`, `private/migrate-v06-v07.sql`, `private/migrate-v07-durable.sql`,
`public/dashboard.php`, `public/admin.php`, `tests.php`, `tests_ui.php`.

Then open `setup.php` once (adds `user_id/share_bps/async` columns to `cb_link_jobs`), and run `tests.php` on the TEST DB.

- **Durable queue (relay mode):** if the extension doesn't answer within `relay_wait_seconds`, the request stays queued (up to 6 h).
  When the extension pushes the link, the server creates it, writes a web notification, and sends Telegram/Zalo (except to guests).
  Customers see "Đang tạo link…" (link in progress) instead of an error. Pasting the same link again doesn't create a duplicate job.
- **Watchdog:** the `worker.php` cron alerts admins when the relay has been silent for more than 5 minutes or is paused.
  The alert repeats at most once per hour, and one message goes out when the relay recovers. Requires the existing worker cron (every minute).
- **Open API (`link_mode => 'open_api'`):** set `open_api_app_id` (digits) and `open_api_secret` in `config.php`.
  Admin → "Test link" calls the Open API. The auth header format and the `generateShortLink` mutation follow the public docs.
  **Check both against the official document Shopee sends with your AppId** before going live.
