<?php
declare(strict_types=1);
// Web UI helpers. Loaded after core.php.
// Auth: Telegram Login Widget verified with HMAC-SHA256(bot_token) (classic alg).
// All state-changing requests require a session CSRF token. CSP allows only
// same-origin scripts (assets/*.js) + the Telegram widget; inline JS is banned.
function web_boot(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
        session_name('cbsess');
        session_start();
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://telegram.org; frame-src https://oauth.telegram.org; style-src 'self'; img-src 'self' https://t.me data:");
}
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return (string)$_SESSION['csrf']; }
function csrf_check(): void {
    $t = $_POST['csrf'] ?? '';
    if (is_string($t) && hash_equals((string)($_SESSION['csrf'] ?? ''), $t)) return;
    http_response_code(400);
    try { $c = cfg(); web_header('Yêu cầu không hợp lệ', $c); }
    catch (Throwable $e) { echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><title>Lỗi</title></head><body>'; }
    echo '<p class="alert err">Phiên làm việc đã hết hạn hoặc yêu cầu không hợp lệ. Vui lòng quay lại và thử lại.</p>';
    echo '<p><a href="dashboard.php">← Về bảng điều khiển</a></p>';
    web_footer(); exit;
}
function flash_set(string $err, string $ok): void { $_SESSION['flash'] = ['e' => $err, 'o' => $ok]; }
function flash_take(): array {
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    return [is_array($f) ? (string)($f['e'] ?? '') : '', is_array($f) ? (string)($f['o'] ?? '') : ''];
}
function web_user(): ?string { $u = $_SESSION['uid'] ?? null; return is_string($u) && $u !== '' ? $u : null; }
function web_is_guest(): bool { $u = web_user(); return $u !== null && isGuest($u); }
// Guest cookie: random id kept on this device for 1 year, so a returning visitor
// gets the same links/orders back without any login.
const GUEST_COOKIE = 'cbguest';
function guest_cookie_id(): ?string { $v = $_COOKIE[GUEST_COOKIE] ?? ''; return is_string($v) && preg_match('/^[a-f0-9]{32}$/', $v) ? $v : null; }
function guest_cookie_set(?string $hex): void {
    setcookie(GUEST_COOKIE, $hex ?? '', ['expires' => $hex === null ? time() - 3600 : time() + 365 * 86400, 'path' => '/', 'httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
    if ($hex === null) unset($_COOKIE[GUEST_COOKIE]); else $_COOKIE[GUEST_COOKIE] = $hex;
}
function guest_start(): string {
    $hex = guest_cookie_id() ?? bin2hex(random_bytes(16));
    guest_cookie_set($hex);
    session_regenerate_id(true);
    $_SESSION['uid'] = guestKey($hex); $_SESSION['name'] = 'Khách';
    return $_SESSION['uid'];
}
function guest_cta(string $why): string {
    return '<div class="alert info guest-cta" role="note"><strong>Bạn đang dùng chế độ khách.</strong> '.h($why)
        .' Dữ liệu chỉ gắn với trình duyệt này — xóa cookie hoặc đổi máy sẽ mất. '
        .'<a class="btn" href="index.php?link=1">Đăng nhập Telegram để giữ hoa hồng</a></div>';
}
function web_name(): string { $n = $_SESSION['name'] ?? ''; return is_string($n) && $n !== '' ? $n : (string)web_user(); }
function web_is_admin(array $c): bool { $u = web_user(); return $u !== null && in_array($u, $c['admins'], true); }
function require_login(): string { $u = web_user(); if ($u === null) { header('Location: index.php'); exit; } return $u; }
function vnd(int $n): string { return number_format($n, 0, ',', '.').'đ'; }
function webRank(int $orders): array {
    if ($orders >= 20) return ['Kim Cương', 'rank-diamond'];
    if ($orders >= 5) return ['Vàng', 'rank-gold'];
    if ($orders >= 1) return ['Bạc', 'rank-silver'];
    return ['Thành viên mới', 'rank-basic'];
}
function stateBadge(string $st): string {
    $map = [
        'requested' => ['Chờ duyệt', 'warn'], 'pending' => ['Chờ xử lý', 'warn'],
        'approved' => ['Đã duyệt', 'info'], 'validated' => ['Đã đối soát', 'info'],
        'paid' => ['Đã chuyển', 'ok'], 'settled' => ['Hoàn tất', 'ok'],
        'rejected' => ['Từ chối', 'err'], 'cancel' => ['Đã hủy', 'err'], 'cancelled' => ['Đã hủy', 'err'],
    ];
    [$label, $tone] = $map[$st] ?? [$st, 'muted'];
    return '<span class="badge badge-'.$tone.'"><span class="dot"></span>'.h($label).'</span>';
}
function fmtTime(string $ts): string {
    $t = strtotime($ts);
    if ($t === false) return h($ts);
    return '<time datetime="'.h($ts).'" title="'.h($ts).'">'.date('d/m H:i', $t).'</time>';
}
function extLink(string $url, string $label, string $cls = 'btn ghost'): string {
    try { safeUrl($url); } catch (Throwable $e) { return h($label); }
    return '<a class="'.h($cls).'" href="'.h($url).'" target="_blank" rel="noopener noreferrer" aria-label="'.h($label.' (mở tab mới)').'">'.h($label).' <span aria-hidden="true">↗</span></a>';
}
function unreadCount(array $c): int {
    $u = web_user(); if ($u === null) return 0;
    try { return (int)q(web_db($c), 'SELECT COUNT(*) FROM cb_notifications WHERE user_id=? AND read_at IS NULL', [$u])->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}
function web_db(array $c): PDO { static $pdo = null; if ($pdo === null) $pdo = db($c); return $pdo; }
function featureOn(array $c, string $k): bool {
    try { return setting(web_db($c), 'feature_'.$k, '0') === '1'; } catch (Throwable $e) { return false; }
}
function verify_tg_login(array $c, array $q): ?array {
    if (!isset($q['hash'], $q['id'], $q['auth_date'])) return null;
    $check = [];
    foreach ($q as $k => $v) { if ($k === 'hash') continue; if (!is_string($v)) return null; $check[$k] = $v; }
    ksort($check);
    $pairs = [];
    foreach ($check as $k => $v) $pairs[] = $k.'='.$v;
    $dsc = implode("\n", $pairs);
    $secret = hash('sha256', $c['telegram_token'], true); // raw 32 bytes
    $calc = hash_hmac('sha256', $dsc, $secret);
    if (!hash_equals($calc, (string)$q['hash'])) return null;
    if (time() - (int)$q['auth_date'] > 86400) return null;
    $id = (int)$q['id'];
    if ($id <= 0) return null;
    $name = trim((string)($q['first_name'] ?? '').' '.(string)($q['last_name'] ?? ''));
    return ['key' => tgKey($id), 'name' => $name !== '' ? $name : (string)($q['username'] ?? tgKey($id))];
}
function tg_bot_username(array $c): string {
    if (!empty($c['bot_username'])) return (string)$c['bot_username'];
    try { $r = telegram($c, 'getMe', []); return (string)($r['username'] ?? ''); } catch (Throwable $e) { return ''; }
}
function themeToggleSvg(): string {
    $sun = '<svg class="ic-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
    $moon = '<svg class="ic-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
    return $sun.$moon;
}
function web_header(string $title, array $c, string $active = '', ?int $unread = null): void {
    $u = web_user();
    if ($unread === null && $u !== null) $unread = unreadCount($c);
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>'.h($title).'</title>';
    echo '<meta name="theme-color" content="#f6f7f9" media="(prefers-color-scheme: light)">';
    echo '<meta name="theme-color" content="#0d1117" media="(prefers-color-scheme: dark)">';
    echo '<script src="assets/theme.js?v=6"></script>'; // no defer: set theme before paint
    echo '<link rel="stylesheet" href="assets/style.css?v=9"></head><body>';
    echo '<a class="skip" href="#main">Bỏ qua tới nội dung</a>';
    echo '<div class="testbar">🧪 Bản TEST — dữ liệu giả lập, không phải đơn Shopee thật</div>';
    echo '<header class="top"><div class="wrap">';
    echo '<a class="brand" href="'.($u !== null ? 'dashboard.php' : 'index.php').'">Shopee Cashback</a>';
    if ($u !== null) {
        echo '<nav class="mainnav" aria-label="Điều hướng chính">';
        $tabs = [['home', 'dashboard.php?tab=home', 'Tổng quan'], ['links', 'dashboard.php?tab=links', 'Link'], ['orders', 'dashboard.php?tab=orders', 'Đơn hàng'], ['wallet', 'dashboard.php?tab=wallet', 'Ví'], ['profile', 'dashboard.php?tab=profile', 'Hồ sơ'], ['help', 'dashboard.php?tab=help', 'Trợ giúp']];
        if (featureOn($c, 'deals')) $tabs[] = ['deals', 'dashboard.php?tab=deals', 'Deal'];
        if (featureOn($c, 'referral')) $tabs[] = ['referral', 'dashboard.php?tab=referral', 'Mời bạn'];
        if (web_is_admin($c)) $tabs[] = ['admin', 'admin.php', 'Quản trị'];
        foreach ($tabs as [$k, $href, $lbl]) {
            $cur = $active === $k ? ' aria-current="page"' : '';
            echo '<a href="'.h($href).'"'.$cur.'>'.h($lbl).'</a>';
        }
        echo '</nav>';
    }
    echo '<div class="top-actions">';
    if ($u !== null) {
        echo '<a class="icon-btn" data-bell href="dashboard.php?tab=notifications" aria-label="Thông báo">';
        echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>';
        if (($unread ?? 0) > 0) echo '<span class="badge-count" data-bell-count>'.(int)$unread.'</span>';
        else echo '<span class="badge-count hide" data-bell-count>0</span>';
        echo '</a>';
    }
    echo '<button type="button" class="icon-btn" data-theme-toggle aria-label="Đổi giao diện sáng/tối" aria-pressed="false">'.themeToggleSvg().'</button>';
    if ($u !== null && isGuest($u)) echo '<a class="linkbtn" href="index.php?link=1">Đăng nhập</a>';
    elseif ($u !== null) echo '<a class="linkbtn" href="logout.php">Đăng xuất</a>';
    echo '</div></div></header>';
    echo '<main id="main" class="wrap">';
    if ($u !== null && isGuest($u) && $active !== '') echo guest_cta('Link và đơn hàng vẫn được ghi nhận, nhưng cần đăng nhập để nhận và rút tiền hoa hồng.');
}
function web_footer(): void { echo '</main><footer class="wrap muted">🔒 Bản TEST giả lập — không phải dữ liệu Shopee thật, bot không chuyển khoản ngân hàng.</footer><script src="assets/app.js?v=9" defer></script></body></html>'; }
function flash(string $err, string $ok): void {
    if ($ok !== '') echo '<p class="alert ok" role="status">'.h($ok).'</p>';
    if ($err !== '') echo '<p class="alert err" role="alert">'.h($err).'</p>';
}
function balancesMany(PDO $d, array $ids): array {
    // Same totals as balance() but in 4 grouped queries total (no N+1).
    $out = [];
    foreach ($ids as $id) $out[(string)$id] = ['available' => 0, 'pending' => 0];
    if (!$ids) return $out;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = q($d, "SELECT l.user_id u, SUM(g.amount) v FROM cb_ledger g JOIN cb_conversions c ON c.id=g.conversion_id JOIN cb_links l ON l.token=c.token WHERE l.user_id IN ($in) GROUP BY l.user_id", $ids)->fetchAll();
    foreach ($rows as $r) if (isset($out[$r['u']])) $out[$r['u']]['available'] += (int)$r['v'];
    $rows = q($d, "SELECT user_id u, SUM(amount) v FROM cb_adjustments WHERE user_id IN ($in) GROUP BY user_id", $ids)->fetchAll();
    foreach ($rows as $r) if (isset($out[$r['u']])) $out[$r['u']]['available'] += (int)$r['v'];
    $rows = q($d, "SELECT user_id u, SUM(amount) v FROM cb_withdrawals WHERE state IN ('requested','approved','paid') AND user_id IN ($in) GROUP BY user_id", $ids)->fetchAll();
    foreach ($rows as $r) if (isset($out[$r['u']])) $out[$r['u']]['available'] -= (int)$r['v'];
    $rows = q($d, "SELECT l.user_id u, SUM(c.share) v FROM cb_conversions c JOIN cb_links l ON l.token=c.token WHERE c.state IN ('pending','validated') AND l.user_id IN ($in) GROUP BY l.user_id", $ids)->fetchAll();
    foreach ($rows as $r) if (isset($out[$r['u']])) $out[$r['u']]['pending'] = (int)$r['v'];
    return $out;
}
function validateBankInputs(string $b1, string $b2, string $b3): string {
    $b1 = trim($b1); $b2 = trim($b2); $b3 = trim($b3);
    if ($b1 === '' || $b2 === '' || $b3 === '') {
        throw new InvalidArgumentException('Vui lòng điền đầy đủ cả 3 thông tin: Tên ngân hàng, Số tài khoản và Tên chủ tài khoản.');
    }
    return $b1.' | '.$b2.' | '.$b3;
}
function orderMilestoneClasses(string $st, int $i): string {
    if ($st === 'settled') return 'done';
    $rank = ['pending' => 0, 'validated' => 1];
    $cur = $rank[$st] ?? -1;
    if ($i < $cur) return 'done';
    if ($i === $cur) return 'now';
    return '';
}
function probeFlash(array $res): array {
    if (isset($res['error'])) {
        return ['Kiểm tra thất bại: '.$res['error'], ''];
    }
    if (empty($res['match'])) {
        return ['Cảnh báo: Tracking không khớp (sub-id gửi đi không khớp với utm_content Shopee phản hồi).', ''];
    }
    return ['', 'Kiểm tra thành công: Tracking chuẩn xác, Shopee đã nhận đúng sub-id.'];
}
function syncFlash(array $sum, bool $dry): array {
    $seen = (int)($sum['seen'] ?? 0);
    $matched = (int)($sum['matched'] ?? 0);
    $imported = (int)($sum['imported'] ?? 0);
    $skipped = (int)($sum['skipped'] ?? 0);
    if ($seen === 0) {
        return ['Không tìm thấy đơn nào trong báo cáo 30 ngày qua (vui lòng kiểm tra lại cookie affiliate hoặc chưa có đơn phát sinh).', ''];
    }
    if (!$dry && $imported === 0) {
        return ['Không có đơn nào được nhập vào hệ thống (thấy '.$seen.', bỏ '.$skipped.' do không khớp token link).', ''];
    }
    $prefix = $dry ? 'Xem trước' : 'Đồng bộ thành công';
    return ['', $prefix.': thấy '.$seen.', khớp '.$matched.', nhập '.$imported.', bỏ '.$skipped.'.'];
}
function mklinkFlash(int $made, int $failed, int $pending = 0): array {
    if ($made === 0 && $pending === 0) {
        return ['Chưa tạo được link hoàn tiền. Xem chi tiết bên dưới.', ''];
    }
    $parts = [];
    if ($made > 0) $parts[] = $made > 1 ? 'Đã tạo '.$made.' link hoàn tiền' : 'Đã tạo link hoàn tiền';
    if ($pending > 0) $parts[] = $pending.' link đang được tạo, sẽ gửi vào Thông báo khi xong';
    if ($failed > 0) $parts[] = $failed.' link lỗi (xem bên dưới)';
    return ['', implode('; ', $parts).'.'];
}
function referralLinkInfo(string $uid, string $botUsername): array {
    if (isGuest($uid)) {
        return ['is_guest' => true, 'link' => '', 'error' => ''];
    }
    $bot = trim($botUsername);
    if ($bot === '') {
        return ['is_guest' => false, 'link' => '', 'error' => 'Chưa tạo được link mời (hệ thống chưa cấu hình bot_username).'];
    }
    $numeric = preg_replace('/\D/', '', $uid);
    if ($numeric === '') {
        return ['is_guest' => false, 'link' => '', 'error' => 'Chưa tạo được link mời (định dạng tài khoản không hỗ trợ link mời Telegram).'];
    }
    return ['is_guest' => false, 'link' => 'https://t.me/'.$bot.'?start=ref_'.$numeric, 'error' => ''];
}
function adminWithdrawActionLabel(string $act): string {
    $map = [
        'approve' => 'đã duyệt',
        'reject' => 'đã từ chối',
        'cancel' => 'đã hủy',
        'paid' => 'đã xác nhận chi trả',
    ];
    return $map[$act] ?? $act;
}
