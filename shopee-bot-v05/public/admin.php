<?php
declare(strict_types=1);
require __DIR__.'/_boot.php';
$c = cfg(); web_boot();
require_login();
if (!web_is_admin($c)) {
    http_response_code(403);
    web_header('Không có quyền', $c);
    echo '<p class="alert err">Chỉ quản trị viên truy cập được trang này.</p>';
    web_footer(); exit;
}
$d = db($c); $admin = (string)web_user();

// CSV export (admin only) — must run before any output.
if (($_GET['export'] ?? '') === 'withdrawals') {
    $rows = q($d, 'SELECT id,user_id,amount,state,reference,created_at FROM cb_withdrawals ORDER BY created_at DESC LIMIT 5000')->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="withdrawals.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['id', 'user_id', 'amount', 'state', 'reference', 'created_at']);
    $safe = fn($v) => (is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false) ? "'".$v : $v;
    foreach ($rows as $r) fputcsv($out, array_map($safe, [$r['id'], $r['user_id'], $r['amount'], $r['state'], $r['reference'], $r['created_at']]));
    fclose($out); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $act = (string)($_POST['action'] ?? '');
    try {
        if (in_array($act, ['approve', 'reject', 'cancel', 'paid'], true)) {
            $wid = (string)($_POST['wid'] ?? '');
            if (!preg_match('/^[a-f0-9]{16}$/', $wid)) throw new InvalidArgumentException('Mã yêu cầu không hợp lệ.');
            $ref = $act === 'paid' ? trim((string)($_POST['ref'] ?? '')) : '';
            atomic($d, function () use ($d, $c, $wid, $act, $ref, $admin) { transition($d, $c, $wid, $act, $ref, $admin); });
            flash_set('', 'Đã cập nhật yêu cầu '.$wid.' → '.adminWithdrawActionLabel($act).'.');
        } elseif ($act === 'credit' || $act === 'debit') {
            $target = resolveUserKey((string)($_POST['user'] ?? ''));
            $amount = (string)($_POST['amount'] ?? '');
            if (!ctype_digit($amount) || strlen($amount) > 12 || (int)$amount <= 0) throw new InvalidArgumentException('Số tiền không hợp lệ.');
            $amt = (int)$amount; if ($act === 'debit') $amt = -$amt;
            atomic($d, function () use ($d, $c, $admin, $target, $amt) {
                $notice = adjust($d, $c, $admin, $target, $amt, (string)($_POST['reason'] ?? ''));
                [$nch, $ntgt] = routeFor($target);
                queue($d, 'web:adj:'.bin2hex(random_bytes(8)).':'.$target, $nch, (string)$ntgt, $notice);
            });
            flash_set('', 'Đã '.($act === 'credit' ? 'cộng' : 'trừ').' '.vnd(abs($amt)).' cho '.$target.'.');
        } elseif ($act === 'setpolicy') {
            $policy = trim((string)($_POST['policy'] ?? ''));
            if ($policy === '') throw new InvalidArgumentException('Nội dung chính sách trống.');
            setSetting($d, 'policy', mb_substr($policy, 0, 4000));
            audit($d, $admin, 'policy.update', '', mb_substr($policy, 0, 100));
            flash_set('', 'Đã cập nhật chính sách bảo hành.');
        } elseif ($act === 'setflags') {
            setSetting($d, 'feature_deals', !empty($_POST['feature_deals']) ? '1' : '0');
            setSetting($d, 'feature_referral', !empty($_POST['feature_referral']) ? '1' : '0');
            if (isset($_POST['deals_content'])) setSetting($d, 'deals_content', mb_substr((string)$_POST['deals_content'], 0, 4000));
            audit($d, $admin, 'flags.update', '', 'deals='.(!empty($_POST['feature_deals']) ? '1' : '0').' referral='.(!empty($_POST['feature_referral']) ? '1' : '0'));
            flash_set('', 'Đã cập nhật tính năng.');
        } elseif ($act === 'setcookie') {
            $ck = trim((string)($_POST['cookie'] ?? ''));
            if (strlen($ck) < 10) throw new InvalidArgumentException('Cookie quá ngắn.');
            $blob = encBlob($c, $ck, 12000);
            setSetting($d, 'affiliate_cookie', $blob);
            if (decBlob($c, setting($d, 'affiliate_cookie', '')) !== $ck) { setSetting($d, 'affiliate_cookie', ''); throw new InvalidArgumentException('Không lưu được cookie (đọc lại không khớp). Hãy thử lại hoặc chỉ dán cookie phiên cần thiết.'); }
            setSetting($d, 'affiliate_cookie_at', date('Y-m-d H:i:s'));
            audit($d, $admin, 'source.cookie.set', '', 'len='.strlen($ck));
            flash_set('', 'Đã lưu cookie nguồn (mã hóa).');
        } elseif ($act === 'testlink') {
            $origin = canonical(trim((string)($_POST['link'] ?? '')));
            try {
                $mode = $c['link_mode'];
                if ($mode === 'relay' || $mode === 'an_redir') throw new RuntimeException('Chế độ '.$mode.': hãy thử bằng cách dán link trên trang khách (Tổng quan).');
                $u = $mode === 'open_api' ? openApiLink($c, $origin, 'admintest') : customLink($d, $c, $origin, 'admintest');
                setSetting($d, 'custom_link_error', '');
                flash_set('', ($mode === 'open_api' ? 'Open API' : 'Custom Link').' OK: '.$u);
            } catch (RuntimeException $e) {
                setSetting($d, 'custom_link_error', date('Y-m-d H:i:s').' — '.mb_substr($e->getMessage(), 0, 400));
                flash_set($e->getMessage(), '');
            }
        } elseif ($act === 'clearcookie') {
            setSetting($d, 'affiliate_cookie', '');
            audit($d, $admin, 'source.cookie.clear', '');
            flash_set('', 'Đã xóa cookie nguồn.');
        } elseif ($act === 'probe') {
            $link = trim((string)($_POST['link'] ?? ''));
            $origin = canonical($link); // throws a Vietnamese message if not a Shopee product link
            $subId = bin2hex(random_bytes(5));
            $res = probeRedir($c, $origin, $subId);
            $_SESSION['probe'] = $res + ['origin' => $origin, 'sub_id' => $subId];
            [$pErr, $pOk] = probeFlash($res);
            flash_set($pErr, $pOk);
        } elseif ($act === 'fetchreport') {
            $to = time(); $from = $to - 30 * 86400;
            $rep = fetchAffiliateReport($d, $c, $from, $to, 1, 20);
            $total = (int)($rep['data']['total_count'] ?? 0);
            $_SESSION['report_probe'] = ['at' => $to, 'total' => $total, 'json' => mb_substr((string)json_encode($rep, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 0, 8000)];
            audit($d, $admin, 'source.fetch', '', 'total='.$total);
            flash_set('', 'Đã tải báo cáo. Tổng đơn: '.$total.'.');
        } elseif ($act === 'syncdry' || $act === 'syncgo') {
            $to = time(); $from = $to - 30 * 86400; $dry = $act === 'syncdry';
            $sum = syncFromReport($d, $c, $from, $to, $dry);
            $_SESSION['sync_sum'] = $sum + ['dry' => $dry, 'at' => $to];
            audit($d, $admin, 'source.sync', '', 'seen='.$sum['seen'].' matched='.$sum['matched'].' imported='.$sum['imported'].' skipped='.$sum['skipped'].' dry='.($dry ? 1 : 0));
            [$sErr, $sOk] = syncFlash($sum, $dry);
            flash_set($sErr, $sOk);
        } else {
            throw new InvalidArgumentException('Thao tác không hợp lệ.');
        }
    } catch (InvalidArgumentException $e) { flash_set($e->getMessage(), ''); }
    catch (Throwable $e) { flash_set('Có lỗi, thử lại sau.', ''); }
    header('Location: admin.php'); exit; // PRG: F5 will not repeat credit/debit
}
[$err, $ok] = flash_take();

$cnt = q($d, "SELECT
    SUM(state='requested') req_cnt, COALESCE(SUM(CASE WHEN state='requested' THEN amount END),0) req_sum,
    SUM(state='approved') app_cnt, COALESCE(SUM(CASE WHEN state='approved' THEN amount END),0) app_sum
  FROM cb_withdrawals")->fetch();
$rows = q($d, "SELECT id,user_id,amount,bank,state,reference,created_at FROM cb_withdrawals WHERE state IN ('requested','approved') ORDER BY created_at ASC LIMIT 100")->fetchAll();
$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1)); $per = 20; $offset = ($page - 1) * $per;
if ($search !== '' && preg_match('/^[A-Za-z0-9_:.\-]{1,80}$/', $search)) {
    $cust = q($d, 'SELECT id, first_seen FROM cb_users WHERE id LIKE ? ORDER BY first_seen DESC LIMIT '.$per.' OFFSET '.$offset, ['%'.$search.'%'])->fetchAll();
    $total = (int)q($d, 'SELECT COUNT(*) FROM cb_users WHERE id LIKE ?', ['%'.$search.'%'])->fetchColumn();
} else {
    $cust = q($d, 'SELECT id, first_seen FROM cb_users ORDER BY first_seen DESC LIMIT '.$per.' OFFSET '.$offset)->fetchAll();
    $total = (int)q($d, 'SELECT COUNT(*) FROM cb_users')->fetchColumn();
}
$custBals = balancesMany($d, array_map(fn($r) => (string)$r['id'], $cust));
$pages = max(1, (int)ceil($total / $per));
$logs = q($d, 'SELECT at,actor,action,ref,detail FROM cb_audit ORDER BY id DESC LIMIT 50')->fetchAll();
web_header('Quản trị', $c, 'admin');
flash($err, $ok);
?>
<h1>Quản trị</h1>
<div class="stat-grid">
  <div class="stat"><div class="n"><?= (int)($cnt['req_cnt'] ?? 0) ?></div><div class="t">Chờ duyệt</div></div>
  <div class="stat"><div class="n"><?= (int)($cnt['app_cnt'] ?? 0) ?></div><div class="t">Đã duyệt chờ trả</div></div>
  <div class="stat"><div class="n"><?= h(vnd((int)($cnt['req_sum'] ?? 0) + (int)($cnt['app_sum'] ?? 0))) ?></div><div class="t">Tổng tiền đang chờ</div></div>
