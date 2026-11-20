<?php
declare(strict_types=1);
require __DIR__.'/_boot.php';
$c = cfg(); web_boot();
$uid = require_login();
$d = db($c);
$allowed = ['home', 'links', 'orders', 'wallet', 'profile', 'help', 'notifications'];
if (featureOn($c, 'deals')) $allowed[] = 'deals';
if (featureOn($c, 'referral')) $allowed[] = 'referral';
$tab = (string)($_GET['tab'] ?? 'home');
if (!in_array($tab, $allowed, true)) $tab = 'home';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $act = (string)($_POST['action'] ?? '');
    try {
        q($d, 'INSERT IGNORE INTO cb_users(id) VALUES(?)', [$uid]);
        if (isGuest($uid) && in_array($act, ['bank', 'withdraw'], true)) throw new InvalidArgumentException('Đăng nhập Telegram để thêm tài khoản nhận tiền và rút hoa hồng.');
        if ($act === 'bank') {
            $b1 = trim((string)($_POST['bank_name'] ?? ''));
            $b2 = trim((string)($_POST['bank_acct'] ?? ''));
            $b3 = trim((string)($_POST['bank_holder'] ?? ''));
            $combined = validateBankInputs($b1, $b2, $b3);
            q($d, 'UPDATE cb_users SET bank=? WHERE id=?', [encBank($c, $combined), $uid]);
            audit($d, $uid, 'bank.update', $uid);
            flash_set('', 'Đã lưu thông tin nhận tiền (mã hóa).');
        } elseif ($act === 'withdraw') {
            $amt = (string)($_POST['amount'] ?? '');
            if (!ctype_digit($amt) || strlen($amt) > 12) throw new InvalidArgumentException('Số tiền không hợp lệ.');
            atomic($d, function () use ($d, $c, $uid, $amt) {
                $out = []; checkWithdrawSlot($d, $uid, $out);
                $wid = bin2hex(random_bytes(8));
                withdraw($d, $c, $uid, (int)$amt, $wid);
                notifyAdminsRequest($d, $c, 'web:wd:'.$wid, $uid, $wid, $amt);
            });
            flash_set('', 'Đã tạo yêu cầu rút, chờ admin duyệt.');
        } elseif ($act === 'mklink') {
            $raw = trim((string)($_POST['url'] ?? ''));
            preg_match_all('~https://[^\s<>]+~u', $raw, $mm);
            $urls = array_slice(array_unique(array_map(fn($u) => rtrim($u, ".,;!?)\"'"), $mm[0])), 0, 5);
            if (!$urls) throw new InvalidArgumentException('Chưa thấy link Shopee hợp lệ. Hãy dán link sản phẩm Shopee (bắt đầu bằng https://).');
            $prepared = [];
            foreach ($urls as $url) {
                try { safeUrl($url); } catch (InvalidArgumentException $e) { continue; }
                try { $prepared[] = ['origin' => canonical($url)]; }
                catch (InvalidArgumentException $e) {
                    if ($c['resolve_short_links']) { try { $prepared[] = ['origin' => resolveShort($url)]; } catch (Throwable $e) { $prepared[] = ['error' => 'Không mở được link rút gọn. Hãy dán link sản phẩm đầy đủ (mở sản phẩm trên Shopee rồi sao chép liên kết).', 'origin' => $url]; } }
                    else $prepared[] = ['error' => 'Đây là link rút gọn. Hãy mở sản phẩm trên ứng dụng Shopee rồi sao chép liên kết đầy đủ (bắt đầu bằng shopee.vn/product/... hoặc shopee.vn/...).', 'origin' => $url];
                }
            }
            if (!$prepared) throw new InvalidArgumentException('Không nhận diện được link sản phẩm Shopee.');
            assertLinkQuota($d, $c, $uid);
            $prepared = presignLinks($d, $c, $uid, $prepared);
            $result = [];
            atomic($d, function () use ($d, $c, $uid, $prepared, &$result) {
                assertLinkQuota($d, $c, $uid);
                foreach ($prepared as $p) {
                    if (isset($p['error'])) { $result[] = ['error' => $p['error'], 'origin' => $p['origin'] ?? '']; continue; }
                    if (!empty($p['pending'])) { $result[] = ['pending' => true, 'origin' => $p['origin']]; continue; }
                    $l = newLink($d, $c, $uid, $p['origin'], $p);
                    $result[] = ['origin' => $p['origin'], 'url' => $l['url']];
                }
                $made = count(array_filter($result, fn($r) => !isset($r['error'])));  // links + pending
                if ($made > 0) q($d, 'UPDATE cb_users SET last_link_at=? WHERE id=?', [time(), $uid]);
            });
            $_SESSION['link_result'] = $result;
            $pending = count(array_filter($result, fn($r) => !empty($r['pending'])));
            $made = count(array_filter($result, fn($r) => !isset($r['error']) && empty($r['pending'])));
            $failed = count($result) - $made - $pending;
            [$fErr, $fOk] = mklinkFlash($made, $failed, $pending);
            flash_set($fErr, $fOk);
        } elseif ($act === 'markread') {
            q($d, 'UPDATE cb_notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL', [$uid]);
            flash_set('', 'Đã đánh dấu tất cả là đã đọc.');
        } else {
            throw new InvalidArgumentException('Thao tác không hợp lệ.');
        }
    } catch (InvalidArgumentException $e) { flash_set($e->getMessage(), ''); }
    catch (Throwable $e) { flash_set('Có lỗi, thử lại sau.', ''); }
    header('Location: dashboard.php?tab='.urlencode($act === 'mklink' ? 'home' : $tab)); exit;
}
[$err, $ok] = flash_take();
$linkResult = $_SESSION['link_result'] ?? null; unset($_SESSION['link_result']);
$bal = balance($d, $uid);
$active = $tab;
web_header('Bảng điều khiển', $c, $active);
flash($err, $ok);
?>

