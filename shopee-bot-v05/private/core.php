<?php
declare(strict_types=1);
// Shopee cashback TEST bot core v0.4 — Telegram buttons + web dashboard.
// TEST ONLY: fake conversion import, no bank transfer, no real Shopee source.
// User keys are namespaced: Telegram 'tg:<numeric_id>', Zalo 'zalo:<sender_id>'.
// Money rules: mutations serialized by advisory lock + transaction; conversions
// idempotent by event_id+digest; at most ONE 'requested' withdrawal per user.
// All chat replies are friendly Vietnamese with icons (no raw JSON).
function cfgValidate(array $c): array {
    if (PHP_INT_SIZE !== 8 || ($c['mode'] ?? '') !== 'TEST') throw new RuntimeException('64-bit PHP and TEST mode required.');
    if (!preg_match('/^\d+$/', (string)($c['affiliate_id'] ?? ''))) throw new RuntimeException('Set numeric Affiliate ID.');
    $ints = ['customer_share_bps' => [0, 10000], 'min_withdraw_vnd' => [0, 1000000000000], 'max_links_per_day' => [1, 10000], 'link_cooldown_seconds' => [0, 86400], 'outbox_retention_days' => [1, 90]];
    foreach ($ints as $k => [$lo, $hi]) if (!is_int($c[$k] ?? null) || $c[$k] < $lo || $c[$k] > $hi) throw new RuntimeException('Invalid '.$k.'.');
    foreach (['allowed_users', 'admins'] as $key) {
        if (!is_array($c[$key] ?? null)) throw new RuntimeException('Invalid allowlist.');
        foreach ($c[$key] as $v) if (!is_string($v) || !preg_match('/^(tg:\d{1,20}|zalo:[A-Za-z0-9_.\-]{1,80})$/', $v)) throw new RuntimeException('Allowlist entries must look like tg:123 or zalo:abc.');
    }
    if (!$c['admins'] || array_diff($c['admins'], $c['allowed_users'])) throw new RuntimeException('Admin must be allowed user.');
    foreach ($c['source_chats'] ?? [] as $v) if (!is_int($v)) throw new RuntimeException('Group IDs must be integers.');
    foreach (['webhook_secret', 'bank_key'] as $k) if (!preg_match('/^[a-f0-9]{64}$/', (string)($c[$k] ?? ''))) throw new RuntimeException('Set random 64-hex '.$k.'.');
    if (hash_equals((string)$c['webhook_secret'], (string)$c['bank_key'])) throw new RuntimeException('bank_key must differ from webhook_secret.');
    if (!is_bool($c['resolve_short_links'] ?? null)) throw new RuntimeException('resolve_short_links must be boolean.');
    $c['link_mode'] = $c['link_mode'] ?? 'custom_link';
    if (!in_array($c['link_mode'], ['custom_link', 'relay', 'open_api', 'an_redir'], true)) throw new RuntimeException('link_mode must be custom_link, relay, open_api or an_redir.');
    $c['open_api_app_id'] = (string)($c['open_api_app_id'] ?? '');
    $c['open_api_secret'] = (string)($c['open_api_secret'] ?? '');
    $c['open_api_url'] = (string)($c['open_api_url'] ?? 'https://open-api.affiliate.shopee.vn/graphql');
    if ($c['link_mode'] === 'open_api') {
        if (!preg_match('/^\d{1,20}$/', $c['open_api_app_id'])) throw new RuntimeException('link_mode open_api needs numeric open_api_app_id.');
        if (strlen($c['open_api_secret']) < 16 || strlen($c['open_api_secret']) > 200) throw new RuntimeException('link_mode open_api needs open_api_secret.');
    }
    if (!preg_match('~^https://[a-z0-9.-]+\.shopee\.vn/~', $c['open_api_url'])) throw new RuntimeException('open_api_url must be https on shopee.vn.');
    $c['relay_key'] = $c['relay_key'] ?? '';
    if ($c['link_mode'] === 'relay' && !preg_match('/^[a-f0-9]{64}$/', (string)$c['relay_key'])) throw new RuntimeException('link_mode relay needs a random 64-hex relay_key.');
    if ($c['relay_key'] !== '' && (hash_equals((string)$c['relay_key'], (string)$c['webhook_secret']) || hash_equals((string)$c['relay_key'], (string)$c['bank_key']))) throw new RuntimeException('relay_key must differ from other secrets.');
    $c['relay_wait_seconds'] = $c['relay_wait_seconds'] ?? 12;
    if (!is_int($c['relay_wait_seconds']) || $c['relay_wait_seconds'] < 3 || $c['relay_wait_seconds'] > 25) throw new RuntimeException('relay_wait_seconds must be 3..25.');
    $c['guest_mode'] = $c['guest_mode'] ?? false;
    if (!is_bool($c['guest_mode'])) throw new RuntimeException('guest_mode must be boolean.');
    foreach (['zalo_access_token', 'zalo_app_id', 'zalo_app_secret', 'zalo_refresh_token', 'bot_username'] as $k) if (!is_string($c[$k] ?? null) || strlen((string)($c[$k] ?? '')) > 500) throw new RuntimeException('Invalid '.$k.'.');
    return $c;
}
function cfg(): array {
    $c = require __DIR__.'/config.php';
    if (!is_array($c)) throw new RuntimeException('Bad config.');
    return cfgValidate($c);
}
function tgKey(int $id): string { return 'tg:'.$id; }
function zaloKey(string $id): string { return 'zalo:'.$id; }
function routeFor(string $key): array {
    // Returns [channel, target] used by queue()/delivery. Throws on unknown key.
    if (str_starts_with($key, 'tg:') && ctype_digit(substr($key, 3))) return ['tg', (int)substr($key, 3)];
    if (str_starts_with($key, 'zalo:') && strlen($key) > 5) return ['zalo', substr($key, 5)];
    throw new InvalidArgumentException('Unknown user key.');
}
// Web guests: 'guest:<32 hex>' bound to a long-lived browser cookie. Guests can
// make links and see orders, but have no chat channel and cannot withdraw until
// they log in and claimGuest() moves their data to the real account.
function guestKey(string $hex): string { return 'guest:'.$hex; }
function isGuest(string $key): bool { return (bool)preg_match('/^guest:[a-f0-9]{32}$/', $key); }
// Move everything a guest owns to $ukey. Caller wraps this in atomic().
// Same product linked by both: keep the user's link; the guest's copy is dropped
// if it has no orders, otherwise kept with a suffixed origin so no order is lost.
function claimGuest(PDO $d, string $guest, string $ukey): array {
    if (!isGuest($guest) || isGuest($ukey)) throw new InvalidArgumentException('Bad guest claim.');
    q($d, 'INSERT IGNORE INTO cb_users(id) VALUES(?)', [$ukey]);
    $out = ['links' => 0, 'orders' => 0];
    foreach (q($d, 'SELECT token,origin FROM cb_links WHERE user_id=? FOR UPDATE', [$guest])->fetchAll() as $l) {
        $orders = (int)q($d, 'SELECT COUNT(*) FROM cb_conversions WHERE token=?', [$l['token']])->fetchColumn();
        $dup = (bool)q($d, 'SELECT token FROM cb_links WHERE user_id=? AND origin=?', [$ukey, $l['origin']])->fetch();
        if ($dup && $orders === 0) { q($d, 'DELETE FROM cb_clicks WHERE token=?', [$l['token']]); q($d, 'DELETE FROM cb_links WHERE token=?', [$l['token']]); continue; }
        $origin = $dup ? mb_substr($l['origin'], 0, 170).'#g'.$l['token'] : $l['origin'];
        q($d, 'UPDATE cb_links SET user_id=?, origin=? WHERE token=?', [$ukey, $origin, $l['token']]);
        $out['links']++; $out['orders'] += $orders;
    }
    q($d, 'UPDATE cb_notifications SET user_id=? WHERE user_id=?', [$ukey, $guest]);
    q($d, 'DELETE FROM cb_users WHERE id=?', [$guest]);
    if ($out['links'] > 0) { audit($d, $ukey, 'guest.claim', $guest); notify($d, $ukey, 'info', 'Đã liên kết dữ liệu khách', $out['links'].' link, '.$out['orders'].' đơn đã chuyển vào tài khoản của bạn.'); }
    return $out;
}
function ownKey(array $ctx): string {
    // Display key for /id: allowlisted key when known, else derived from sender ref.
    if (($ctx['sender_key'] ?? null) !== null) return (string)$ctx['sender_key'];
    $ref = (string)($ctx['sender_ref'] ?? '');
    if (($ctx['channel'] ?? '') === 'zalo') return $ref !== '' ? zaloKey($ref) : 'zalo:?';
    return tgKey((int)$ref);
}
function db(array $c): PDO {
    return new PDO($c['dsn'], $c['db_user'], $c['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
function q(PDO $d, string $sql, array $args = []): PDOStatement { $s = $d->prepare($sql); $s->execute($args); return $s; }
function atomic(PDO $d, callable $fn): mixed {
    // Dedicated small TEST bot: one advisory lock serializes wallet mutations.
    // No network request inside this transaction. A nested call (only tests
    // wrap everything in an outer transaction) degrades to a savepoint.
    if ($d->inTransaction()) {
        $sp = 'sp_'.bin2hex(random_bytes(4));
        q($d, 'SAVEPOINT '.$sp);
        try { $r = $fn(); q($d, 'RELEASE SAVEPOINT '.$sp); return $r; }
        catch (Throwable $e) { q($d, 'ROLLBACK TO SAVEPOINT '.$sp); throw $e; }
    }
    if ((int)q($d, "SELECT GET_LOCK('cb_test_mutations',5)")->fetchColumn() !== 1) throw new RuntimeException('Busy; retry.');
    try { $d->beginTransaction(); $r = $fn(); $d->commit(); return $r; }
    catch (Throwable $e) { if ($d->inTransaction()) $d->rollBack(); throw $e; }
    finally { q($d, "SELECT RELEASE_LOCK('cb_test_mutations')"); }
}
function queue(PDO $d, string $key, string $channel, string $target, string $text, ?string $markup = null): void {
    if (!in_array($channel, ['tg', 'zalo'], true) || $target === '') throw new InvalidArgumentException('Bad outbox target.');
    q($d, 'INSERT IGNORE INTO cb_outbox(id,channel,target,message,markup) VALUES(?,?,?,?,?)', [$key, $channel, $target, $text, $markup]);
}
function audit(PDO $d, string $actor, string $action, string $ref, string $detail = ''): void {
    q($d, 'INSERT INTO cb_audit(actor,action,ref,detail) VALUES(?,?,?,?)', [mb_substr($actor, 0, 64), mb_substr($action, 0, 50), mb_substr($ref, 0, 200), mb_substr($detail, 0, 1000)]);
}
function notify(PDO $d, string $ukey, string $kind, string $title, string $body, ?int $amount = null): void {
    // Web notification (separate from the Telegram/Zalo outbox). Read by the web UI.
    q($d, 'INSERT INTO cb_notifications(user_id,kind,title,body,amount) VALUES(?,?,?,?,?)', [$ukey, mb_substr($kind, 0, 20), mb_substr($title, 0, 120), mb_substr($body, 0, 500), $amount]);
}
function assertLinkQuota(PDO $d, array $c, string $ukey, ?array &$outcome = null): void {
    // Shared daily-quota + cooldown rule used by BOTH the bot and the web.
    $today = date('Y-m-d').' 00:00:00';
    $made = (int)q($d, 'SELECT COUNT(*) FROM cb_links WHERE user_id=? AND created_at>=?', [$ukey, $today])->fetchColumn();
    $made += (int)q($d, "SELECT COUNT(*) FROM cb_link_jobs WHERE user_id=? AND async=1 AND state IN ('queued','working') AND created_at>=?", [$ukey, $today])->fetchColumn();
    if ($made >= $c['max_links_per_day']) { if ($outcome !== null) $outcome['blocked'] = 'quota-daily'; throw new InvalidArgumentException('⏰ Hôm nay bạn đã tạo đủ '.$c['max_links_per_day'].' link rồi, mai thử lại nhé!'); }
    $last = (int)q($d, 'SELECT last_link_at FROM cb_users WHERE id=?', [$ukey])->fetchColumn();
    if ($c['link_cooldown_seconds'] > 0 && time() - $last < $c['link_cooldown_seconds']) { if ($outcome !== null) $outcome['blocked'] = 'cooldown'; throw new InvalidArgumentException('🐢 Tạo link hơi nhanh, chờ '.$c['link_cooldown_seconds'].' giây giữa các lượt nhé.'); }
}
// ---- Friendly Vietnamese formatting (icons, no raw JSON) ----
function fmtVnd(int $n): string { return number_format($n, 0, ',', '.').'đ'; }
function stateLabel(string $s): string {
    $m = ['pending' => '⏳ chờ xử lý', 'validated' => '🔎 đã đối soát', 'settled' => '✅ hoàn tất', 'rejected' => '❌ đã hủy', 'requested' => '📨 chờ duyệt', 'approved' => '👍 đã duyệt', 'paid' => '💸 đã trả'];
    return $m[$s] ?? $s;
}
function renderBalance(array $b): string {
    return "💰 Số dư của bạn\n• Khả dụng: ".fmtVnd((int)$b['available'])."\n• Chờ đối soát: ".fmtVnd((int)$b['pending']);
}
function renderLinks(array $rows): string {
    if (!$rows) return "🔗 Bạn chưa có link nào.\nGửi link sản phẩm Shopee cho mình để tạo link hoàn tiền nhé!";
    $out = ["🔗 Link gần đây:"];
    foreach ($rows as $i => $r) $out[] = ($i + 1).'. 🛍️ '.$r['origin']."\n   🏷️ Mã: ".substr((string)$r['token'], 0, 10)."…\n   📅 ".$r['created_at'];
    return implode("\n", $out);
}
function renderOrders(array $rows): string {
    if (!$rows) return "🧾 Chưa có đơn nào.\nKhi Shopee xác nhận đơn, mình sẽ báo bạn ngay!";
    $out = ["🧾 Đơn gần đây:"];
    foreach ($rows as $r) $out[] = "• ".$r['id']."\n   Trạng thái: ".stateLabel((string)$r['state'])."\n   Hoa hồng: ".fmtVnd((int)$r['commission'])."\n   Phần bạn: ".fmtVnd((int)$r['share']);
    return implode("\n", $out);
}
function renderRequests(array $rows): string {
    if (!$rows) return "📭 Không có yêu cầu rút nào đang chờ.";
    $out = ["📥 Yêu cầu rút đang chờ:"];
    foreach ($rows as $r) $out[] = "• ".$r['id']." — ".fmtVnd((int)$r['amount'])."\n   👤 ".$r['user_id']."  ".stateLabel((string)$r['state']);
    return implode("\n", $out);
}
function renderCustomers(PDO $d, array $rows): string {
    if (!$rows) return "👥 Chưa có khách nào.";
    $out = ["👥 Khách (30 gần nhất):"];
    foreach ($rows as $r) { $b = balance($d, (string)$r['id']); $out[] = "• ".$r['id']."\n   💰 Khả dụng ".fmtVnd((int)$b['available'])." · ⏳ Chờ ".fmtVnd((int)$b['pending']); }
    return implode("\n", $out);
}
function renderAudit(array $rows): string {
    if (!$rows) return "📋 Nhật ký trống.";
    $out = ["📋 Nhật ký (15 dòng mới nhất):"];
    foreach ($rows as $r) $out[] = "• ".$r['at'].' · '.$r['actor'].' · '.$r['action'].' · '.$r['ref'];
    return implode("\n", $out);
}
// ---- Settings (editable text such as the warranty policy) ----
function defaultPolicy(): string {
    return "🛡️ Chính sách bảo hành (bản TEST)\n• Hoa hồng chỉ được cộng khi Shopee xác nhận đơn ở trạng thái hoàn tất.\n• Đơn hủy/hoàn không được cộng; nếu đã cộng sẽ bị đảo lại.\n• Khiếu nại trong vòng 7 ngày kể từ ngày đơn hoàn tất.\n• Admin có thể điều chỉnh ví thủ công khi có sai sót và sẽ báo kèm lý do.\n🧪 Bản test: chưa có đơn Shopee thật, không chuyển tiền ngân hàng.";
}
function setting(PDO $d, string $k, string $default = ''): string {
    $v = q($d, 'SELECT v FROM cb_settings WHERE k=?', [$k])->fetchColumn();
    return $v === false ? $default : (string)$v;
}
function setSetting(PDO $d, string $k, string $v): void {
    // cb_settings.v is TEXT (65535 bytes). Refuse instead of truncating: a cut
    // encrypted blob (cookie) would silently become unreadable.
    if (strlen($v) > 60000) throw new InvalidArgumentException('Giá trị cài đặt quá dài.');
    q($d, 'INSERT INTO cb_settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$k, $v]);
}
function currentPolicy(PDO $d): string { return setting($d, 'policy', defaultPolicy()); }
// ---- Admin wallet adjustment (top-up / fix for a missed update) ----
function resolveUserKey(string $s): string {
    $s = trim($s);
    if (preg_match('/^tg:\d{1,20}$/', $s)) return $s;
    if (preg_match('/^zalo:[A-Za-z0-9_.\-]{1,80}$/', $s)) return $s;
    if (ctype_digit($s) && strlen($s) <= 20) return tgKey((int)$s);
    throw new InvalidArgumentException('Người dùng không hợp lệ (dùng dạng tg:ID hoặc ID số).');
}
function adjust(PDO $d, array $c, string $actor, string $ukey, int $amount, string $reason): string {
    if ($amount === 0 || abs($amount) > 1000000000000) throw new InvalidArgumentException('Số tiền điều chỉnh không hợp lệ.');
    if (!in_array($ukey, $c['allowed_users'], true)) throw new InvalidArgumentException('Người dùng không có trong allowlist.');
    q($d, 'INSERT INTO cb_adjustments(user_id,amount,reason,actor) VALUES(?,?,?,?)', [$ukey, $amount, mb_substr($reason, 0, 300), mb_substr($actor, 0, 64)]);
    audit($d, $actor, 'wallet.adjust', $ukey, ($amount > 0 ? '+' : '').$amount.' '.$reason);
    notify($d, $ukey, $amount > 0 ? 'money_in' : 'money_out', ($amount > 0 ? 'Cộng ví: +' : 'Trừ ví: -').fmtVnd(abs($amount)), $reason !== '' ? 'Lý do: '.$reason : 'Điều chỉnh ví', $amount);
    return ($amount > 0 ? "🎁 Bạn được cộng thêm " : "🔻 Ví của bạn bị trừ ").fmtVnd(abs($amount)).($reason !== '' ? "\n📝 Lý do: ".$reason : '')."\n🧪 Dữ liệu test.";
}
// ---- Telegram inline keyboards (buttons). Zalo ignores markup (text only). ----
function tgKb(array $rows): string { return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE); }
function mainMenuKb(bool $isAdmin): string {
    $rows = [
        [['text' => '💰 Số dư', 'callback_data' => 'm:balance']],
        [['text' => '🔗 Link của tôi', 'callback_data' => 'm:links']],
        [['text' => '🧾 Đơn của tôi', 'callback_data' => 'm:orders']],
        [['text' => '🏦 Nhận tiền', 'callback_data' => 'm:bank']],
        [['text' => '📨 Rút tiền', 'callback_data' => 'm:withdraw']],
        [['text' => '🛡️ Chính sách bảo hành', 'callback_data' => 'm:policy']],
        [['text' => '📩 Hỗ trợ', 'callback_data' => 'm:support']],
    ];
    if ($isAdmin) {
        $rows[] = [['text' => '📥 Duyệt tiền', 'callback_data' => 'w:req']];
        $rows[] = [['text' => '🎁 Nộp tiền', 'callback_data' => 'a:credit'], ['text' => '🔻 Trừ tiền', 'callback_data' => 'a:debit']];
        $rows[] = [['text' => '🛡️ Sửa chính sách', 'callback_data' => 'a:setpolicy']];
        $rows[] = [['text' => '👥 Khách hàng', 'callback_data' => 'a:customers'], ['text' => '📋 Nhật ký', 'callback_data' => 'a:audit']];
    }
    return tgKb($rows);
}
function menuText(): string { return "🎛️ Chọn thao tác bên dưới nhé!\nCác lệnh /... vẫn dùng được, /menu mở lại bảng này."; }
function adminReqKb(string $wid): string {
    return tgKb([[['text' => '👍 Duyệt', 'callback_data' => 'w:approve:'.$wid], ['text' => '👎 Từ chối', 'callback_data' => 'w:reject:'.$wid]]]);
}
function encBank(array $c, string $plain): string {
    $plain = mb_substr(trim($plain), 0, 300);
    if ($plain === '') throw new InvalidArgumentException('Thông tin trống.');
    $ct = openssl_encrypt($plain, 'aes-256-gcm', hex2bin($c['bank_key']), OPENSSL_RAW_DATA, $iv = random_bytes(12), $tag);
    if ($ct === false) throw new RuntimeException('Encrypt failed.');
    return 'gcm1.'.base64_encode($iv).'.'.base64_encode($tag).'.'.base64_encode($ct);
}
function decBank(array $c, string $stored): array {
    // Returns [plaintext, isLegacy]. Legacy (v0.1 plaintext) passes through
    // so old rows keep working; user re-sends /bank once to encrypt.
    if (!str_starts_with($stored, 'gcm1.')) return [$stored, true];
    $p = explode('.', $stored);
    if (count($p) !== 4) throw new RuntimeException('Bank record unreadable.');
    $iv = base64_decode($p[1], true); $tag = base64_decode($p[2], true); $ct = base64_decode($p[3], true);
    if ($iv === false || $tag === false || $ct === false) throw new RuntimeException('Bank record unreadable.');
    $pt = openssl_decrypt($ct, 'aes-256-gcm', hex2bin($c['bank_key']), OPENSSL_RAW_DATA, $iv, $tag);
    if ($pt === false) throw new RuntimeException('Bank record unreadable (wrong bank_key?).');
    return [$pt, false];
}
function maskBank(string $plain): string {
    return mb_strlen($plain) <= 8 ? '****' : mb_substr($plain, 0, 4).'***'.mb_substr($plain, -4);
}
// Generic encrypted blob (e.g. affiliate session cookie). Not truncated like bank.
function encBlob(array $c, string $plain, int $max = 12000): string {
    $plain = trim($plain);
    if ($plain === '' || strlen($plain) > $max) throw new InvalidArgumentException('Dữ liệu trống hoặc quá dài.');
    $ct = openssl_encrypt($plain, 'aes-256-gcm', hex2bin($c['bank_key']), OPENSSL_RAW_DATA, $iv = random_bytes(12), $tag);
    if ($ct === false) throw new RuntimeException('Encrypt failed.');
    return 'gcmb1.'.base64_encode($iv).'.'.base64_encode($tag).'.'.base64_encode($ct);
}
function decBlob(array $c, string $stored): string {
    if (!str_starts_with($stored, 'gcmb1.')) throw new RuntimeException('Blob unreadable.');
    $p = explode('.', $stored);
    if (count($p) !== 4) throw new RuntimeException('Blob unreadable.');
    $iv = base64_decode($p[1], true); $tag = base64_decode($p[2], true); $ct = base64_decode($p[3], true);
    if ($iv === false || $tag === false || $ct === false) throw new RuntimeException('Blob unreadable.');
    $pt = openssl_decrypt($ct, 'aes-256-gcm', hex2bin($c['bank_key']), OPENSSL_RAW_DATA, $iv, $tag);
    if ($pt === false) throw new RuntimeException('Blob unreadable.');
    return $pt;
}
// Diagnostic: build an an_redir link with a test sub_id and read where Shopee sends it.
// Confirms our link generation carries the affiliate id and maps sub_id -> utm_content.
function probeRedir(array $c, string $origin, string $subId): array {
    $url = 'https://s.shopee.vn/an_redir?'.http_build_query(['origin_link' => $origin, 'affiliate_id' => $c['affiliate_id'], 'sub_id' => $subId], '', '&', PHP_QUERY_RFC3986);
    $loc = '';
    $ch = curl_init($url);
    // GET (not HEAD): Shopee only attaches the affiliate params on the GET redirect.
    curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$loc) { if (stripos($line, 'location:') === 0) $loc = trim(substr($line, 9)); return strlen($line); }]);
    $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($ok === false) return ['error' => 'Không kết nối được Shopee (hosting có thể chặn outbound).'];
    parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
    return ['url' => $url, 'status' => $status, 'redirect' => $loc, 'utm_content' => (string)($q['utm_content'] ?? ''), 'mmp_pid' => (string)($q['mmp_pid'] ?? ''), 'match' => ((string)($q['utm_content'] ?? '') === $subId)];
}
// Read the affiliate conversion report using the admin's stored session cookie.
// Endpoint captured from https://affiliate.shopee.vn/report/conversion_report.
// NOTE: automating the affiliate dashboard may violate Shopee ToS; the owner accepts that.
function fetchAffiliateReport(PDO $d, array $c, int $from, int $to, int $page = 1, int $size = 20): array {
    $blob = setting($d, 'affiliate_cookie', '');
    if ($blob === '') throw new RuntimeException('Chưa có cookie nguồn. Dán cookie ở mục "Nguồn chuyển đổi".');
    $cookie = decBlob($c, $blob);
    if (strlen($cookie) > 12000) throw new RuntimeException('Cookie nguồn quá dài (tối đa 12.000 byte).');
    $qs = http_build_query(['page_size' => $size, 'page_num' => $page, 'purchase_time_s' => $from, 'purchase_time_e' => $to, 'version' => 1]);
    $ch = curl_init('https://affiliate.shopee.vn/api/v3/report/list?'.$qs);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept: application/json, text/plain, */*', 'Affiliate-Program-Type: 1', 'Dnt: 1', 'Referer: https://affiliate.shopee.vn/report/conversion_report'],
        CURLOPT_COOKIE => $cookie,
    ]);
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($raw === false) throw new RuntimeException('Không kết nối được affiliate.shopee.vn (hosting chặn outbound?).');
    $j = json_decode((string)$raw, true);
    if ($status !== 200 || !is_array($j) || ($j['code'] ?? -1) !== 0) throw new RuntimeException('Báo cáo trả lỗi (HTTP '.$status.') — cookie có thể đã hết hạn.');
    return $j;
}
// Best-effort mapping from a report row to our conversion contract (preview only).
function guessConversionRow(array $row): array {
    $pick = function (array $keys) use ($row) { foreach ($keys as $k) if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) return $row[$k]; return null; };
    return [
        'tracking_token' => $pick(['sub_id1', 'subId1', 'sub_id', 'subId', 'subid', 'utm_content']),
        'conversion_id' => $pick(['checkout_id', 'order_id', 'orderId', 'conversion_id', 'id']),
        'commission' => $pick(['commission', 'total_commission', 'commission_amount', 'actual_commission', 'estimated_commission', 'shopee_commission']),
        'raw_status' => $pick(['status', 'order_status', 'checkout_status', 'state']),
    ];
}
function reportToken(string $raw): string {
    $raw = trim($raw);
    return preg_match('/^([a-f0-9]{20})(?:-*)$/i', $raw, $m) ? strtolower($m[1]) : '';
}
function reportConversionId(mixed $raw): string {
    return (is_string($raw) || is_int($raw)) ? trim((string)$raw) : '';
}
// Map a Shopee report status string to our states. Returns null if unsure (skip).
function sourceMapState(string $raw): ?string {
    $s = mb_strtolower(trim($raw));
    if ($s === '') return null;
    if (str_contains($s, 'cancel') || str_contains($s, 'hủy') || str_contains($s, 'huỷ') || str_contains($s, 'invalid') || str_contains($s, 'reject')) return 'rejected';
    if (str_contains($s, 'pend') || str_contains($s, 'chờ') || str_contains($s, 'process') || str_contains($s, 'wait')) return 'pending';
    if (str_contains($s, 'complet') || str_contains($s, 'hoàn tất') || str_contains($s, 'settle') || str_contains($s, 'approv') || str_contains($s, 'duyệt')) return 'settled';
    return null;
}
// Pull the conversion report and (dry-run or real) map rows into cb_conversions.
// Network fetch happens OUTSIDE atomic(); money inserts go through conversion()
// inside atomic(). Unknown rows are skipped, never guessed.
function syncFromReport(PDO $d, array $c, int $from, int $to, bool $dry = true, ?callable $reportFetch = null, ?callable $convertFn = null): array {
    $all = [];
    for ($page = 1; $page <= 5; $page++) {
        $rep = $reportFetch !== null ? $reportFetch($page, 50) : fetchAffiliateReport($d, $c, $from, $to, $page, 50);
        $list = $rep['data']['list'] ?? null;
        if (!is_array($list) || !$list) break;
        foreach ($list as $row) if (is_array($row)) $all[] = $row;
        if (count($list) < 50) break;
    }
    $sum = ['seen' => 0, 'matched' => 0, 'imported' => 0, 'skipped' => 0, 'rows' => []];
    $do = function () use ($d, $c, $all, $dry, $convertFn, &$sum) {
        foreach ($all as $row) {
            $sum['seen']++;
            $g = guessConversionRow($row);
            $token = is_string($g['tracking_token'] ?? null) ? reportToken($g['tracking_token']) : '';
            $cid = reportConversionId($g['conversion_id'] ?? null);
            $state = sourceMapState(is_scalar($g['raw_status'] ?? null) ? (string)$g['raw_status'] : '');
            $linkOk = $token !== '' && (bool)q($d, 'SELECT token FROM cb_links WHERE token=?', [$token])->fetch();
            $amount = is_numeric($g['commission'] ?? null) ? (int)round((float)$g['commission']) : null;
            $line = ['token' => $token, 'cid' => $cid, 'state' => $state, 'amount' => $amount, 'link' => $linkOk, 'verdict' => ''];
            if ($token === '' || $cid === '' || $state === null || !$linkOk) { $sum['skipped']++; $line['verdict'] = 'bỏ (thiếu mã/đơn/trạng thái hoặc token lạ)'; }
            elseif ($amount === null || $amount < 0) { $sum['skipped']++; $line['verdict'] = 'bỏ (tiền không hợp lệ)'; }
            else {
                $sum['matched']++;
                if ($dry) { $line['verdict'] = 'sẽ nhập: ' . $state . ' · ' . $amount . 'đ'; }
                else {
                    try {
                        ($convertFn ?? 'conversion')($d, $c, ['event_id' => 'aff:'.$cid.':'.$state, 'conversion_id' => $cid, 'tracking_token' => $token, 'state' => $state, 'commission_vnd' => $amount]);
                        $sum['imported']++; $line['verdict'] = 'đã nhập';
                    } catch (InvalidArgumentException $e) { $line['verdict'] = 'lỗi nghiệp vụ: ' . $e->getMessage(); }
                }
            }
            $sum['rows'][] = $line;
        }
    };
    if ($dry) $do(); else atomic($d, $do);
    return $sum;
}
function safeUrl(string $url): array {
    $p = parse_url($url); $hosts = ['shopee.vn', 'www.shopee.vn', 's.shopee.vn', 'shope.ee', 'vn.shp.ee'];
    if (!$p || ($p['scheme'] ?? '') !== 'https' || !in_array(strtolower($p['host'] ?? ''), $hosts, true) || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && $p['port'] !== 443)) throw new InvalidArgumentException('Chỉ nhận HTTPS tại domain Shopee được cho phép.');
    return $p;
}
function canonical(string $url): string {
    $p = safeUrl($url); parse_str($p['query'] ?? '', $params);
    if (($p['path'] ?? '') === '/an_redir' && isset($params['origin_link'])) {
        // No recursive tracking nesting; inspect the underlying landing directly.
        $p = safeUrl((string)$params['origin_link']);
    }
    $path = $p['path'] ?? '';
    if (preg_match('~/(?:product|opaanlp)/(\d+)/(\d+)(?:/|$)~', $path, $m) || preg_match('~-i\.(\d+)\.(\d+)$~', $path, $m)) return 'https://shopee.vn/product/'.$m[1].'/'.$m[2];
    throw new InvalidArgumentException('Không tìm được link sản phẩm đầy đủ.');
}
function productFromHtml(string $html): ?string {
    // s.shopee.vn serves browsers a 200 page whose JS holds the target, e.g.
    // CONFIG={httpUrl:"https:\/\/shopee.vn\/opaanlp\/<shop>\/<item>?..."}.
    $s = str_replace('\\/', '/', $html);
    if (preg_match('~https://shopee\.vn/(?:product|opaanlp)/(\d+)/(\d+)~', $s, $m)) return 'https://shopee.vn/product/'.$m[1].'/'.$m[2];
    if (preg_match('~https://shopee\.vn/[^"\'\s?]*-i\.(\d+)\.(\d+)~', $s, $m)) return 'https://shopee.vn/product/'.$m[1].'/'.$m[2];
    return null;
}
function resolveShort(string $url, ?callable $fetch = null): string {
    // Short links (s.shopee.vn/XXXX, shope.ee, vn.shp.ee) answer a plain client with
    // a 301 Location, and a browser User-Agent with a 200 JS page. Handle both.
    // Each hop is re-validated by safeUrl() so we never leave the Shopee allowlist.
    for ($i = 0; $i < 4; $i++) {
        safeUrl($url); $location = '';
        if ($fetch !== null) {
            [$status, $location, $body] = $fetch($url);
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'curl/8.5.0', CURLOPT_RANGE => '0-65535', CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$location) { if (stripos($line, 'location:') === 0) $location = trim(substr($line, 9)); return strlen($line); }]);
            $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        }
        if ($body === false) throw new InvalidArgumentException('Không resolve được link ngắn.');
        if ($status >= 300 && $status < 400 && $location) {
            if (str_starts_with($location, '//')) $location = 'https:'.$location;
            elseif (str_starts_with($location, '/')) $location = 'https://'.safeUrl($url)['host'].$location;
            safeUrl($location);
            try { return canonical($location); } catch (InvalidArgumentException $e) { $url = $location; continue; }
        }
        try { return canonical($url); } catch (InvalidArgumentException $e) {}
        $p = is_string($body) ? productFromHtml(substr($body, 0, 65536)) : null;
        if ($p !== null) return $p;
        throw new InvalidArgumentException('Không tìm được sản phẩm trong link rút gọn.');
    }
    throw new InvalidArgumentException('Link chuyển hướng quá nhiều hoặc bị chặn.');
}
function extractUrls(array $m): array {
    preg_match_all('~https://[^\s<>]+~u', ($m['text'] ?? '').' '.($m['caption'] ?? ''), $matches); $urls = $matches[0];
    foreach (['entities', 'caption_entities'] as $key) foreach ($m[$key] ?? [] as $e) if (($e['type'] ?? '') === 'text_link') $urls[] = $e['url'];
    return array_slice(array_unique(array_map(fn($u) => rtrim($u, ".,;!?)\"'"), $urls)), 0, 5);
}
// Official signed link via the affiliate dashboard's Custom Link tool (GraphQL
// batchCustomLink), using the admin's stored cookie. The token goes in subId1 and
// comes back in the report as utm_content "token----". Network call: never call
// this inside atomic().
function customLink(PDO $d, array $c, string $origin, string $subId, ?callable $httpCall = null): string {
    $blob = setting($d, 'affiliate_cookie', '');
    if ($blob === '') throw new RuntimeException('Chưa có cookie nguồn để tạo link.');
    $cookie = decBlob($c, $blob);
    if (strlen($cookie) > 12000) throw new RuntimeException('Cookie nguồn quá dài (tối đa 12.000 byte). Hãy chỉ dán các cookie phiên cần thiết.');
    $body = json_encode(['operationName' => 'batchGetCustomLink',
        'query' => 'query batchGetCustomLink($linkParams: [CustomLinkParam!], $sourceCaller: SourceCaller){ batchCustomLink(linkParams: $linkParams, sourceCaller: $sourceCaller){ shortLink longLink failCode } }',
        'variables' => ['linkParams' => [['originalLink' => $origin, 'advancedLinkParams' => ['subId1' => $subId]]], 'sourceCaller' => 'CUSTOM_LINK_CALLER']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $csrf = preg_match('~(?:^|;\s*)csrftoken=([^;]+)~', $cookie, $m) ? $m[1] : '';
    $requestUrl = 'https://affiliate.shopee.vn/api/v3/gql?q=batchCustomLink';
    $headers = array_values(array_filter(['Content-Type: application/json', 'Accept: application/json, text/plain, */*', 'Affiliate-Program-Type: 1', 'Origin: https://affiliate.shopee.vn', 'Referer: https://affiliate.shopee.vn/offer/custom_link', $csrf !== '' ? 'X-CSRFToken: '.$csrf : '']));
    if ($httpCall !== null) { [$status, $raw] = $httpCall($requestUrl, $body, $cookie, $headers); }
    else {
    $ch = curl_init($requestUrl);
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIE => $cookie,
    ];
    curl_setopt_array($ch, $curlOpts);
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($raw === false) throw new RuntimeException('Không kết nối được affiliate.shopee.vn.');
    }
    $j = json_decode((string)$raw, true); $row = $j['data']['batchCustomLink'][0] ?? null;
    if ($status !== 200 || !is_array($row) || (int)($row['failCode'] ?? -1) !== 0 || !is_string($row['shortLink'] ?? null) || trim($row['shortLink']) === '') {
        $snip = preg_replace('~\s+~', ' ', mb_substr(strip_tags((string)$raw), 0, 200));
        $snip = preg_replace('~(?i)(cookie|csrftoken|authorization|session|token)["\s:=]+[^,;" ]+~', '$1=[redacted]', (string)$snip);
        throw new RuntimeException('Custom Link lỗi (HTTP '.$status.', failCode '.(is_array($row) ? ($row['failCode'] ?? '?') : '?').') — cookie có thể đã hết hạn. Shopee trả: '.$snip);
    }
    $url = $row['shortLink'];
    try { safeUrl($url); } catch (InvalidArgumentException $e) { throw new RuntimeException('Shopee trả shortLink ngoài allowlist: '.mb_substr($url, 0, 180), 0, $e); }
    return $url;
}
// ---- Shopee Affiliate Open API (official; link_mode 'open_api') ----
// Auth per Shopee Affiliate Open API docs: Authorization: SHA256 Credential=<AppId>,
// Timestamp=<unix>, Signature=sha256(AppId + Timestamp + Payload + Secret).
// Re-check the endpoint/field names against the docs you receive with your AppId.
function openApiSignature(string $appId, int $ts, string $payload, string $secret): string {
    return hash('sha256', $appId.$ts.$payload.$secret);
}
// cURL options for Open API calls.
function openApiCurlOpts(array $c, string $payload, array $headers): array {
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
    return $o;
}
function openApiCall(array $c, string $query, ?callable $http = null, ?int $ts = null): array {
    $payload = json_encode(['query' => $query], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ts ??= time();
    $headers = ['Content-Type: application/json', 'Authorization: SHA256 Credential='.$c['open_api_app_id'].', Timestamp='.$ts.', Signature='.openApiSignature((string)$c['open_api_app_id'], $ts, $payload, (string)$c['open_api_secret'])];
    if ($http !== null) { [$status, $raw] = $http($c['open_api_url'], $payload, $headers); }
    else {
        $ch = curl_init($c['open_api_url']);
        curl_setopt_array($ch, openApiCurlOpts($c, $payload, $headers));
        $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($raw === false) throw new RuntimeException('Không kết nối được Shopee Open API.');
    }
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) throw new RuntimeException('Open API trả dữ liệu không đọc được (HTTP '.$status.').');
    if (!empty($j['errors']) && is_array($j['errors'])) {
        $e = $j['errors'][0];
        $code = is_array($e) ? ($e['extensions']['code'] ?? '?') : '?';
        throw new RuntimeException('Open API lỗi (HTTP '.$status.', code '.$code.'): '.mb_substr(is_array($e) ? (string)($e['message'] ?? '') : '', 0, 200));
    }
    if ($status !== 200 || !is_array($j['data'] ?? null)) throw new RuntimeException('Open API lỗi HTTP '.$status.'.');
    return $j['data'];
}
function openApiLink(array $c, string $origin, string $subId, ?callable $http = null, ?int $ts = null): string {
    $q = 'mutation{generateShortLink(input:{originUrl:'.json_encode($origin, JSON_UNESCAPED_SLASHES).',subIds:['.json_encode($subId).']}){shortLink}}';
    $data = openApiCall($c, $q, $http, $ts);
    $url = is_string($data['generateShortLink']['shortLink'] ?? null) ? trim($data['generateShortLink']['shortLink']) : '';
    if ($url === '') throw new RuntimeException('Open API không trả shortLink.');
    try { safeUrl($url); } catch (InvalidArgumentException $e) { throw new RuntimeException('Open API trả shortLink ngoài allowlist: '.mb_substr($url, 0, 180), 0, $e); }
    return $url;
}
// ---- Link relay (Chrome extension in the admin's logged-in affiliate tab) ----
// The web/bot enqueues a job and waits briefly; the extension pulls queued jobs,
// creates the link through the real Custom Link page session and pushes the result.
// Never call relayLink inside atomic(): it sleeps while waiting.
const RELAY_CLAIM_TTL = 30;   // seconds a claimed job may stay 'working' before re-queue
const RELAY_MAX_ATTEMPTS = 3;
const RELAY_JOB_TTL = 21600;  // 6h: queued/working jobs older than this are failed (customer notified)
class RelayPending extends RuntimeException {}
// Enqueue a job and wait until $deadline. On timeout the job is NOT dropped: it is
// switched to async delivery (relayPush later creates the link and notifies the
// customer) and RelayPending is thrown. The async switch and relayPush both lock the
// job row, so a link is created exactly once, either here (sync) or there (async).
function relayLink(PDO $d, array $c, string $origin, string $token, ?callable $sleep = null, ?callable $now = null, ?float $deadline = null, ?string $ukey = null): string {
    q($d, 'INSERT INTO cb_link_jobs(token,origin,user_id,share_bps) VALUES(?,?,?,?)', [$token, $origin, $ukey, $c['customer_share_bps']]);
    $id = (int)$d->lastInsertId();
    $now ??= fn() => microtime(true);
    $sleep ??= function () { usleep(400000); };
    $deadline ??= $now() + (int)$c['relay_wait_seconds'];
    while (true) {
        $j = q($d, 'SELECT state,short_link,error FROM cb_link_jobs WHERE id=?', [$id])->fetch();
        if ($j && $j['state'] === 'done') { $u = (string)$j['short_link']; safeUrl($u); return $u; }
        if ($j && $j['state'] === 'failed') throw new RuntimeException('Relay lỗi: '.(string)$j['error']);
        if ($now() >= $deadline) break;
        $sleep($id);
    }
    $switched = atomic($d, function () use ($d, $id) {
        $st = (string)q($d, 'SELECT state FROM cb_link_jobs WHERE id=? FOR UPDATE', [$id])->fetchColumn();
        if ($st !== 'queued' && $st !== 'working') return false;
        q($d, 'UPDATE cb_link_jobs SET async=1 WHERE id=?', [$id]);
        return true;
    });
    if (!$switched) { // finished in the last instant: use the result synchronously
        $j = q($d, 'SELECT state,short_link,error FROM cb_link_jobs WHERE id=?', [$id])->fetch();
        if ($j && $j['state'] === 'done') { $u = (string)$j['short_link']; safeUrl($u); return $u; }
        throw new RuntimeException('Relay lỗi: '.(string)($j['error'] ?? 'không rõ'));
    }
    throw new RelayPending('Relay chưa trả lời trong '.(int)$c['relay_wait_seconds'].' giây; yêu cầu được giữ trong hàng đợi (job '.$id.').');
}
// Queue a job for async delivery without waiting (used once the relay already timed out).
function relayEnqueueAsync(PDO $d, array $c, string $ukey, string $origin): void {
    q($d, 'INSERT INTO cb_link_jobs(token,origin,user_id,share_bps,async) VALUES(?,?,?,?,1)', [bin2hex(random_bytes(10)), $origin, $ukey, $c['customer_share_bps']]);
}
function relayHasPending(PDO $d, string $ukey, string $origin): bool {
    return (bool)q($d, "SELECT id FROM cb_link_jobs WHERE user_id=? AND origin=? AND async=1 AND state IN ('queued','working') LIMIT 1", [$ukey, $origin])->fetch();
}
// Async delivery of a finished/failed job to its customer. Caller holds the row lock.
function relayDeliver(PDO $d, array $j, bool $ok, string $url, string $error): void {
    $ukey = (string)($j['user_id'] ?? '');
    if ($ukey === '' || (int)$j['async'] !== 1) return;
    $origin = (string)$j['origin'];
    if ($ok) {
        $have = q($d, 'SELECT url FROM cb_links WHERE user_id=? AND origin=?', [$ukey, $origin])->fetch();
        if ($have) $url = (string)$have['url'];
        else q($d, 'INSERT INTO cb_links(token,user_id,origin,url,share_bps) VALUES(?,?,?,?,?)', [$j['token'], $ukey, $origin, $url, (int)($j['share_bps'] ?? 0)]);
        notify($d, $ukey, 'link', 'Link hoàn tiền đã sẵn sàng', $origin.' → '.$url);
        $msg = "🔗 Link hoàn tiền bạn yêu cầu đã sẵn sàng:\n".$url;
    } else {
        notify($d, $ukey, 'link', 'Chưa tạo được link', $origin.' — vui lòng dán lại link sau ít phút.');
        $msg = "⚠️ Chưa tạo được link hoàn tiền cho:\n".$origin."\nBạn dán lại link sau ít phút nhé.";
    }
    if (!isGuest($ukey)) { try { [$ch, $tgt] = routeFor($ukey); queue($d, 'relay:'.$j['id'], $ch, (string)$tgt, $msg); } catch (InvalidArgumentException $e) {} }
}
// Extension side: claim up to $limit jobs (queued, or 'working' whose claim expired).
function relayPull(PDO $d, int $limit = 3): array {
    $limit = max(1, min(5, $limit));
    setSetting($d, 'relay_seen_at', date('Y-m-d H:i:s'));
    return atomic($d, function () use ($d, $limit) {
        $dead = q($d, "SELECT * FROM cb_link_jobs WHERE (state IN ('queued','working') AND created_at < (NOW() - INTERVAL ".RELAY_JOB_TTL." SECOND))
            OR (state='working' AND attempts >= ? AND claimed_at < (NOW() - INTERVAL ".RELAY_CLAIM_TTL." SECOND)) FOR UPDATE", [RELAY_MAX_ATTEMPTS])->fetchAll();
        foreach ($dead as $j) {
            $why = (int)$j['attempts'] >= RELAY_MAX_ATTEMPTS ? 'quá số lần thử' : 'quá hạn';
            q($d, "UPDATE cb_link_jobs SET state='failed', error=?, done_at=NOW() WHERE id=?", [$why, $j['id']]);
            relayDeliver($d, $j, false, '', $why);
        }
        $rows = q($d, "SELECT id,token,origin FROM cb_link_jobs WHERE state='queued' OR (state='working' AND claimed_at < (NOW() - INTERVAL ".RELAY_CLAIM_TTL." SECOND)) ORDER BY id LIMIT ".$limit." FOR UPDATE")->fetchAll();
        foreach ($rows as $r) q($d, "UPDATE cb_link_jobs SET state='working', attempts=attempts+1, claimed_at=NOW() WHERE id=?", [$r['id']]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'sub_id' => (string)$r['token'], 'origin_url' => (string)$r['origin']], $rows);
    });
}
// Extension side: only a job currently 'working' can be completed; the link must be a Shopee URL.
function relayPush(PDO $d, int $id, bool $ok, string $shortLink, string $error): string {
    return atomic($d, function () use ($d, $id, $ok, $shortLink, $error) {
        $j = q($d, 'SELECT * FROM cb_link_jobs WHERE id=? FOR UPDATE', [$id])->fetch();
        if (!$j) return 'unknown';
        if ($j['state'] !== 'working') return 'ignored';
        if ($ok) {
            try { safeUrl($shortLink); } catch (InvalidArgumentException $e) { $ok = false; $error = 'link ngoài allowlist: '.mb_substr($shortLink, 0, 120); }
        }
        if ($ok) q($d, "UPDATE cb_link_jobs SET state='done', short_link=?, error=NULL, done_at=NOW() WHERE id=?", [$shortLink, $id]);
        else q($d, "UPDATE cb_link_jobs SET state='failed', error=?, done_at=NOW() WHERE id=?", [mb_substr($error !== '' ? $error : 'lỗi không rõ', 0, 300), $id]);
        relayDeliver($d, $j, $ok, $shortLink, $error);
        return $ok ? 'done' : 'failed';
    });
}
// Cron (worker.php): tell admins on Telegram/Zalo when the relay has been silent or paused
// for 5 minutes (repeat hourly), and once more when it is back. Returns 'down', 'up' or ''.
function relayWatchdog(PDO $d, array $c, ?int $now = null): string {
    if (($c['link_mode'] ?? '') !== 'relay') return '';
    $now ??= time();
    $seen = setting($d, 'relay_seen_at', '');
    $paused = str_starts_with(setting($d, 'relay_status', ''), 'paused');
    $silent = $seen === '' || $now - (int)strtotime($seen) > 300;
    $alertAt = setting($d, 'relay_alert_at', '');
    $waiting = (int)q($d, "SELECT COUNT(*) FROM cb_link_jobs WHERE state IN ('queued','working')")->fetchColumn();
    $send = function (string $key, string $msg) use ($d, $c) {
        foreach ($c['admins'] as $a) { try { [$ch, $tgt] = routeFor($a); queue($d, $key.':'.$a, $ch, (string)$tgt, $msg); } catch (InvalidArgumentException $e) {} }
    };
    if ($silent || $paused) {
        if ($alertAt !== '' && $now - (int)strtotime($alertAt) < 3600) return '';
        $why = $paused ? 'đang TẠM DỪNG ('.mb_substr(setting($d, 'relay_status', ''), 0, 200).')' : 'im lặng từ '.($seen !== '' ? $seen : 'chưa từng liên lạc');
        $send('relay:down:'.date('YmdHi', $now), "⚠️ Relay tạo link ".$why.".\n📥 Yêu cầu đang chờ: ".$waiting."\nMở Chrome có extension + tab affiliate.shopee.vn đang đăng nhập.");
        setSetting($d, 'relay_alert_at', date('Y-m-d H:i:s', $now));
        return 'down';
    }
    if ($alertAt !== '') {
        $send('relay:up:'.date('YmdHi', $now), "✅ Relay tạo link đã hoạt động lại. Yêu cầu đang chờ: ".$waiting.".");
        setSetting($d, 'relay_alert_at', '');
        return 'up';
    }
    return '';
}
// Outside atomic(): give each new (user, origin) a token + signed link before the
// DB transaction. Existing links are reused; failures become per-link errors.
function presignLinks(PDO $d, array $c, string $ukey, array $prepared, ?callable $httpCall = null): array {
    $mode = $c['link_mode'] ?? 'custom_link';
    if (!in_array($mode, ['custom_link', 'relay', 'open_api'], true)) return $prepared;
    $down = false; // relay already timed out in this request: queue the rest without waiting
    // One deadline for the whole request so N links never exceed ~15s in total (PHP-FPM
    // request_terminate_timeout is often 30s).
    $relayDeadline = microtime(true) + min(15, (int)($c['relay_wait_seconds'] ?? 12));
    foreach ($prepared as &$p) {
        if (isset($p['error']) || q($d, 'SELECT token FROM cb_links WHERE user_id=? AND origin=?', [$ukey, $p['origin']])->fetch()) continue;
        if ($mode === 'relay' && relayHasPending($d, $ukey, $p['origin'])) { $p = ['pending' => true, 'origin' => $p['origin']]; continue; }
        if ($mode === 'relay' && $down) { relayEnqueueAsync($d, $c, $ukey, $p['origin']); $p = ['pending' => true, 'origin' => $p['origin']]; continue; }
        $token = bin2hex(random_bytes(10));
        try {
            $p['token'] = $token;
            $p['url'] = match ($mode) {
                'relay' => relayLink($d, $c, $p['origin'], $token, null, null, $relayDeadline, $ukey),
                'open_api' => openApiLink($c, $p['origin'], $token, $httpCall),
                default => customLink($d, $c, $p['origin'], $token, $httpCall),
            };
        } catch (RelayPending $e) {
            $down = true;
            $p = ['pending' => true, 'origin' => $p['origin']];
        } catch (Throwable $e) {
            error_log('presign '.$mode.': '.$e->getMessage());
            try { setSetting($d, 'custom_link_error', date('Y-m-d H:i:s').' — '.mb_substr($e->getMessage(), 0, 400)); } catch (Throwable $e2) {}
            $p = ['error' => 'Tạm thời chưa tạo được link hoàn tiền, thử lại sau ít phút.', 'origin' => $p['origin']];
        }
    }
    unset($p);
    return $prepared;
}
function newLink(PDO $d, array $c, string $ukey, string $origin, array $pre = []): array {
    $r = q($d, 'SELECT token,url FROM cb_links WHERE user_id=? AND origin=?', [$ukey, $origin])->fetch(); if ($r) return $r;
    if (isset($pre['token'], $pre['url'])) { $token = $pre['token']; $url = $pre['url']; }
    elseif (in_array($c['link_mode'] ?? 'custom_link', ['custom_link', 'relay', 'open_api'], true)) throw new InvalidArgumentException('Tạm thời chưa tạo được link hoàn tiền, thử lại sau ít phút.');
    else {
        // Legacy unsigned link; Shopee does not seem to attribute it (see docs/TRACKING_DIAG.md).
        $token = bin2hex(random_bytes(10));
        $url = 'https://s.shopee.vn/an_redir?'.http_build_query(['origin_link' => $origin, 'affiliate_id' => $c['affiliate_id'], 'sub_id' => $token], '', '&', PHP_QUERY_RFC3986);
    }
    q($d, 'INSERT INTO cb_links(token,user_id,origin,url,share_bps) VALUES(?,?,?,?,?)', [$token, $ukey, $origin, $url, $c['customer_share_bps']]); return ['token' => $token, 'url' => $url];
}
function balance(PDO $d, string $ukey): array {
    $total = (int)q($d, 'SELECT COALESCE(SUM(g.amount),0) FROM cb_ledger g JOIN cb_conversions c ON c.id=g.conversion_id JOIN cb_links l ON l.token=c.token WHERE l.user_id=?', [$ukey])->fetchColumn();
    $adj = (int)q($d, 'SELECT COALESCE(SUM(amount),0) FROM cb_adjustments WHERE user_id=?', [$ukey])->fetchColumn();
    $held = (int)q($d, "SELECT COALESCE(SUM(amount),0) FROM cb_withdrawals WHERE user_id=? AND state IN ('requested','approved','paid')", [$ukey])->fetchColumn();
    $pending = (int)q($d, "SELECT COALESCE(SUM(c.share),0) FROM cb_conversions c JOIN cb_links l ON l.token=c.token WHERE l.user_id=? AND c.state IN ('pending','validated')", [$ukey])->fetchColumn();
    return ['available' => $total - $held + $adj, 'pending' => $pending];
}
function conversion(PDO $d, array $c, array $e): bool {
    foreach (['event_id', 'conversion_id', 'tracking_token'] as $k) if (!isset($e[$k]) || !is_string($e[$k]) || !preg_match('/^[A-Za-z0-9_:.\-]{1,150}$/', $e[$k])) throw new InvalidArgumentException('ID conversion không hợp lệ.');
    $amount = $e['commission_vnd'] ?? null; $state = $e['state'] ?? '';
    if (!is_int($amount) || $amount < 0 || $amount > 1000000000000 || !in_array($state, ['pending', 'validated', 'settled', 'rejected'], true)) throw new InvalidArgumentException('Tiền hoặc trạng thái không hợp lệ.');
    ksort($e); $digest = hash('sha256', json_encode($e, JSON_THROW_ON_ERROR));
    $prior = q($d, 'SELECT digest FROM cb_events WHERE id=?', [$e['event_id']])->fetchColumn();
    if ($prior) { if (!hash_equals($prior, $digest)) throw new InvalidArgumentException('Event ID trùng nhưng nội dung khác.'); return false; }
    $l = q($d, 'SELECT token,user_id,share_bps FROM cb_links WHERE token=?', [$e['tracking_token']])->fetch(); if (!$l) throw new InvalidArgumentException('Không xác định được khách từ tracking.');
    $old = q($d, 'SELECT token FROM cb_conversions WHERE id=?', [$e['conversion_id']])->fetch();
    if ($old && $old['token'] !== $l['token']) throw new InvalidArgumentException('Không được đổi chủ conversion.');
    $share = $state === 'rejected' ? 0 : intdiv($amount * (int)$l['share_bps'], 10000); $target = $state === 'settled' ? $share : 0;
    $previous = (int)q($d, 'SELECT COALESCE(SUM(amount),0) FROM cb_ledger WHERE conversion_id=?', [$e['conversion_id']])->fetchColumn();
    q($d, 'INSERT INTO cb_conversions(id,token,state,commission,share) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE state=VALUES(state),commission=VALUES(commission),share=VALUES(share)', [$e['conversion_id'], $l['token'], $state, $amount, $share]);
    q($d, 'INSERT INTO cb_events(id,digest) VALUES(?,?)', [$e['event_id'], $digest]);
    if ($target !== $previous) q($d, 'INSERT INTO cb_ledger(conversion_id,amount,event_id) VALUES(?,?,?)', [$e['conversion_id'], $target - $previous, $e['event_id']]);
    if (!isGuest((string)$l['user_id'])) { [$ch, $tgt] = routeFor($l['user_id']);
    queue($d, 'event:'.$e['event_id'], $ch, (string)$tgt, "🧪🧾 Đơn ".$e['conversion_id']." ".stateLabel($state)."\n💵 Phần bạn: ".fmtVnd($share)."\n(🧪 Dữ liệu test, chưa lấy từ Shopee.)"); }
    if ($state === 'settled' && $target > $previous) notify($d, $l['user_id'], 'money_in', 'Tiền về: +'.fmtVnd($share), 'Đơn '.$e['conversion_id'].' đã hoàn tất.', $share);
    elseif (in_array($state, ['pending', 'validated'], true)) notify($d, $l['user_id'], 'order', 'Đơn mới: '.$e['conversion_id'], 'Trạng thái: '.stateLabel($state), $amount);
    elseif ($state === 'rejected' && $previous > 0) notify($d, $l['user_id'], 'money_out', 'Đảo bút toán: -'.fmtVnd($previous), 'Đơn '.$e['conversion_id'].' đã bị hủy.', -$previous);
    if ($target !== $previous) {
        $note = $state === 'settled'
            ? "🧪✅ Đơn ".$e['conversion_id']." hoàn tất\n💰 Hoa hồng: ".fmtVnd($amount)."\n👤 Phần khách: ".fmtVnd($share)."\n🏷️ token ".$l['token']
            : ($state === 'rejected' && $previous > 0 ? "🧪↩️ Đơn ".$e['conversion_id']." bị hủy — đã đảo bút toán ".fmtVnd($previous)."." : '');
        if ($note !== '') foreach ($c['admins'] as $a) { [$ach, $atgt] = routeFor($a); queue($d, 'event:'.$e['event_id'].':admin:'.$a, $ach, (string)$atgt, $note); }
    }
    return true;
}
function checkWithdrawSlot(PDO $d, string $ukey, array &$outcome): void {
    // Anti-spam/exploit: at most one 'requested' withdrawal per user.
    $has = (int)q($d, "SELECT COUNT(*) FROM cb_withdrawals WHERE user_id=? AND state='requested'", [$ukey])->fetchColumn();
    if ($has > 0) { $outcome['blocked'] = 'withdraw-active'; throw new InvalidArgumentException('⏳ Bạn đang có 1 yêu cầu rút chờ xử lý rồi, chờ admin duyệt xong đã nhé!'); }
}
function withdraw(PDO $d, array $c, string $ukey, int $amount, string $wid): void {
    $bank = q($d, 'SELECT bank FROM cb_users WHERE id=?', [$ukey])->fetchColumn();
    if (!$bank) throw new InvalidArgumentException('🏦 Bạn cần thêm thông tin nhận tiền trước: /bank ngân_hàng | số_tài_khoản | tên_chủ');
    if ($amount < $c['min_withdraw_vnd']) throw new InvalidArgumentException('⚠️ Tối thiểu '.fmtVnd((int)$c['min_withdraw_vnd']).' mỗi lần rút nhé.');
    if ($amount <= 0 || $amount > balance($d, $ukey)['available']) throw new InvalidArgumentException('⚠️ Số tiền vượt số dư khả dụng.');
    q($d, 'INSERT INTO cb_withdrawals(id,user_id,amount,bank,state,reference) VALUES(?,?,?,?,?,?)', [$wid, $ukey, $amount, $bank, 'requested', '']);
    audit($d, $ukey, 'withdraw.request', $wid, (string)$amount);
}
function notifyAdminsRequest(PDO $d, array $c, string $baseKey, string $ukey, string $wid, string $amount): void {
    foreach ($c['admins'] as $admin) {
        [$ach, $atgt] = routeFor($admin);
        queue($d, $baseKey.':admin:'.$admin, $ach, (string)$atgt, "📥 Yêu cầu rút mới!\n👤 Khách: ".$ukey."\n💵 Số tiền: ".fmtVnd((int)$amount)."\n🏷️ Mã: ".$wid."\n(/approve ".$wid.", /reject ".$wid.", hoặc /paid ".$wid." MÃ_REF để ghi trả.)", adminReqKb($wid));
    }
}
function transition(PDO $d, array $c, string $wid, string $action, string $ref, string $actor): string {
    $w = q($d, 'SELECT * FROM cb_withdrawals WHERE id=?', [$wid])->fetch(); if (!$w) throw new InvalidArgumentException('Không có yêu cầu này.');
    $map = ['approve' => ['requested', 'approved'], 'reject' => ['requested', 'rejected'], 'cancel' => ['approved', 'rejected'], 'paid' => ['approved', 'paid']];
    if (!isset($map[$action])) throw new InvalidArgumentException('Thao tác không có.');
    [$before, $after] = $map[$action]; if ($w['state'] !== $before) throw new InvalidArgumentException('Trạng thái không cho phép thao tác này.');
    if (in_array($action, ['approve', 'paid'], true) && balance($d, (string)$w['user_id'])['available'] < 0) throw new InvalidArgumentException('Số dư âm sau điều chỉnh; cần đối soát lại.');
    if ($action === 'paid' && ($ref === '' || strlen($ref) > 150)) throw new InvalidArgumentException('Cần mã tham chiếu hợp lệ.');
    q($d, 'UPDATE cb_withdrawals SET state=?,reference=? WHERE id=?', [$after, $ref, $wid]);
    audit($d, $actor, 'withdraw.'.$action, $wid, (string)$w['amount'].' '.($ref !== '' ? $ref : ''));
    [$ch, $tgt] = routeFor((string)$w['user_id']);
    queue($d, 'withdraw:'.$wid.':'.$after, $ch, (string)$tgt, "📣 Yêu cầu rút ".$wid." → ".stateLabel($after)."\n🧪 Bot chỉ ghi nhận test, không tự chuyển tiền.");
    notify($d, (string)$w['user_id'], 'withdraw', 'Yêu cầu rút: '.stateLabel($after), 'Mã '.$wid.'. Số tiền '.fmtVnd((int)$w['amount']).'.', (int)$w['amount']);
    $reply = "✅ Yêu cầu ".$wid." → ".stateLabel($after)."\n(Admin xác nhận — bot không chuyển khoản.)";
    if ($action === 'approve') {
        try { [$plain, $legacy] = decBank($c, (string)$w['bank']); $reply .= "\n🏦 Nhận tiền: ".$plain.($legacy ? "\n⚠️ Thông tin chưa mã hóa — nhắc khách gửi lại /bank." : ''); }
        catch (Throwable $e) { $reply .= "\n⚠️ Không đọc được thông tin nhận tiền."; }
    }
    return $reply;
}
function clearPending(PDO $d, string $ukey): void { q($d, 'UPDATE cb_users SET pending_action=?,pending_at=0 WHERE id=?', ['', $ukey]); }
function setPending(PDO $d, string $ukey, string $action): void { q($d, 'UPDATE cb_users SET pending_action=?,pending_at=? WHERE id=?', [$action, time(), $ukey]); }
function consumePending(PDO $d, array $c, array $ctx, string $ukey, callable $say, array &$outcome): bool {
    // Button-driven data entry: the message after pressing a data-entry button.
    $row = q($d, 'SELECT pending_action,pending_at FROM cb_users WHERE id=?', [$ukey])->fetch();
    $act = (string)($row['pending_action'] ?? '');
    if ($act === '') return false;
    if (time() - (int)($row['pending_at'] ?? 0) > 600) { clearPending($d, $ukey); $say('', "⌛ Thao tác chờ đã hết hạn (10 phút). Bấm lại nút để làm mới nhé."); return true; }
    $text = trim($ctx['text'] ?? '');
    if (str_contains($text, 'https://')) { clearPending($d, $ukey); return false; } // Link wins over pending input.
    if ($act === 'bank') {
        if ($text === '') { $say('', "✍️ Thông tin trống. Gửi lại hoặc /cancel nhé."); return true; }
        q($d, 'UPDATE cb_users SET bank=?,pending_action=?,pending_at=0 WHERE id=?', [encBank($c, mb_substr($text, 0, 300)), '', $ukey]);
        audit($d, $ukey, 'bank.update', $ukey); $say('', "🏦 Đã lưu thông tin nhận tiền (đã mã hóa)."); return true;
    }
    if ($act === 'withdraw') {
        if (!ctype_digit($text) || strlen($text) > 12) { $outcome['blocked'] = 'withdraw-invalid'; $say('', "⚠️ Số tiền không hợp lệ. Nhập số nguyên VNĐ hoặc /cancel nhé."); return true; }
        try {
            checkWithdrawSlot($d, $ukey, $outcome);
            $wid = substr(hash('sha256', $ctx['key'].':withdraw'), 0, 16); withdraw($d, $c, $ukey, (int)$text, $wid);
        } catch (InvalidArgumentException $e) { if (!isset($outcome['blocked'])) $outcome['blocked'] = 'withdraw-invalid'; if ($outcome['blocked'] === 'withdraw-active') clearPending($d, $ukey); throw $e; }
        notifyAdminsRequest($d, $c, $ctx['key'], $ukey, $wid, $text);
        clearPending($d, $ukey); $say('', "📨 Đã tạo yêu cầu rút ".$wid."\n⏳ Chờ admin duyệt nhé!"); return true;
    }
    if ($act === 'support') {
        if ($text === '') { $say('', "✍️ Nội dung trống. Gửi lại hoặc /cancel nhé."); return true; }
        foreach ($c['admins'] as $admin) { [$ach, $atgt] = routeFor($admin); queue($d, $ctx['key'].':support:'.$admin, $ach, (string)$atgt, '📩 Khách '.$ukey.': '.mb_substr($text, 0, 1000)); }
        clearPending($d, $ukey); $say('', "📩 Đã gửi tới quản trị, chờ phản hồi nhé!"); return true;
    }
    if ($act === 'credit' || $act === 'debit') {
        $p = preg_split('/\s+/', $text);
        if (count($p) < 2) { $say('', "✍️ Dùng: user số_tiền [lý do] (user dạng tg:ID hoặc ID). /cancel để hủy."); return true; }
        $target = '';
        try {
            $target = resolveUserKey($p[0]);
            if (!ctype_digit($p[1]) || strlen($p[1]) > 12 || (int)$p[1] <= 0) throw new InvalidArgumentException('Số tiền không hợp lệ.');
            $amt = (int)$p[1]; if ($act === 'debit') $amt = -$amt;
            $notice = adjust($d, $c, $ukey, $target, $amt, trim(implode(' ', array_slice($p, 2))));
            [$nch, $ntgt] = routeFor($target); queue($d, $ctx['key'].':adjust:'.$target, $nch, (string)$ntgt, $notice);
        } catch (InvalidArgumentException $e) { if (!isset($outcome['blocked'])) $outcome['blocked'] = 'adjust-invalid'; throw $e; }
        clearPending($d, $ukey); $say('', "✅ Đã ".($act === 'credit' ? 'nộp' : 'trừ')." tiền cho ".$target."."); return true;
    }
    if ($act === 'setpolicy') {
        if ($text === '') { $say('', "✍️ Nội dung trống. Gửi lại hoặc /cancel nhé."); return true; }
        setSetting($d, 'policy', mb_substr($text, 0, 4000));
        audit($d, $ukey, 'policy.update', '', mb_substr($text, 0, 100));
        clearPending($d, $ukey); $say('', "🛡️ Đã cập nhật chính sách bảo hành."); return true;
    }
    clearPending($d, $ukey); return false;
}
function handleCallback(PDO $d, array $c, array $ctx, callable $say, bool $isAdmin, array &$outcome): void {
    $ukey = $ctx['sender_key'];
    if ($ukey === null || !$ctx['private']) { $outcome['cb_answer'] = 'Bạn chưa được duyệt dùng bot.'; return; }
    q($d, 'INSERT IGNORE INTO cb_users(id) VALUES(?)', [$ukey]);
    $data = (string)($ctx['data'] ?? '');
    $kb = mainMenuKb($isAdmin);
    if (in_array($data, ['m:menu', 'm:help', 'm:start'], true)) { $say('', menuText(), $kb); return; }
    if ($data === 'm:balance') { $say('', renderBalance(balance($d, $ukey)), $kb); return; }
    if ($data === 'm:links') { $say('', renderLinks(q($d, 'SELECT token,origin,created_at FROM cb_links WHERE user_id=? ORDER BY created_at DESC LIMIT 10', [$ukey])->fetchAll()), $kb); return; }
    if ($data === 'm:orders') { $say('', renderOrders(q($d, 'SELECT c.id,c.state,c.commission,c.share FROM cb_conversions c JOIN cb_links l ON l.token=c.token WHERE l.user_id=? ORDER BY c.created_at DESC LIMIT 10', [$ukey])->fetchAll()), $kb); return; }
    if ($data === 'm:bank') { setPending($d, $ukey, 'bank'); $say('', "🏦 Gửi thông tin nhận tiền theo mẫu:\nngân_hàng | số_tài_khoản | tên_chủ\n(/cancel để hủy)", $kb); return; }
    if ($data === 'm:withdraw') { setPending($d, $ukey, 'withdraw'); $say('', "📨 Nhập số tiền VNĐ muốn rút (tối thiểu ".fmtVnd((int)$c['min_withdraw_vnd']).").\n(/cancel để hủy)", $kb); return; }
    if ($data === 'm:policy') { $say('', currentPolicy($d), $kb); return; }
    if ($data === 'm:support') { setPending($d, $ukey, 'support'); $say('', "📩 Gửi nội dung cần hỗ trợ nhé.\n(/cancel để hủy)", $kb); return; }
    if ($data === 'w:req') {
        if (!$isAdmin) { $outcome['cb_answer'] = 'Chỉ admin.'; return; }
        $rows = q($d, "SELECT id,user_id,amount,state FROM cb_withdrawals WHERE state IN ('requested','approved') ORDER BY created_at DESC LIMIT 5")->fetchAll();
        if (!$rows) { $say('', "📭 Không có yêu cầu rút nào đang chờ.", $kb); return; }
        $lines = []; $btns = [];
        foreach ($rows as $r) { $lines[] = "• ".$r['id']." — ".fmtVnd((int)$r['amount'])." (".$r['user_id'].") ".stateLabel((string)$r['state']); $btns[] = [['text' => '👍 '.substr($r['id'], 0, 8), 'callback_data' => 'w:approve:'.$r['id']], ['text' => '👎', 'callback_data' => 'w:reject:'.$r['id']]]; }
        $say('', "📥 Yêu cầu rút đang chờ:\n".implode("\n", $lines)."\n\nGhi trả: /paid <MÃ> <MÃ_REF>", tgKb($btns)); return;
    }
    if ($data === 'a:credit' || $data === 'a:debit') {
        if (!$isAdmin) { $outcome['cb_answer'] = 'Chỉ admin.'; return; }
        setPending($d, $ukey, $data === 'a:credit' ? 'credit' : 'debit');
        $say('', ($data === 'a:credit' ? "🎁 Nộp tiền (cộng ví)" : "🔻 Trừ tiền (trừ ví)").": gửi theo mẫu\nuser số_tiền [lý do]\n(user dạng tg:ID hoặc ID số). /cancel để hủy.", $kb); return;
    }
    if ($data === 'a:setpolicy') {
        if (!$isAdmin) { $outcome['cb_answer'] = 'Chỉ admin.'; return; }
        setPending($d, $ukey, 'setpolicy');
        $say('', "🛡️ Gửi nội dung chính sách bảo hành mới nhé.\n(/cancel để hủy)", $kb); return;
    }
    if ($data === 'a:customers') {
        if (!$isAdmin) { $outcome['cb_answer'] = 'Chỉ admin.'; return; }
        $say('', renderCustomers($d, q($d, 'SELECT id FROM cb_users ORDER BY first_seen DESC LIMIT 30')->fetchAll()), $kb); return;
    }
    if ($data === 'a:audit') {
        if (!$isAdmin) { $outcome['cb_answer'] = 'Chỉ admin.'; return; }
        $say('', renderAudit(q($d, 'SELECT at,actor,action,ref FROM cb_audit ORDER BY id DESC LIMIT 15')->fetchAll()), $kb); return;
    }
    foreach (['w:approve:' => 'approve', 'w:reject:' => 'reject'] as $prefix => $action) {
        if (str_starts_with($data, $prefix)) {
            if (!$isAdmin) { $outcome['cb_answer'] = 'Chỉ admin.'; return; }
            $wid = substr($data, strlen($prefix));
            try { $say('', transition($d, $c, $wid, $action, '', $ukey)); $outcome['cb_answer'] = ($action === 'approve' ? '👍 Đã duyệt ' : '👎 Đã từ chối ').$wid; }
            catch (InvalidArgumentException $e) { $outcome['cb_answer'] = 'Yêu cầu này đã được xử lý trước đó.'; audit($d, $ukey, 'blocked.duplicate', $wid); }
            return;
        }
    }
    $outcome['cb_answer'] = 'Nút đã hết hạn, bấm lại nhé.';
}
// $ctx: kind(message|callback), channel(tg|zalo), target(reply id), chat_id,
// private(bool), sender_key(?string), sender_ref(string), text, data, key(dedupe).
function handle(PDO $d, array $c, array $ctx, array $prepared, array &$outcome): void {
    $outcome['cb_answer'] = '';
    $tgt = (string)$ctx['target'];
    $say = function (string $suffix, string $msg, ?string $kb = null) use ($d, $ctx, $tgt) { queue($d, $ctx['key'].$suffix, $ctx['channel'], $tgt, $msg, $kb); };
    $isAdmin = ($ctx['sender_key'] ?? null) !== null && in_array($ctx['sender_key'], $c['admins'], true);
    if (($ctx['kind'] ?? 'message') === 'callback') { handleCallback($d, $c, $ctx, $say, $isAdmin, $outcome); return; }
    $text = $ctx['text'];
    $parts = preg_split('/\s+/', trim($text)); $cmd = explode('@', $parts[0] ?? '')[0];
    if ($cmd === '/id') { $say('', "🪪 Key của bạn: ".ownKey($ctx)."\nGửi key này cho admin để được thêm vào bot nhé!"); return; }
    $ukey = $ctx['sender_key']; if ($ukey === null) return;
    q($d, 'INSERT IGNORE INTO cb_users(id) VALUES(?)', [$ukey]);
    if (str_starts_with($text, '/')) {
        clearPending($d, $ukey);
        if ($cmd === '/cancel') { $say('', "🆗 Đã hủy thao tác đang chờ."); return; }
        if ($cmd === '/menu') { if (!$ctx['private']) return; $say('', menuText(), $ctx['channel'] === 'tg' ? mainMenuKb($isAdmin) : null); return; }
        if (!$ctx['private']) return;
        if (in_array($cmd, ['/start', '/help'], true)) $reply = "👋 Chào bạn! Gửi link sản phẩm Shopee để mình tạo link hoàn tiền nhé.\n🎛️ Bấm /menu để mở bảng nút.\nLệnh: /balance /links /orders /bank /withdraw /policy /support\n🧪 Bản test: chưa có đơn Shopee thật, không chuyển tiền ngân hàng.";
        elseif ($cmd === '/balance') $reply = renderBalance(balance($d, $ukey));
        elseif ($cmd === '/policy') $reply = currentPolicy($d);
        elseif ($cmd === '/bank') {
            $v = trim(mb_substr($text, (int)(strpos($text, ' ') ?: strlen($text))));
            if ($v === '') {
                $stored = q($d, 'SELECT bank FROM cb_users WHERE id=?', [$ukey])->fetchColumn();
                if (!$stored) $reply = "🏦 Chưa có thông tin nhận tiền.\nDùng: /bank ngân_hàng | số_tài_khoản | tên_chủ";
                else try { $reply = "🏦 Đã lưu: ".maskBank(decBank($c, (string)$stored)[0])."\n(gửi lại /bank ... để đổi)"; }
                catch (Throwable $e) { $reply = "⚠️ Bản ghi nhận tiền bị lỗi, gửi lại /bank ... để lưu mới nhé."; }
            }
            else { q($d, 'UPDATE cb_users SET bank=? WHERE id=?', [encBank($c, $v), $ukey]); audit($d, $ukey, 'bank.update', $ukey); $reply = "🏦 Đã lưu thông tin nhận tiền (đã mã hóa)."; }
        }
        elseif ($cmd === '/links') $reply = renderLinks(q($d, 'SELECT token,origin,created_at FROM cb_links WHERE user_id=? ORDER BY created_at DESC LIMIT 10', [$ukey])->fetchAll());
        elseif ($cmd === '/orders') $reply = renderOrders(q($d, 'SELECT c.id,c.state,c.commission,c.share FROM cb_conversions c JOIN cb_links l ON l.token=c.token WHERE l.user_id=? ORDER BY c.created_at DESC LIMIT 10', [$ukey])->fetchAll());
        elseif ($cmd === '/withdraw') {
            if (count($parts) !== 2 || !ctype_digit($parts[1]) || strlen($parts[1]) > 12) { $outcome['blocked'] = 'withdraw-invalid'; throw new InvalidArgumentException('⚠️ Dùng: /withdraw số_nguyên_VNĐ'); }
            try {
                checkWithdrawSlot($d, $ukey, $outcome);
                $wid = substr(hash('sha256', $ctx['key']), 0, 16); withdraw($d, $c, $ukey, (int)$parts[1], $wid);
            } catch (InvalidArgumentException $e) { if (!isset($outcome['blocked'])) $outcome['blocked'] = 'withdraw-invalid'; throw $e; }
            notifyAdminsRequest($d, $c, $ctx['key'], $ukey, $wid, $parts[1]);
            $reply = "📨 Đã tạo yêu cầu rút ".$wid."\n⏳ Chờ admin duyệt nhé!";
        }
        elseif ($cmd === '/support') { $v = mb_substr($text, 0, 1000); foreach ($c['admins'] as $admin) { [$ach, $atgt] = routeFor($admin); queue($d, $ctx['key'].':support:'.$admin, $ach, (string)$atgt, '📩 Khách '.$ukey.': '.$v); } $reply = "📩 Đã gửi tới quản trị, chờ phản hồi nhé!"; }
        elseif ($cmd === '/requests' && $isAdmin) {
            $rows = q($d, "SELECT id,user_id,amount,state FROM cb_withdrawals WHERE state IN ('requested','approved') ORDER BY created_at DESC LIMIT 10")->fetchAll();
            $reply = renderRequests($rows);
        }
        elseif ($cmd === '/setpolicy' && $isAdmin) {
            $v = trim(mb_substr($text, (int)(strpos($text, ' ') ?: strlen($text))));
            if ($v === '') throw new InvalidArgumentException('✍️ Nội dung chính sách trống.');
            setSetting($d, 'policy', $v); audit($d, $ukey, 'policy.update', '', mb_substr($v, 0, 100));
            $reply = "🛡️ Đã cập nhật chính sách bảo hành.";
        }
        elseif (in_array($cmd, ['/credit', '/debit'], true) && $isAdmin) {
            if (count($parts) < 3) throw new InvalidArgumentException('⚠️ Dùng: '.$cmd.' user số_tiền [lý do]');
            $target = resolveUserKey($parts[1]);
            if (!ctype_digit($parts[2]) || strlen($parts[2]) > 12 || (int)$parts[2] <= 0) throw new InvalidArgumentException('⚠️ Số tiền không hợp lệ.');
            $amt = (int)$parts[2]; if ($cmd === '/debit') $amt = -$amt;
            $notice = adjust($d, $c, $ukey, $target, $amt, trim(implode(' ', array_slice($parts, 3))));
            [$nch, $ntgt] = routeFor($target); queue($d, $ctx['key'].':adjust:'.$target, $nch, (string)$ntgt, $notice);
            $reply = "✅ Đã ".($cmd === '/credit' ? 'nộp' : 'trừ')." tiền cho ".$target.".";
        }
        elseif ($cmd === '/customers' && $isAdmin) $reply = renderCustomers($d, q($d, 'SELECT id FROM cb_users ORDER BY first_seen DESC LIMIT 30')->fetchAll());
        elseif ($cmd === '/audit' && $isAdmin) $reply = renderAudit(q($d, 'SELECT at,actor,action,ref FROM cb_audit ORDER BY id DESC LIMIT 15')->fetchAll());
        elseif (in_array($cmd, ['/approve', '/reject', '/cancel', '/paid'], true) && $isAdmin) $reply = transition($d, $c, $parts[1] ?? '', substr($cmd, 1), implode(' ', array_slice($parts, 2)), $ukey);
        else $reply = "🤔 Lệnh không có hoặc bạn không có quyền.";
        $say('', $reply); return;
    }
    if ($ctx['private'] && consumePending($d, $c, $ctx, $ukey, $say, $outcome)) return;
    if (!$prepared) return;
    assertLinkQuota($d, $c, $ukey, $outcome);
    $replies = []; $created = false;
    foreach ($prepared as $p) {
        if (isset($p['error'])) { $replies[] = '⚠️ '.$p['error']; continue; }
        if (!empty($p['pending'])) { $created = true; $replies[] = "⏳ Đang tạo link cho:\n".$p['origin']."\nBot sẽ gửi link ngay khi xong (có thể vài phút)."; continue; }
        $l = newLink($d, $c, $ukey, $p['origin'], $p); $created = true; $replies[] = "🔗 Link hoàn tiền của bạn:\n".$l['url'];
    }
    if ($created) q($d, 'UPDATE cb_users SET last_link_at=? WHERE id=?', [time(), $ukey]);
    if ($replies) $say('', implode("\n\n", $replies));
}
function prepareLinks(array $c, array $ctx, array $m): array {
    if (($ctx['kind'] ?? 'message') === 'callback') return [];
    if ($ctx['sender_key'] === null || ($m['from']['is_bot'] ?? false) || str_starts_with($m['text'] ?? '', '/')) return [];
    if (!$ctx['private'] && $ctx['channel'] === 'tg' && !in_array($ctx['chat_id'], $c['source_chats'] ?? [], true)) return [];
    $out = [];
    foreach (extractUrls($m) as $url) {
        try { safeUrl($url); } catch (InvalidArgumentException $e) { continue; }
        try { $out[] = ['origin' => canonical($url)]; }
        catch (InvalidArgumentException $e) {
            if ($c['resolve_short_links']) { try { $out[] = ['origin' => resolveShort($url)]; } catch (Throwable $e) { $out[] = ['error' => 'Không resolve được link ngắn hoặc link bị chặn.']; } }
            else $out[] = ['error' => 'Link ngắn chưa được bật resolve trong config.'];
        }
    }
    return $out;
}
// Returns the callback toast answer ('' = silent ack). Blocked attempts are
// audited AFTER the savepoint rollback so the record survives the throw.
function processUpdate(PDO $d, array $c, array $ctx, array $prepared = [], ?callable $linkHttpCall = null): string {
    if (!is_string($ctx['key']) || $ctx['key'] === '' || strlen($ctx['key']) > 200) throw new InvalidArgumentException('Invalid update ID.');
    if (q($d, 'SELECT id FROM cb_updates WHERE id=?', [$ctx['key']])->fetch()) return '';
    $outcome = [];
    if ($prepared && is_string($ctx['sender_key'] ?? null)) {
        // Read-only early check avoids spending Shopee API quota for blocked users.
        // handle() repeats it in atomic() to close the race before DB writes.
        $quotaOk = true;
        try { assertLinkQuota($d, $c, $ctx['sender_key']); }
        catch (InvalidArgumentException $e) { $quotaOk = false; }
        if ($quotaOk) $prepared = presignLinks($d, $c, $ctx['sender_key'], $prepared, $linkHttpCall);
    }
    return atomic($d, function () use ($d, $c, $ctx, $prepared, &$outcome) {
        if (q($d, 'SELECT id FROM cb_updates WHERE id=?', [$ctx['key']])->fetch()) return '';
        q($d, 'SAVEPOINT message_work');
        try { handle($d, $c, $ctx, $prepared, $outcome); }
        catch (InvalidArgumentException $e) {
            q($d, 'ROLLBACK TO SAVEPOINT message_work');
            $actor = $ctx['sender_key'] ?? (($ctx['channel'] ?? '?').':'.($ctx['sender_ref'] ?? '?'));
            audit($d, (string)$actor, 'blocked.'.($outcome['blocked'] ?? 'invalid'), $ctx['key']);
            if (($ctx['private'] ?? false) && ($ctx['sender_key'] ?? null) !== null) queue($d, 'error:'.$ctx['key'], $ctx['channel'], (string)$ctx['target'], $e->getMessage());
            elseif (($ctx['kind'] ?? 'message') === 'callback') $outcome['cb_answer'] = $e->getMessage();
            else $outcome['cb_answer'] = '';
        }
        q($d, 'INSERT INTO cb_updates(id) VALUES(?)', [$ctx['key']]);
        return (string)($outcome['cb_answer'] ?? '');
    });
}
function telegram(array $c, string $method, array $data): mixed {
    $ch = curl_init('https://api.telegram.org/bot'.$c['telegram_token'].'/'.$method);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data, JSON_THROW_ON_ERROR), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 3]);
    $raw = curl_exec($ch); curl_close($ch); $r = is_string($raw) ? json_decode($raw, true) : null;
    if (!($r['ok'] ?? false)) throw new RuntimeException('Telegram call failed.'); return $r['result'];
}
function tgAnswer(array $c, string $cbId, string $text): void {
    $data = ['callback_query_id' => $cbId];
    if ($text !== '') $data['text'] = mb_substr($text, 0, 190);
    telegram($c, 'answerCallbackQuery', $data);
}
function deliverRow(PDO $d, array $c, array $r): void {
    if ($r['channel'] === 'zalo') zaloSend($c, $r['target'], $r['message']);
    else {
        $msg = ['chat_id' => (int)$r['target'], 'text' => mb_substr($r['message'], 0, 3900), 'link_preview_options' => ['is_disabled' => true]];
        if (!empty($r['markup'])) { $mk = json_decode((string)$r['markup'], true); if (is_array($mk)) $msg['reply_markup'] = $mk; }
        telegram($c, 'sendMessage', $msg);
    }
}
function sendRow(PDO $d, array $c, array $r): void {
    deliverRow($d, $c, $r);
    q($d, 'UPDATE cb_outbox SET sent=1,sent_at=NOW() WHERE id=?', [$r['id']]);
}
function noteRowFailed(PDO $d, array $r): void {
    q($d, 'UPDATE cb_outbox SET attempts=attempts+1,next_try=? WHERE id=?', [time() + min(300, 2 ** min(8, (int)$r['attempts'] + 1)), $r['id']]);
}
function matchForUpdate(PDO $d, string $base): array {
    // Rows queued by one update: base key, base-suffixed children, error reply.
    return q($d, 'SELECT * FROM cb_outbox WHERE sent=0 AND (id=? OR id LIKE ? OR id=?)', [$base, $base.':%', 'error:'.$base])->fetchAll();
}
function flushForUpdate(PDO $d, array $c, array $ctx): void {
    // Fast-path: deliver this update's messages right after commit, under the
    // shared delivery lock. If the cron worker holds it, skip — it delivers.
    if ((int)q($d, "SELECT GET_LOCK('cb_test_delivery',0)")->fetchColumn() !== 1) return;
    try { foreach (matchForUpdate($d, $ctx['key']) as $r) { try { sendRow($d, $c, $r); } catch (Throwable $e) { noteRowFailed($d, $r); } usleep(500000); } }
    finally { q($d, "SELECT RELEASE_LOCK('cb_test_delivery')"); }
}
function flushOutbox(PDO $d, array $c, int $limit = 10): void {
    if ((int)q($d, "SELECT GET_LOCK('cb_test_delivery',0)")->fetchColumn() !== 1) return;
    try {
        $rows = q($d, 'SELECT * FROM cb_outbox WHERE sent=0 AND next_try<=? LIMIT '.max(1, min(20, $limit)), [time()])->fetchAll();
        foreach ($rows as $r) {
            try { sendRow($d, $c, $r); }
            catch (Throwable $e) { noteRowFailed($d, $r); }
            usleep(500000);
        }
    } finally { q($d, "SELECT RELEASE_LOCK('cb_test_delivery')"); }
}
function pruneOutbox(PDO $d, array $c): int {
    return q($d, 'DELETE FROM cb_outbox WHERE sent=1 AND sent_at < NOW() - INTERVAL ? DAY', [$c['outbox_retention_days']])->rowCount();
}
function pruneNotifications(PDO $d): int {
    return q($d, 'DELETE FROM cb_notifications WHERE read_at IS NOT NULL AND created_at < NOW() - INTERVAL 90 DAY')->rowCount();
}