</div>

<section>
  <h2>Yêu cầu rút cần xử lý</h2>
  <p class="muted">Duyệt → chuyển khoản ngân hàng thủ công → ghi nhận mã tham chiếu giao dịch. Bot không tự chuyển tiền. <a href="admin.php?export=withdrawals">Xuất CSV</a></p>
  <?php if (!$rows): ?><p class="muted">Không có yêu cầu chờ.</p><?php endif; ?>
  <?php foreach ($rows as $r):
      try { $bank = maskBank(decBank($c, (string)$r['bank'])[0]); } catch (Throwable $e) { $bank = '(lỗi đọc)'; }
  ?>
    <div class="card">
      <div class="row-between">
        <strong class="money"><?= h(vnd((int)$r['amount'])) ?></strong>
        <?= stateBadge((string)$r['state']) ?>
      </div>
      <div class="muted">Khách: <span class="mono"><?= h($r['user_id']) ?></span></div>
      <div>Nhận tiền: <?= h($bank) ?></div>
      <div class="hint">Mã <span class="mono"><?= h($r['id']) ?></span> — gửi <?= fmtTime((string)$r['created_at']) ?></div>
      <div class="row-actions">
        <?php if ($r['state'] === 'requested'): ?>
          <form method="post" class="inline" data-once>
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="wid" value="<?= h($r['id']) ?>"><input type="hidden" name="action" value="approve">
            <button type="submit">Duyệt</button>
          </form>
          <form method="post" class="inline" data-confirm="Từ chối yêu cầu <?= h(vnd((int)$r['amount'])) ?> của <?= h($r['user_id']) ?>?" data-once>
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="wid" value="<?= h($r['id']) ?>"><input type="hidden" name="action" value="reject">
            <button type="submit" class="danger">Từ chối</button>
          </form>
        <?php elseif ($r['state'] === 'approved'): ?>
          <form method="post" class="inline" data-once>
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="wid" value="<?= h($r['id']) ?>"><input type="hidden" name="action" value="paid">
            <input name="ref" placeholder="mã tham chiếu" required>
            <button type="submit">Ghi đã trả</button>
          </form>
          <form method="post" class="inline" data-confirm="Hủy yêu cầu <?= h($r['id']) ?>?" data-once>
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="wid" value="<?= h($r['id']) ?>"><input type="hidden" name="action" value="cancel">
            <button type="submit" class="danger">Hủy</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>

