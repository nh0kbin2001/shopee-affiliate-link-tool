# Shopee Cashback Private Bot — PHP hosting TEST v0.6

Bản thử cho chủ hệ thống và bạn bè. PHP 8.1–8.3 64-bit, MySQL/InnoDB.
Kênh: Telegram bot (nút bấm + lệnh), Zalo OA (lệnh), và **web dashboard** (đăng nhập Telegram).

## Mới trong v0.6 (so với v0.5)

- Bố cục web sát mẫu app hoàn tiền: Tổng quan có 4 ô số liệu, Đơn hàng có thanh quy
  trình + tab trạng thái kèm số đếm, màn Tài chính (hero số dư + tổng vào/ra + 2 bước),
  thêm tab **Hồ sơ** (hạng, thống kê, đổi giao diện, bật thông báo), Trợ giúp có kênh
  liên hệ + FAQ đánh số.
- **Toggle tính năng cho admin**: bật/tắt **Deal cộng đồng** và **Mời bạn bè** trong
  trang Quản trị (lưu ở `cb_settings`). Bật thì mục tương ứng hiện cho khách; admin sửa
  nội dung Deal ngay tại đó. Đây là phần để **nghiên cứu**, chưa có luật thưởng/kiểm duyệt.
- Sửa hiển thị thời gian (không còn in thẻ `<time>`), nút "Mở" đổi thành nút đồng bộ với
  "Sao chép", thêm cache-bust `?v=` cho CSS/JS.

## Chi tiết đối chiếu Caffiliate

Xem `CAFFILIATE_COMPARE_v06.md`.

## Nền tảng (từ v0.5)

Giao diện sáng/tối, bố cục tab, dán link trên web, thông báo web, xuất CSV, cùng toàn bộ
luật tiền và chống lạm dụng. Không có bộ lấy đơn Shopee thật, không tự chuyển khoản.
Chỉ chấp nhận mode TEST.
Không phụ thuộc API thương mại Addlivetag. Không có bộ lấy đơn Shopee thật,
không tự đăng nhập Shopee, không chuyển khoản ngân hàng và chưa triển khai lên hosting.
Link tracking dài được dựng theo mô tả của Addlivetag; cần xác minh bằng báo cáo
thực tế của tài khoản trước khi dùng tiền thật. Bản này chỉ chấp nhận mode TEST.

## Mới trong v0.5 (so với v0.4)

- **Giao diện sáng/tối** (token màu, mặc định theo hệ điều hành, nhớ lựa chọn), bố cục
  header một hàng + thanh tab đáy trên điện thoại, bảng tự chuyển thành thẻ trên mobile.
- **Dán link Shopee ngay trên web**: dùng chung `safeUrl/canonical/resolveShort/newLink`
  và luật hạn mức `assertLinkQuota()` với bot.
- **Hiện link hoàn tiền** kèm nút Sao chép / Mở tab mới (`rel="noopener noreferrer"`).
- **Thông báo web**: mức 1 (hộp thông báo `cb_notifications` + chuông) và mức 2
  (`api/notifications.php` poll 45 giây, toast + thông báo trình duyệt khi tab còn mở).
- **Tổng quan số liệu, dòng thời gian đơn, lọc/phân trang, FAQ, xuất CSV** (admin).
- Sửa lỗi B1–B6: web rút tiền báo admin, web nộp tiền báo khách, Post/Redirect/Get
  (F5 không lặp), bỏ inline style, gộp truy vấn khách hàng (hết N+1), trang lỗi CSRF.
- Bỏ emoji khỏi nhãn trạng thái trên web (bot vẫn dùng `stateLabel()` riêng).

## Cài mới lên hosting

Theo `DEPLOY.md`. Nâng cấp v0.4 → v0.5 xem mục đầu file `DEPLOY.md`.

## Lệnh khách / admin

Như v0.4 (xem dưới), thêm `/policy` cho khách và `/credit`, `/debit`, `/setpolicy`,
`/customers`, `/audit` cho admin. Trên web: tab Tổng quan / Link / Đơn hàng / Ví / Trợ giúp;
admin có màn Quản trị (duyệt, nộp/trừ tiền, chính sách, khách hàng, nhật ký, xuất CSV).

## Mới trong v0.4 (so với v0.3)

- **Web dashboard**: đăng nhập bằng Telegram Login Widget (không cần mật khẩu, khớp key
  `tg:<id>` sẵn có). Khách xem số dư/đơn/link, cập nhật nhận tiền, tạo yêu cầu rút.
  Trang quản trị duyệt/từ chối/ghi trả, nộp/trừ tiền thủ công, sửa chính sách, xem khách + nhật ký.
  Bảo vệ: session `HttpOnly`+`Secure`, CSRF mọi POST, chỉ tài khoản trong allowlist.
- **Bộ quản trị trong Telegram**: menu thêm Nộp tiền / Trừ tiền / Sửa chính sách / Khách hàng / Nhật ký.
  Lệnh admin mới: `/credit`, `/debit`, `/setpolicy`, `/customers`, `/audit`. Khách xem `/policy`.
