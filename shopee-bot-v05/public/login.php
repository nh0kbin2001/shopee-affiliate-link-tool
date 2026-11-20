<?php
declare(strict_types=1);
require __DIR__.'/_boot.php';
$c = cfg(); web_boot();
$me = verify_tg_login($c, $_GET);
if ($me === null) {
    http_response_code(403);
    web_header('Lỗi đăng nhập', $c);
    echo '<div class="login-hero"><div class="login-icon">🎁</div><h1>Không đăng nhập được</h1>';
    echo '<p class="alert err">Xác thực Telegram thất bại hoặc đã hết hạn. Vui lòng thử lại.</p>';
    echo '<p><a class="btn" href="index.php">← Về trang đăng nhập</a></p></div>';
    web_footer(); exit;
}
$ukey = $me['key'];
if (!in_array($ukey, $c['allowed_users'], true)) {
    web_header('Chờ duyệt', $c);
    echo '<div class="login-hero"><div class="login-icon">⏳</div><h1>Tài khoản chờ duyệt</h1>';
    echo '<p class="muted">Tài khoản của bạn chưa được admin thêm vào hệ thống. Gửi mã dưới đây cho admin để được duyệt.</p>';
    echo '<p class="u-key"><span class="mono">'.h($ukey).'</span></p>';
    echo '<p class="row-actions"><button type="button" data-copy="'.h($ukey).'">Sao chép mã</button> <a class="btn ghost" href="index.php">← Về trang chủ</a></p>';
    if ($c['guest_mode']) echo '<p class="hint">Trong lúc chờ duyệt, bạn vẫn có thể dùng chế độ khách từ trang chủ; link và đơn sẽ được gộp vào tài khoản khi được duyệt và đăng nhập lại.</p>';
    echo '</div>';
    web_footer(); exit;
}
// Guest data on this device (session or cookie) moves into the real account.
$guest = web_is_guest() ? (string)web_user() : (($g = guest_cookie_id()) !== null ? guestKey($g) : null);
$claimed = null;
if ($guest !== null) {
    try { $d = db($c); $claimed = atomic($d, fn() => claimGuest($d, $guest, $ukey)); guest_cookie_set(null); }
    catch (Throwable $e) { error_log('claimGuest: '.$e->getMessage()); }
}
session_regenerate_id(true);
$_SESSION['uid'] = $ukey;
$_SESSION['name'] = $me['name'];
if ($claimed !== null && $claimed['links'] > 0) flash_set('', 'Đã liên kết '.$claimed['links'].' link và '.$claimed['orders'].' đơn từ chế độ khách vào tài khoản của bạn.');
header('Location: dashboard.php'); exit;