<section>
  <h2>Cộng / Trừ tiền thủ công (bù đơn sót, sửa sai)</h2>
  <p class="muted">Dùng khi hoa hồng chưa kịp cập nhật. Mọi thao tác được ghi nhật ký và báo cho khách kèm lý do.</p>
  <form method="post" data-once>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <div class="field"><label for="user">Khách</label><input id="user" name="user" placeholder="tg:ID hoặc ID số" required></div>
    <div class="field"><label for="amount2">Số tiền</label><input id="amount2" name="amount" inputmode="numeric" placeholder="số tiền VNĐ" required></div>
    <div class="field"><label for="reason">Lý do</label><input id="reason" name="reason" placeholder="vd: bù đơn sót" maxlength="300"></div>
    <div class="row-actions">
      <button type="submit" name="action" value="credit">Cộng tiền</button>
      <button type="submit" name="action" value="debit" class="danger" data-confirm="Trừ tiền ví khách này?">Trừ tiền</button>
    </div>
  </form>
</section>

<section>
  <h2>Chính sách bảo hành</h2>
  <form method="post" data-once>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="setpolicy">
    <textarea name="policy" rows="6" class="w-full"><?= h(currentPolicy($d)) ?></textarea>
    <button type="submit">Lưu chính sách</button>
  </form>
