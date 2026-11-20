# Cashback Link Relay (Chrome extension, giải pháp tạm)

Tạo link Custom Link có `sub_id` **trong tab affiliate.shopee.vn bạn đang đăng nhập**, thay cho
việc server tự gọi Shopee bằng cookie (bị chặn HTTP 403 / error 90309999).
Dùng tạm cho tới khi có Shopee Affiliate Open API.

⚠️ Tự động hóa trang affiliate có thể trái điều khoản Shopee. Extension có giới hạn tốc độ
(cách nhau ≥ 2,5 giây, mặc định tối đa 300 link/ngày), **không giả mạo header hay dấu vết trình
duyệt**, và **tự dừng** ngay khi Shopee từ chối. Khi dừng, đừng cố bật lại liên tục.

## Cách hoạt động

```
Khách dán link ─► web/bot (PHP) ─► bảng cb_link_jobs (queued)
                                      ▲            │ pull (mỗi 4 giây)
                                      │ push        ▼
                       extension (tab affiliate.shopee.vn đang đăng nhập)
                         └─ gọi Custom Link như chính trang đó, nhận shortLink
PHP chờ tối đa relay_wait_seconds (mặc định 12 giây) rồi trả link cho khách.
```

Chỉ một tab affiliate làm việc tại một thời điểm. Job quá 90 giây bị huỷ; job đang làm mà
treo quá 30 giây được giao lại (tối đa 3 lần).

## Cài đặt

### 1. Server (bot.dailyhotdeals.biz)
1. Upload `private/core.php`, `private/setup.php`, `private/migrate-v06-v07.sql` vào
   `~/cashback_private/`; `public/api/relay.php` vào `api/` của thư mục web; `public/admin.php`.
2. Tạo bảng: `cd ~/cashback_private && php setup.php` (hoặc import `migrate-v06-v07.sql` trong phpMyAdmin cho DB chính + DB test).
3. Sinh khoá: `php -r 'echo bin2hex(random_bytes(32)),PHP_EOL;'`
4. Sửa `config.php`:
   ```php
   'link_mode' => 'relay',
   'relay_key' => '<64 ký tự vừa sinh>',
   'relay_wait_seconds' => 12,
   ```

### 2. Chrome (máy luôn bật, ví dụ máy bạn)
1. Mở `chrome://extensions` → bật **Developer mode** → **Load unpacked** → chọn thư mục `link-relay-extension`.
2. Bấm biểu tượng extension → nhập Backend `https://bot.dailyhotdeals.biz`, Relay key (giống `config.php`), tick **Bật relay** → **Lưu** → **Thử backend** (phải báo “Backend OK”).
3. Mở `https://affiliate.shopee.vn` và đăng nhập; **giữ tab này mở** (có thể thu nhỏ cửa sổ, đừng đóng tab, đừng để máy ngủ).

Biểu tượng: **ON** = đang chạy · **!** = tạm dừng (xem lý do trong popup) · **OFF** = tắt.

## Kiểm thử

1. Trang `admin.php` → mục Nguồn chuyển đổi hiện “relay (extension) — lần cuối liên lạc: …” màu xanh (≤ 2 phút).
2. `admin.php` → **Thử tạo link** chỉ dùng cho chế độ `custom_link`; với relay hãy thử trực tiếp: vào web, dán một link sản phẩm → trong ~5 giây phải nhận link `https://s.shopee.vn/...`.
3. Mở link đó → trang Shopee cuối có `utm_content=<sub_id>----` (xem `docs/TRACKING_DIAG.md`).
4. Nếu popup báo “Tạm dừng: Shopee từ chối (HTTP 403 …)”: cách này cũng bị Shopee chặn → quay về chờ Open API, đặt lại `link_mode` cũ.
5. Test offline của extension: `node test/relay-main.test.js relay-main.js` (giả lập phản hồi Shopee).
6. Test server (DB test): `php tests.php` — các ca `relay*`.

## Xử lý sự cố

| Hiện tượng | Nguyên nhân / cách xử lý |
|---|---|
| Khách thấy “Tạm thời chưa tạo được link…”, admin ghi “extension không phản hồi” | Chrome tắt, tab affiliate đóng, máy ngủ, hoặc relay đang tạm dừng |
| Popup: “Backend từ chối relay key” | Key trong popup khác `relay_key` trong `config.php` |
| Popup: “Phiên affiliate đã đăng xuất” | Đăng nhập lại affiliate.shopee.vn rồi bấm **Tiếp tục** |
| Popup: “Đạt giới hạn … link/ngày” | Tăng giới hạn trong popup (thận trọng) hoặc chờ sang ngày |
