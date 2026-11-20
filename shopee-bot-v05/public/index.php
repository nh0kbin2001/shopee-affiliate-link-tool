<?php
declare(strict_types=1);
require __DIR__.'/_boot.php';
$c = cfg(); web_boot();
$wantLink = isset($_GET['link']);
if (web_user() !== null && !(web_is_guest() && $wantLink)) { header('Location: dashboard.php'); exit; }
$canGuest = (bool)$c['guest_mode'];
$hasGuest = guest_cookie_id() !== null;
$bot = tg_bot_username($c);
$host = parse_url($c['webhook_url'], PHP_URL_HOST) ?: '';
web_header('Đăng nhập', $c);
?>
<div class="login-hero">
  <div class="login-icon">🎁</div>
  <h1 class="login-title">Shopee Cashback</h1>
  <p class="muted">Mua sắm Shopee thông minh — tự động nhận hoàn tiền về ví khi mua qua link Shopee Cashback!</p>
  <?php if ($bot === '' || $host === ''): ?>
    <p class="alert err">Chưa xác định được bot/domain. Kiểm tra <code>telegram_token</code> và <code>webhook_url</code> trong <code>config.php</code>, hoặc thêm <code>'bot_username' => 'affduongg_bot'</code>.</p>
  <?php else: ?>
    <p class="muted login-hint">Nhấn nút bên dưới để xác thực tài khoản qua ứng dụng Telegram.</p>
    <div class="login-widget">
      <script async src="https://telegram.org/js/telegram-widget.js?22"
              data-telegram-login="<?= h($bot) ?>"
              data-size="large"
              data-userpic="false"
              data-auth-url="<?= h('https://'.$host.'/login.php') ?>"
              data-request-access="write"></script>
    </div>
    <noscript><p class="alert err">Cần bật JavaScript để dùng Telegram Login.</p></noscript>
  <?php endif; ?>
  <?php if ($wantLink && ($hasGuest || web_is_guest())): ?>
    <p class="alert info">Sau khi đăng nhập, mọi link, đơn hàng và hoa hồng bạn tạo ở chế độ khách trên máy này sẽ được chuyển vào tài khoản Telegram.</p>
  <?php endif; ?>
  <?php if ($canGuest && !web_is_guest()): ?>
    <p class="login-or">— hoặc —</p>
    <form method="post" action="guest.php" class="login-guest">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button type="submit"><?= $hasGuest ? 'Tiếp tục với tư cách khách' : 'Dùng thử không cần đăng nhập' ?></button>
    </form>
    <p class="hint">Chế độ khách: tạo link và theo dõi đơn ngay. Muốn nhận/rút hoa hồng thì đăng nhập Telegram sau.</p>
  <?php endif; ?>
</div>
<?php web_footer();