</section>

<section>
  <h2>Tính năng (thử nghiệm)</h2>
  <p class="muted">Bật/tắt tính năng kiểu Caffiliate. Tắt thì mục tương ứng ẩn khỏi web khách.</p>
  <form method="post" data-once>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="setflags">
    <div class="flags">
    <label class="flag"><input type="checkbox" name="feature_deals" value="1"<?= setting($d, 'feature_deals', '0') === '1' ? ' checked' : '' ?>> Deal cộng đồng</label>
    <label class="flag"><input type="checkbox" name="feature_referral" value="1"<?= setting($d, 'feature_referral', '0') === '1' ? ' checked' : '' ?>> Mời bạn bè</label>
    </div>
    <div class="field">
      <label for="deals_content">Nội dung Deal (mỗi deal cách nhau 1 dòng trống)</label>
      <textarea id="deals_content" name="deals_content" rows="5" class="w-full"><?= h(setting($d, 'deals_content', '')) ?></textarea>
    </div>
    <button type="submit">Lưu tính năng</button>
  </form>
</section>

<section>
  <h2>Nguồn chuyển đổi (cookie)</h2>
  <?php if (($c['link_mode'] ?? '') === 'relay'): ?>
    <?php $rSeen = setting($d, 'relay_seen_at', ''); $rSt = setting($d, 'relay_status', ''); $rAge = $rSeen !== '' ? time() - (int)strtotime($rSeen) : PHP_INT_MAX; ?>
    <p class="alert <?= $rAge < 120 && !str_starts_with($rSt, 'paused') ? 'ok' : 'err' ?>">Chế độ tạo link: <strong>relay (extension)</strong> — lần cuối extension liên lạc: <?= $rSeen !== '' ? h($rSeen) : 'chưa bao giờ' ?><?= $rSt !== '' ? ' · trạng thái: '.h($rSt) : '' ?><?= $rAge >= 120 ? ' · Mở Chrome có cài extension và một tab affiliate.shopee.vn đang đăng nhập.' : '' ?></p>
  <?php endif; ?>
  <p class="muted">Dán cookie phiên đăng nhập affiliate để hệ thống đọc báo cáo chuyển đổi. Cookie được mã hóa, không hiển thị lại. Hết hạn thì dán lại.</p>
  <p>Trạng thái:
    <?php $ckAt = setting($d, 'affiliate_cookie_at', ''); ?>
    <?= setting($d, 'affiliate_cookie', '') !== '' ? 'đã có (cập nhật ' . h($ckAt) . ')' : 'chưa có' ?>
  </p>
  <form method="post" data-once>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="setcookie">
    <div class="field">
      <label for="cookie">Cookie phiên (dán nguyên chuỗi, vd SPC_F=...; SPC_U=...)</label>
      <textarea id="cookie" name="cookie" rows="3" class="w-full" placeholder="SPC_F=...; SPC_U=...; ..." required></textarea>
    </div>
    <button type="submit">Lưu cookie</button>
  </form>
  <?php $clErr = setting($d, 'custom_link_error', ''); if ($clErr !== ''): ?>
    <p class="alert err">Lỗi tạo link gần nhất: <?= h($clErr) ?></p>
  <?php endif; ?>
  <form method="post" data-once>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="testlink">
    <div class="field"><label for="testlink">Thử tạo link Custom Link (dán link sản phẩm)</label><input id="testlink" name="link" placeholder="https://shopee.vn/product/..." required></div>
    <button type="submit">Thử tạo link</button>
  </form>
  <?php if (setting($d, 'affiliate_cookie', '') !== ''): ?>
  <form method="post" data-once>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="clearcookie">
    <button type="submit" class="danger" data-confirm="Xóa cookie nguồn?">Xóa cookie</button>
  </form>
  <?php endif; ?>
  <p class="hint">⚠️ Tự động hóa truy cập bảng điều khiển affiliate có thể vi phạm điều khoản Shopee và rủi ro khóa tài khoản. Ưu tiên Open API chính thức khi có quyền. Bộ lấy đơn tự động đang chờ cấu hình endpoint thật.</p>