<?php if ($tab === 'home'): ?>
  <?php
    $nLinks = (int)q($d, 'SELECT COUNT(*) FROM cb_links WHERE user_id=?', [$uid])->fetchColumn();
    $nOrders = (int)q($d, 'SELECT COUNT(*) FROM cb_conversions c JOIN cb_links l ON l.token=c.token WHERE l.user_id=?', [$uid])->fetchColumn();
    $earned = (int)q($d, "SELECT COALESCE(SUM(c.share),0) FROM cb_conversions c JOIN cb_links l ON l.token=c.token WHERE l.user_id=? AND c.state='settled'", [$uid])->fetchColumn();
  ?>
  <h1>👋 Xin chào, <?= h(web_name()) ?></h1>
  <div class="stat-grid">
    <div class="stat"><div class="n money-pos"><?= h(vnd((int)$bal['available'])) ?></div><div class="t">Khả dụng</div></div>
    <div class="stat"><div class="n"><?= h(vnd((int)$bal['pending'])) ?></div><div class="t">Đang chờ</div></div>
    <div class="stat"><div class="n"><?= h(vnd($earned)) ?></div><div class="t">Đã nhận</div></div>
    <div class="stat"><div class="n"><?= $nOrders ?></div><div class="t">Tổng đơn</div></div>
  </div>

  <?php if (is_array($linkResult) && $linkResult): ?>
    <section>
      <h2><?= array_filter($linkResult, fn($r) => isset($r['url'])) ? 'Link hoàn tiền vừa tạo' : 'Kết quả tạo link' ?></h2>
      <?php foreach ($linkResult as $r): ?>
        <?php if (!empty($r['pending'])): ?>
          <p class="alert info"><strong><?= h($r['origin']) ?>:</strong> Đang tạo link — hệ thống sẽ gửi vào mục Thông báo<?= isGuest($uid) ? '' : ' và Telegram' ?> ngay khi xong (thường vài phút). Bạn không cần dán lại.</p>
        <?php elseif (isset($r['error'])): ?>
          <p class="alert err"><strong><?= h(!empty($r['origin']) ? $r['origin'] : 'Link') ?>:</strong> <?= h($r['error']) ?></p>
        <?php else: ?>
          <div class="atm-card">
            <div class="w-full">
              <div class="atm-label">Link hoàn tiền</div>
              <input type="text" readonly data-select value="<?= h($r['url']) ?>">
              <p class="hint">Mở link này rồi đặt hàng trong cùng phiên để được ghi nhận.</p>
              <div class="row-actions">
                <button type="button" data-copy="<?= h($r['url']) ?>">Sao chép</button>
                <?= extLink((string)$r['url'], 'Mở Shopee') ?>
              </div>
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <?php if ($nLinks === 0): ?>
    <section>
      <h2>Bắt đầu sau 3 bước</h2>
      <div class="steps">
        <div class="step"><span class="k">1</span><div>Dán link sản phẩm Shopee vào ô bên dưới.</div></div>
        <div class="step"><span class="k">2</span><div>Mở link hoàn tiền vừa tạo và đặt hàng như bình thường.</div></div>
        <div class="step"><span class="k">3</span><div>Khi Shopee xác nhận đơn, tiền hoàn về ví của bạn.</div></div>
      </div>
    </section>
  <?php endif; ?>

  <section>
    <h2>Dán link Shopee</h2>
    <form method="post" data-once>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="mklink">
      <div class="field">
        <label for="url">Dán link sản phẩm Shopee</label>
        <input id="url" name="url" placeholder="https://shopee.vn/..." required>
      </div>
      <button type="submit">Tạo link hoàn tiền</button>
    </form>
    <p class="hint">Bạn nhận <?= h(number_format((int)$c['customer_share_bps'] / 100, 0, ',', '.')) ?>% hoa hồng. Tối đa <?= (int)$c['max_links_per_day'] ?> link/ngày.</p>
  </section>

  <section>
    <h2>Hoạt động gần đây</h2>
    <?php $recent = q($d, 'SELECT c.id,c.state,c.share,c.created_at FROM cb_conversions c JOIN cb_links l ON l.token=c.token WHERE l.user_id=? ORDER BY c.created_at DESC LIMIT 3', [$uid])->fetchAll(); ?>
    <?php if (!$recent): ?><p class="muted">Chưa có hoạt động nào.</p><?php else: ?>
      <ul class="timeline">
        <?php foreach ($recent as $r): ?>
          <li class="done">Đơn <span class="mono"><?= h($r['id']) ?></span> — <?= stateBadge((string)$r['state']) ?> <span class="muted">(<?= fmtTime((string)$r['created_at']) ?>)</span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'links'): ?>
  <?php
    $per = 20; $p = max(1, (int)($_GET['p'] ?? 1)); $off = ($p - 1) * $per;
    $total = (int)q($d, 'SELECT COUNT(*) FROM cb_links WHERE user_id=?', [$uid])->fetchColumn();
    $links = q($d, 'SELECT token,origin,url,created_at FROM cb_links WHERE user_id=? ORDER BY created_at DESC LIMIT '.$per.' OFFSET '.$off, [$uid])->fetchAll();
    $pages = max(1, (int)ceil($total / $per));
  ?>
  <h1>Link của tôi (<?= $total ?>)</h1>
  <section>
    <?php if (!$links): ?><p class="muted">Bạn chưa có link nào. Vào <a href="dashboard.php?tab=home">Tổng quan</a> để dán link đầu tiên.</p><?php else: ?>
      <table class="responsive">
        <thead><tr><th>Sản phẩm</th><th>Link hoàn tiền</th><th>Thời gian</th><th>Thao tác</th></tr></thead>
        <tbody>
        <?php foreach ($links as $r): ?>
          <tr>
            <td data-label="Sản phẩm"><span class="mono"><?= h(preg_replace('~^https?://~', '', (string)$r['origin'])) ?></span></td>
            <td data-label="Link"><input type="text" readonly data-select value="<?= h($r['url']) ?>"></td>
            <td data-label="Thời gian"><?= fmtTime((string)$r['created_at']) ?></td>
            <td data-label="Thao tác"><div class="row-actions">
              <button type="button" data-copy="<?= h($r['url']) ?>">Sao chép</button>
              <?= extLink((string)$r['url'], 'Mở') ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($pages > 1): ?>
        <p class="muted">Trang <?= $p ?>/<?= $pages ?></p>
        <p><?php if ($p > 1): ?><a href="dashboard.php?tab=links&p=<?= $p - 1 ?>">← Trước</a><?php endif; ?>
        <?php if ($p < $pages): ?> &nbsp; <a href="dashboard.php?tab=links&p=<?= $p + 1 ?>">Sau →</a><?php endif; ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'orders'): ?>
  <?php
    $fstate = (string)($_GET['state'] ?? '');
    $per = 20; $p = max(1, (int)($_GET['p'] ?? 1)); $off = ($p - 1) * $per;
    $cnt = q($d, "SELECT COUNT(*) t, SUM(c.state IN ('pending','validated')) waiting, SUM(c.state='settled') done, SUM(c.state='rejected') rejected FROM cb_conversions c JOIN cb_links l ON l.token=c.token WHERE l.user_id=?", [$uid])->fetch();
    $where = 'l.user_id=?'; $args = [$uid];
    if (in_array($fstate, ['pending', 'validated', 'settled', 'rejected'], true)) { $where .= ' AND c.state=?'; $args[] = $fstate; }
    $total = (int)q($d, "SELECT COUNT(*) FROM cb_conversions c JOIN cb_links l ON l.token=c.token WHERE $where", $args)->fetchColumn();
    $orders = q($d, "SELECT c.id,c.state,c.commission,c.share,c.created_at FROM cb_conversions c JOIN cb_links l ON l.token=c.token WHERE $where ORDER BY c.created_at DESC LIMIT $per OFFSET $off", $args)->fetchAll();
    $pages = max(1, (int)ceil($total / $per));
    $rank = ['pending' => 0, 'validated' => 1, 'settled' => 2];
    $milestones = ['Shopee ghi nhận', 'Đối soát', 'Duyệt tiền'];
    $base = 'dashboard.php?tab=orders';
  ?>
  <h1>Đơn hàng</h1>
  <section>
    <div class="pipeline">
      <div class="pstep done"><div class="dot">1</div><div>Dán link</div></div>
      <div class="pstep done"><div class="dot">2</div><div>Mua qua link</div></div>
      <div class="pstep now"><div class="dot">3</div><div>Shopee xác nhận</div></div>
      <div class="pstep"><div class="dot">4</div><div>Tiền về ví</div></div>
    </div>
    <div class="pill-row">
      <a class="pill<?= $fstate === '' ? ' active' : '' ?>" href="<?= h($base) ?>">Tất cả (<?= (int)($cnt['t'] ?? 0) ?>)</a>
      <a class="pill<?= $fstate === 'pending' ? ' active' : '' ?>" href="<?= h($base) ?>&state=pending">Chờ xử lý (<?= (int)($cnt['waiting'] ?? 0) ?>)</a>
      <a class="pill<?= $fstate === 'settled' ? ' active' : '' ?>" href="<?= h($base) ?>&state=settled">Hoàn tất (<?= (int)($cnt['done'] ?? 0) ?>)</a>
      <a class="pill<?= $fstate === 'rejected' ? ' active' : '' ?>" href="<?= h($base) ?>&state=rejected">Đã hủy (<?= (int)($cnt['rejected'] ?? 0) ?>)</a>
    </div>
    <?php if (!$orders): ?><p class="muted">Không có đơn nào.</p><?php else: ?>
      <?php foreach ($orders as $r): $st = (string)$r['state']; $cur = $rank[$st] ?? -1; ?>
        <div class="card">
          <div class="row-between">
            <span class="mono"><?= h($r['id']) ?></span>
            <?= stateBadge($st) ?>
          </div>
          <div class="stat-grid">
            <div class="stat"><div class="n"><?= h(vnd((int)$r['commission'])) ?></div><div class="t">Hoa hồng</div></div>
            <div class="stat"><div class="n money-pos"><?= h(vnd((int)$r['share'])) ?></div><div class="t">Phần bạn</div></div>
          </div>
          <?php if ($st === 'rejected'): ?>
            <p class="hint">Đơn đã bị hủy, không được cộng tiền.</p>
          <?php else: ?>
            <ul class="timeline">
              <?php foreach ($milestones as $i => $m): ?>
                <li class="<?= h(orderMilestoneClasses($st, $i)) ?>"><?= h($m) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <p class="hint">Ghi nhận lúc <?= fmtTime((string)$r['created_at']) ?></p>
        </div>
      <?php endforeach; ?>
      <?php if ($pages > 1): ?>
        <p class="muted">Trang <?= $p ?>/<?= $pages ?></p>
        <p><?php $qbase = $base.($fstate !== '' ? '&state='.urlencode($fstate) : ''); ?>
        <?php if ($p > 1): ?><a href="<?= h($qbase) ?>&p=<?= $p - 1 ?>">← Trước</a><?php endif; ?>
        <?php if ($p < $pages): ?> &nbsp; <a href="<?= h($qbase) ?>&p=<?= $p + 1 ?>">Sau →</a><?php endif; ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'wallet'): ?>
  <?php
    $bankRow = (string)q($d, 'SELECT bank FROM cb_users WHERE id=?', [$uid])->fetchColumn();
    $bankShown = '';
    if ($bankRow !== '') { try { $bankShown = maskBank(decBank($c, $bankRow)[0]); } catch (Throwable $e) { $bankShown = '(lỗi đọc)'; } }
    $hasBank = $bankRow !== '';
    $held = (int)q($d, "SELECT COALESCE(SUM(amount),0) FROM cb_withdrawals WHERE user_id=? AND state IN ('requested','approved')", [$uid])->fetchColumn();
    $totalIn = (int)q($d, 'SELECT COALESCE(SUM(g.amount),0) FROM cb_ledger g JOIN cb_conversions c ON c.id=g.conversion_id JOIN cb_links l ON l.token=c.token WHERE l.user_id=? AND g.amount>0', [$uid])->fetchColumn();
    $totalIn += (int)q($d, 'SELECT COALESCE(SUM(amount),0) FROM cb_adjustments WHERE user_id=? AND amount>0', [$uid])->fetchColumn();
    $totalOut = (int)q($d, "SELECT COALESCE(SUM(amount),0) FROM cb_withdrawals WHERE user_id=? AND state='paid'", [$uid])->fetchColumn();
    $pendingReq = q($d, "SELECT id,amount,created_at FROM cb_withdrawals WHERE user_id=? AND state='requested' ORDER BY created_at DESC LIMIT 1", [$uid])->fetch();
    $wds = q($d, 'SELECT id,amount,state,reference,created_at FROM cb_withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 20', [$uid])->fetchAll();
    $adjs = q($d, 'SELECT amount,reason,created_at FROM cb_adjustments WHERE user_id=? ORDER BY id DESC LIMIT 20', [$uid])->fetchAll();
  ?>
  <h1>Ví của tôi</h1>
  <div class="hero">
    <div class="hero-label">Khả dụng</div>
    <div class="big"><?= h(vnd((int)$bal['available'])) ?></div>
    <div class="hero-sub"><span>Đang chờ: <?= h(vnd((int)$bal['pending'])) ?></span><span>Đang rút: <?= h(vnd($held)) ?></span></div>
    <p class="row-actions"><a class="btn" href="#rut">Rút tiền</a></p>
  </div>
  <div class="stat-grid">
    <div class="stat"><div class="n money-pos">+<?= h(vnd($totalIn)) ?></div><div class="t">Tổng hoa hồng nhận</div></div>
    <div class="stat"><div class="n money-neg">−<?= h(vnd($totalOut)) ?></div><div class="t">Tổng đã rút</div></div>
  </div>

  <?php if (isGuest($uid)): ?>
  <section id="rut">
    <h2>Nhận hoa hồng</h2>
    <p>Hoa hồng của các đơn mua qua link bạn tạo đang được giữ cho trình duyệt này. Đăng nhập Telegram để liên kết số tiền vào tài khoản, thêm ngân hàng và rút tiền.</p>
    <p><a class="btn" href="index.php?link=1">Đăng nhập Telegram để liên kết</a></p>
  </section>
  <?php else: ?>
  <section>
    <h2>1. Tài khoản nhận tiền</h2>
    <div class="atm-card">
      <div>
        <div class="atm-label">Tài khoản nhận tiền</div>
        <div class="atm-value"><?= $bankShown !== '' ? h($bankShown) : 'Chưa thiết lập' ?></div>
      </div>
    </div>
    <form method="post" data-once>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="bank">
      <div class="field"><label for="bank_name">Ngân hàng</label><input id="bank_name" name="bank_name" placeholder="VD: Vietcombank" required></div>
      <div class="field"><label for="bank_acct">Số tài khoản</label><input id="bank_acct" name="bank_acct" placeholder="VD: 0123456789" required></div>
      <div class="field"><label for="bank_holder">Tên chủ tài khoản</label><input id="bank_holder" name="bank_holder" placeholder="VD: NGUYEN VAN A" required></div>
      <button type="submit">Lưu</button>
    </form>
  </section>
  <section id="rut">
    <h2>2. Rút tiền</h2>
    <?php if ($pendingReq): ?>
      <div class="card card--wait">
        <span class="label">Yêu cầu đang chờ duyệt</span>
        <strong><?= h(vnd((int)$pendingReq['amount'])) ?></strong>
        <span class="sub">Mã <span class="mono"><?= h($pendingReq['id']) ?></span> — gửi lúc <?= fmtTime((string)$pendingReq['created_at']) ?></span>
      </div>
    <?php elseif (!$hasBank): ?>
      <p class="alert err">Thêm tài khoản nhận tiền ở bước 1 trước.</p>
    <?php else: ?>
      <p class="muted">Khả dụng: <strong class="money-pos"><?= h(vnd((int)$bal['available'])) ?></strong> — tối thiểu <?= h(vnd((int)$c['min_withdraw_vnd'])) ?>.</p>
      <form method="post" data-once>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="withdraw">
        <div class="field"><label for="amount">Số tiền muốn rút</label><input id="amount" name="amount" inputmode="numeric" placeholder="Nhập số tiền..." required></div>
        <button type="submit">Tạo yêu cầu rút</button>
      </form>
      <p class="row-actions">
        <button type="button" class="ghost" data-fill="50000">50.000</button>
        <button type="button" class="ghost" data-fill="100000">100.000</button>
        <button type="button" class="ghost" data-fill="<?= (int)$bal['available'] ?>">Tất cả</button>
      </p>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <section>
    <h2>Lịch sử yêu cầu rút</h2>
    <?php if (!$wds): ?><p class="muted">Chưa có yêu cầu nào.</p><?php else: ?>
      <table class="responsive"><thead><tr><th>Mã</th><th>Số tiền</th><th>Trạng thái</th><th>Tham chiếu</th><th>Thời gian</th></tr></thead><tbody>
      <?php foreach ($wds as $r): ?>
        <tr><td data-label="Mã"><span class="mono"><?= h($r['id']) ?></span></td><td data-label="Số tiền"><?= h(vnd((int)$r['amount'])) ?></td><td data-label="Trạng thái"><?= stateBadge((string)$r['state']) ?></td><td data-label="Tham chiếu"><span class="mono"><?= h($r['reference']) ?></span></td><td data-label="Thời gian"><?= fmtTime((string)$r['created_at']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
  </section>
  <section>
    <h2>Lịch sử biến động số dư</h2>
    <?php if (!$adjs): ?><p class="muted">Chưa có điều chỉnh nào.</p><?php else: ?>
      <table class="responsive"><thead><tr><th>Số tiền</th><th>Lý do</th><th>Thời gian</th></tr></thead><tbody>
      <?php foreach ($adjs as $r): $a = (int)$r['amount']; ?>
        <tr><td data-label="Số tiền"><span class="<?= $a >= 0 ? 'money-pos' : 'money-neg' ?>"><?= h(($a > 0 ? '+' : '').vnd($a)) ?></span></td><td data-label="Lý do"><?= h($r['reason']) ?></td><td data-label="Thời gian"><?= fmtTime((string)$r['created_at']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'profile'): ?>
  <?php
    $firstSeen = (string)q($d, 'SELECT first_seen FROM cb_users WHERE id=?', [$uid])->fetchColumn();
    $nOrders = (int)q($d, 'SELECT COUNT(*) FROM cb_conversions c JOIN cb_links l ON l.token=c.token WHERE l.user_id=?', [$uid])->fetchColumn();
    $earned = (int)q($d, "SELECT COALESCE(SUM(c.share),0) FROM cb_conversions c JOIN cb_links l ON l.token=c.token WHERE l.user_id=? AND c.state='settled'", [$uid])->fetchColumn();
    [$rankName, $rankCls] = webRank($nOrders);
    $initial = mb_strtoupper(mb_substr(web_name(), 0, 1));
  ?>
  <h1>Hồ sơ</h1>
  <section>
    <div class="profile-head">
      <div class="avatar"><?= h($initial) ?></div>
      <div>
        <div class="row-between"><strong><?= h(web_name()) ?></strong><span class="rank <?= h($rankCls) ?>"><?= h($rankName) ?></span></div>
        <div class="muted"><span class="mono"><?= h($uid) ?></span></div>
        <div class="hint">Tham gia: <?= $firstSeen !== '' ? fmtTime($firstSeen) : '—' ?></div>
      </div>
    </div>
  </section>
  <div class="stat-grid">
    <div class="stat"><div class="n money-pos"><?= h(vnd($earned)) ?></div><div class="t">Đã nhận</div></div>
    <div class="stat"><div class="n"><?= $nOrders ?></div><div class="t">Tổng đơn</div></div>
    <div class="stat"><div class="n"><?= h(vnd((int)$bal['available'])) ?></div><div class="t">Khả dụng</div></div>
  </div>
  <section>
    <h2>Cài đặt</h2>
    <p class="muted">Giao diện sáng/tối:</p>
    <p><button type="button" class="ghost" data-theme-toggle aria-pressed="false">Đổi giao diện</button></p>
    <p class="muted">Thông báo trình duyệt (chỉ khi trang còn mở):</p>
    <p><button type="button" class="ghost" data-enable-notif>Bật thông báo trên trình duyệt này</button></p>
    <p class="muted">Cần hỗ trợ? Xem <a href="dashboard.php?tab=help">Trợ giúp</a>.</p>
  </section>

<?php elseif ($tab === 'help'): ?>
  <?php
    $supportEmail = setting($d, 'support_email', '');
    $bot = tg_bot_username($c);
  ?>
  <h1>Trợ giúp</h1>
  <div class="hero">
    <div class="big">Cần giúp đỡ?</div>
    <p>Chúng tôi luôn sẵn sàng hỗ trợ bạn. Xem câu hỏi thường gặp hoặc liên hệ bên dưới.</p>
  </div>
  <section>
    <h2>Kênh hỗ trợ</h2>
    <div class="support-grid">
      <?php if ($supportEmail !== ''): ?>
        <div class="support-card"><div class="ic">✉️</div><div><strong>Email</strong></div><div class="hint"><?= h($supportEmail) ?></div></div>
      <?php endif; ?>
      <?php if ($bot !== ''): ?>
        <div class="support-card"><div class="ic">💬</div><div><strong>Telegram</strong></div><div class="hint"><a href="https://t.me/<?= h($bot) ?>" target="_blank" rel="noopener noreferrer">@<?= h($bot) ?></a></div></div>
      <?php endif; ?>
      <div class="support-card"><div class="ic">🕒</div><div><strong>Giờ hỗ trợ</strong></div><div class="hint">08:00 – 24:00 hằng ngày</div></div>
    </div>
  </section>
  <section>
    <h2>Chính sách bảo hành &amp; Hoàn tiền</h2>
    <pre class="policy"><?= h(currentPolicy($d)) ?></pre>
  </section>
  <section>
    <h2>Câu hỏi thường gặp</h2>
    <?php
      $faq = setting($d, 'faq', "Làm sao để nhận hoàn tiền?\nDán link sản phẩm Shopee ở mục Tổng quan, mở link hoàn tiền rồi đặt hàng trong cùng phiên.\n\nKhi nào tiền về?\nKhi Shopee xác nhận đơn ở trạng thái hoàn tất.\n\nBao lâu được rút?\nAdmin duyệt và chuyển khoản tay. Bot không tự chuyển tiền.\n\nĐơn bị hủy thì sao?\nĐơn hủy/hoàn không được cộng; nếu đã cộng sẽ bị trừ lại.");
      echo '<ol class="faqlist">';
      foreach (preg_split('/\n\s*\n/', trim($faq)) as $block) {
          $block = trim($block);
          if ($block === '') continue;
          $lines = explode("\n", $block, 2);
          $qtext = trim($lines[0]); $atext = trim($lines[1] ?? '');
          echo '<li><details><summary>'.h($qtext).'</summary><p>'.nl2br(h($atext)).'</p></details></li>';
      }
      echo '</ol>';
    ?>
  </section>
  <section>
    <h2>Thông báo trình duyệt</h2>
    <p class="muted">Bật để nhận thông báo khi có tiền về ngay trên trình duyệt (chỉ khi trang còn mở; khi đóng, bạn vẫn nhận tin qua Telegram).</p>
    <p><button type="button" class="ghost" data-enable-notif>Bật thông báo trên trình duyệt này</button></p>
  </section>

<?php elseif ($tab === 'deals'): ?>
  <?php
    $dealsRaw = setting($d, 'deals_content', "Deal cộng đồng\nAdmin chưa cập nhật nội dung deal. Đây là mục thử nghiệm đang nghiên cứu.");
  ?>
  <h1>Deal cộng đồng</h1>
  <section>
    <p class="muted">Khu vực deal thử nghiệm — nội dung do admin cập nhật ở trang Quản trị.</p>
    <?php foreach (preg_split('/\n\s*\n/', trim($dealsRaw)) as $block):
      $block = trim($block); if ($block === '') continue;
      $lines = explode("\n", $block, 2);
    ?>
      <div class="card">
        <strong><?= h(trim($lines[0])) ?></strong>
        <?php if (isset($lines[1]) && trim($lines[1]) !== ''): ?><div class="muted"><?= nl2br(h(trim($lines[1]))) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>

<?php elseif ($tab === 'referral'): ?>
  <?php
    $refInfo = referralLinkInfo($uid, tg_bot_username($c));
    $refLink = $refInfo['link'];
  ?>
  <h1>Mời bạn bè</h1>
  <section>
    <p class="muted">Chia sẻ link của bạn. Bạn bè mở link, nhắn bot để nhận mã và admin duyệt.</p>
    <?php if ($refInfo['is_guest']): ?>
      <div class="alert info">
        <strong>Bạn đang dùng chế độ khách.</strong> Vui lòng <a class="btn" href="index.php?link=1">Đăng nhập Telegram</a> để tạo link mời bạn bè và nhận hoa hồng giới thiệu.
      </div>
    <?php elseif ($refInfo['error'] !== ''): ?>
      <p class="alert err"><?= h($refInfo['error']) ?></p>
    <?php else: ?>
      <div class="atm-card">
        <div class="w-full">
          <div class="atm-label">Link mời của bạn</div>
          <input type="text" readonly data-select value="<?= h($refLink) ?>">
          <div class="row-actions">
            <button type="button" data-copy="<?= h($refLink) ?>">Sao chép</button>
            <?= extLink($refLink, 'Mở Telegram') ?>
          </div>
        </div>
      </div>
      <p class="hint">Đây là mục thử nghiệm đang nghiên cứu; luật thưởng giới thiệu chưa được áp dụng.</p>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'notifications'): ?>
  <?php $notes = q($d, 'SELECT id,kind,title,body,amount,created_at,read_at FROM cb_notifications WHERE user_id=? ORDER BY id DESC LIMIT 50', [$uid])->fetchAll(); ?>
  <h1>Thông báo</h1>
  <section>
    <?php if ($notes && unreadCount($c) > 0): ?>
      <form method="post" class="inline" data-once>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="markread">
        <button type="submit" class="ghost">Đánh dấu tất cả đã đọc</button>
      </form>
    <?php endif; ?>
    <?php if (!$notes): ?><p class="muted">Chưa có thông báo nào.</p><?php else: ?>
      <ul class="timeline">
        <?php foreach ($notes as $r): $unreadRow = $r['read_at'] === null; ?>
          <li class="<?= $unreadRow ? 'now' : 'done' ?>">
            <strong><?= h($r['title']) ?></strong><?= $unreadRow ? ' <span class="badge badge-info">Mới</span>' : '' ?>
            <div class="muted"><?= h($r['body']) ?></div>
            <div class="hint"><?= fmtTime((string)$r['created_at']) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php web_footer();