- **Bù đơn sót**: admin nộp/trừ tiền thủ công (`cb_adjustments`), mọi thao tác có nhật ký và báo khách kèm lý do.
- **Chính sách bảo hành** lưu trong `cb_settings`, sửa được từ Telegram hoặc web.
- **Trả lời thân thiện tiếng Việt có icon**, không còn xổ JSON thô.
- Schema thêm `cb_settings`, `cb_adjustments` (nâng cấp bằng `migrate-v03-v04.sql`).

## Cài mới lên hosting

Theo `DEPLOY.md`. Nâng cấp v0.3 → v0.4: chạy `migrate-v03-v04.sql`, upload đè
`core.php`, `tests.php`, thêm các file web (`_boot.php`, `index.php`, `login.php`,
`dashboard.php`, `admin.php`, `logout.php`, `assets/style.css`) vào document root,
thêm `web.php` vào thư mục private, rồi chạy lại `php setup.php` (không bắt buộc nhưng an toàn).
Trong BotFather cần `/setdomain` → `bot.dailyhotdeals.biz` để widget đăng nhập hoạt động.

## Lệnh khách (chat riêng) — hoặc bấm nút trong /menu

```
/start, /help, /menu, /id, /balance, /links, /orders, /policy
/bank                       (xem bản đã che)
/bank ngân_hàng | số_tài_khoản | tên_chủ
/withdraw 10000             (tối thiểu theo config; 1 yêu cầu chờ tại một thời điểm)
/support nội_dung
/cancel                     (hủy nhập liệu đang chờ)
```

## Lệnh admin (chat riêng) — duyệt/từ chối bấm nút ngay trên tin báo

```
/requests
/approve REQUEST_ID
/reject REQUEST_ID
/cancel REQUEST_ID
/paid REQUEST_ID MA_THAM_CHIEU_TEST
/credit tg:ID số_tiền [lý do]     (nộp/bù ví)
/debit  tg:ID số_tiền [lý do]     (trừ ví)
/setpolicy nội_dung               (sửa chính sách bảo hành)
/customers                        (danh sách khách + số dư)
/audit                            (15 dòng nhật ký mới nhất)
```

`/paid` chỉ ghi sổ test, KHÔNG gọi ngân hàng. Không cho thao tác ví trong nhóm.
Khách chuyển tiếp tin nhắn/link sẽ được gắn tracking cho người đang gửi đến bot,
không cho tác giả gốc. Không phân phối link từ kênh chung thành link khách riêng:
người mua cần tương tác với bot để xác định người hưởng phần chia.

## Bộ kết nối chuyển đổi — CHƯA có nguồn thật

Hiện có bộ nhập JSON cục bộ để kiểm thử nghiệp vụ, không thay thế tự động lấy đơn.
Dùng /links lấy token đã lưu. Sửa test_event.example.json rồi chạy:
`php /home/USER/cashback_private/import_test.php /home/USER/test-event.json`
Sự kiện chỉ nhận từ file CLI do chủ hosting kiểm soát; không có API public cộng tiền.
Các state: pending, validated, settled, rejected. Chỉ settled cấp tiền được rút.
Một conversion_id phải định danh từng dòng đơn/sản phẩm/biến thể theo nguồn thực tế.
commission_vnd là tổng hoa hồng của dòng, không phải giá sản phẩm.
Mỗi lần cập nhật cần event_id mới. Lặp đúng event không cộng trùng; cùng event_id
khác dữ liệu bị từ chối. Không tự coi trạng thái giao hàng thành công là settled.
Nguồn tương lai phải xác minh tracking, hoa hồng thực nhận, version/thứ tự cập nhật,
trả hàng, bản ghi phân trang và lịch đối soát. Adapter test hiện nhận theo thứ tự file;
không tự xử lý sự kiện lỗi thứ tự từ một nguồn trực tuyến.

## Kiểm thử sau cài / giới hạn

Chạy `php -l` từng file trước khi deploy và `php tests.php` với một DB TEST trống
(tên DB chứa chữ "test"). File tests.php tạo và xóa dữ liệu TEST trong DB cấu hình,
tuyệt đối không chạy ở DB sản xuất. Bao phủ: tracking riêng từng khách, idempotent
event, chia đúng tỷ lệ, rút giữ tiền, máy trạng thái duyệt/trả, đảo bút toán khi đơn
bị từ chối, hạn mức link/cooldown (+audit), 1 yêu cầu rút chờ (+audit), nút bấm
menu/duyệt (kèm chống bấm trùng), nhập liệu chờ + `/cancel`, `/id` hiện key.
Telegram at-least-once: trùng tin nhắn có thể xảy ra khi mạng đứt đúng lúc gửi;
tiền không trùng nhờ transaction + dedupe event + máy trạng thái.
Chưa có đăng ký công khai, AI tự trả lời, product scraping, đơn Shopee
thật, thanh toán tự động. Dữ liệu ngân hàng test nên dùng giá trị giả. Khi chuyển
sang tiền thật cần bổ sung kiểm soát nguồn chuyển đổi, audit/phân quyền, bảo vệ
dữ liệu và xác minh tracking.

## Nguồn mô tả giao thức

- https://core.telegram.org/bots/api#setwebhook
- https://core.telegram.org/bots/buttons
- https://developers.zalo.me/docs/api/official-account-api-431
- https://data.addlivetag.com/shopee/aff-link.html (nguồn bên thứ ba, không phải cam kết Shopee)
- https://github.com/bcat95/shopee-aff/blob/main/product-data-api.md