</section>

<section id="probe">
  <h2>Kiểm tra link (sub-id)</h2>
  <p class="muted">Dán link sản phẩm Shopee để hệ thống tạo thử link affiliate và xác nhận Shopee nhận đúng sub-id (không cần đơn thật).</p>
  <?php $probe = $_SESSION['probe'] ?? null; unset($_SESSION['probe']); ?>
  <form method="post" data-once>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="probe">
    <div class="field"><label for="link">Link sản phẩm Shopee</label><input id="link" name="link" placeholder="https://shopee.vn/..." required></div>
    <button type="submit">Kiểm tra</button>
  </form>
  <?php if (is_array($probe)): ?>
    <?php if (isset($probe['error'])): ?>
      <p class="alert err"><?= h($probe['error']) ?></p>
    <?php else: ?>
      <div class="card">
        <p>Sản phẩm: <span class="mono"><?= h($probe['origin']) ?></span></p>
        <p>HTTP <?= (int)$probe['status'] ?> — affiliate id Shopee nhận: <span class="mono"><?= h($probe['mmp_pid']) ?></span></p>
        <p>sub-id gửi đi: <span class="mono"><?= h($probe['sub_id']) ?></span></p>
        <p>utm_content Shopee trả về: <span class="mono"><?= h($probe['utm_content']) ?></span></p>
        <p><?= $probe['match'] ? '<span class="badge badge-ok"><span class="dot"></span>Khớp — tracking OK</span>' : '<span class="badge badge-err"><span class="dot"></span>Không khớp</span>' ?></p>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section id="report">
  <h2>Báo cáo chuyển đổi (thử)</h2>
  <p class="muted">Tải báo cáo 30 ngày gần nhất trực tiếp từ affiliate.shopee.vn bằng cookie đã lưu. Dùng để kiểm cookie/endpoint; khi có đơn thật sẽ thấy danh sách và tên cột.</p>
  <form method="post" data-once>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="fetchreport">
    <button type="submit">Tải báo cáo 30 ngày</button>
  </form>
  <?php $rp = $_SESSION['report_probe'] ?? null; unset($_SESSION['report_probe']); ?>
  <?php if (is_array($rp)): ?>
    <p>Lúc <?= h(date('d/m H:i', (int)$rp['at'])) ?> — tổng đơn: <strong><?= (int)$rp['total'] ?></strong>. Dữ liệu thô:</p>
    <pre class="policy"><?= h((string)$rp['json']) ?></pre>
  <?php endif; ?>
</section>

<section id="sync">
  <h2>Đồng bộ từ báo cáo</h2>
  <p class="muted">Đọc báo cáo 30 ngày, map cột sub-id → khách, mã đơn, trạng thái, hoa hồng. <strong>Xem trước</strong> trước rồi mới <strong>Đồng bộ</strong>. Chỉ nhập dòng khớp token đã tạo; dòng lạ/lỗi bị bỏ (không đoán).</p>
  <div class="row-actions">
    <form method="post" class="inline" data-once>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="syncdry">
      <button type="submit">Xem trước</button>
    </form>
    <form method="post" class="inline" data-once data-confirm="Nhập các dòng khớp vào sổ hoa hồng?">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="syncgo">
      <button type="submit" class="danger">Đồng bộ (nhập)</button>
    </form>
  </div>
  <?php $ss = $_SESSION['sync_sum'] ?? null; unset($_SESSION['sync_sum']); ?>
  <?php if (is_array($ss)): ?>
    <p><?= $ss['dry'] ? 'Xem trước' : 'Đã nhập' ?> lúc <?= h(date('d/m H:i', (int)$ss['at'])) ?> — thấy <?= (int)$ss['seen'] ?>, khớp <?= (int)$ss['matched'] ?>, nhập <?= (int)$ss['imported'] ?>, bỏ <?= (int)$ss['skipped'] ?>.</p>
    <?php if ($ss['rows']): ?>
      <table class="responsive"><thead><tr><th>Token</th><th>Mã đơn</th><th>Trạng thái</th><th>Tiền</th><th>Link</th><th>Kết quả</th></tr></thead><tbody>
      <?php foreach ($ss['rows'] as $r): ?>
        <tr><td data-label="Token"><span class="mono"><?= h($r['token'] !== '' ? $r['token'] : '—') ?></span></td><td data-label="Mã đơn"><span class="mono"><?= h($r['cid'] !== '' ? $r['cid'] : '—') ?></span></td><td data-label="Trạng thái"><?= h((string)($r['state'] ?? '?')) ?></td><td data-label="Tiền"><?= $r['amount'] === null ? '—' : h(vnd((int)$r['amount'])) ?></td><td data-label="Link"><?= $r['link'] ? '✅' : '❌' ?></td><td data-label="Kết quả"><?= h($r['verdict']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section>
  <h2>Khách hàng (<?= $total ?>)</h2>
  <form method="get">
    <input type="hidden" name="tab" value="customers">
    <div class="field"><label for="q">Tìm theo mã khách</label><input id="q" name="q" value="<?= h($search) ?>" placeholder="tg:... hoặc ID"></div>
    <button type="submit">Tìm</button>
    <?php if ($search !== ''): ?><a class="muted" href="admin.php">Xoá lọc</a><?php endif; ?>
  </form>
  <table class="responsive"><thead><tr><th>Khách</th><th>Khả dụng</th><th>Chờ đối soát</th><th>Tham gia</th></tr></thead><tbody>
  <?php foreach ($cust as $u): $b = $custBals[(string)$u['id']] ?? ['available' => 0, 'pending' => 0]; ?>
    <tr><td data-label="Khách"><span class="mono"><?= h($u['id']) ?></span></td><td data-label="Khả dụng"><span class="money-pos"><?= h(vnd((int)$b['available'])) ?></span></td><td data-label="Chờ"><?= h(vnd((int)$b['pending'])) ?></td><td data-label="Tham gia"><?= fmtTime((string)$u['first_seen']) ?></td></tr>
  <?php endforeach; if (!$cust): ?><tr><td colspan="4" class="muted">chưa có</td></tr><?php endif; ?>
  </tbody></table>
  <?php if ($pages > 1): $qs = $search !== '' ? 'q='.urlencode($search).'&' : ''; ?>
    <p class="muted">Trang <?= $page ?>/<?= $pages ?></p>
    <p><?php if ($page > 1): ?><a href="admin.php?<?= $qs ?>p=<?= $page - 1 ?>">← Trước</a><?php endif; ?>
    <?php if ($page < $pages): ?> &nbsp; <a href="admin.php?<?= $qs ?>p=<?= $page + 1 ?>">Sau →</a><?php endif; ?></p>
  <?php endif; ?>
</section>

<section>
  <h2>Nhật ký thao tác</h2>
  <table class="responsive"><thead><tr><th>Thời gian</th><th>Người</th><th>Hành động</th><th>Đối tượng</th><th>Chi tiết</th></tr></thead><tbody>
  <?php foreach ($logs as $l): ?>
    <tr><td data-label="Thời gian"><?= fmtTime((string)$l['at']) ?></td><td data-label="Người"><span class="mono"><?= h($l['actor']) ?></span></td><td data-label="Hành động"><?= h($l['action']) ?></td><td data-label="Đối tượng"><span class="mono"><?= h($l['ref']) ?></span></td><td data-label="Chi tiết"><?= h($l['detail']) ?></td></tr>
  <?php endforeach; if (!$logs): ?><tr><td colspan="5" class="muted">chưa có</td></tr><?php endif; ?>
  </tbody></table>
</section>
<?php web_footer();
