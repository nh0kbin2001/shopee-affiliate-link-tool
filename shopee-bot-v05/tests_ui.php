<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

$__core = __DIR__.'/core.php'; if (!is_file($__core)) $__core = __DIR__.'/private/core.php'; require $__core;
$__web = __DIR__.'/web.php'; if (!is_file($__web)) $__web = __DIR__.'/private/web.php'; require $__web;

function expect(bool $ok, string $name): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }
    echo "PASS: $name\n";
}

function expectThrows(callable $fn, string $name, string $expectedClass = InvalidArgumentException::class): void {
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $expectedClass) {
            expect(true, $name);
            return;
        }
        fwrite(STDERR, "FAIL: $name (threw ".get_class($e)." instead of $expectedClass)\n");
        exit(1);
    }
    fwrite(STDERR, "FAIL: $name (did not throw)\n");
    exit(1);
}

echo "=== Starting UI Regression Test Suite ===\n";

// --- 1. Finding #03: Bank validation ---
echo "--- Finding #03: Bank validation ---\n";
expectThrows(fn() => validateBankInputs('', '', ''), 'reject empty bank inputs');
expectThrows(fn() => validateBankInputs('VCB', '', ''), 'reject missing account and holder');
expectThrows(fn() => validateBankInputs('VCB', '123456', ''), 'reject missing holder');
expectThrows(fn() => validateBankInputs('  ', '123456', 'NGUYEN A'), 'reject whitespace bank name');
expect(validateBankInputs('VCB', '123456', 'NGUYEN A') === 'VCB | 123456 | NGUYEN A', 'accept full bank inputs');

// --- 2. Finding #05: Order milestones for settled ---
echo "--- Finding #05: Order milestones ---\n";
expect(orderMilestoneClasses('settled', 0) === 'done', 'settled step 0 is done');
expect(orderMilestoneClasses('settled', 1) === 'done', 'settled step 1 is done');
expect(orderMilestoneClasses('settled', 2) === 'done', 'settled step 2 is done (not now)');
expect(orderMilestoneClasses('validated', 0) === 'done', 'validated step 0 is done');
expect(orderMilestoneClasses('validated', 1) === 'now', 'validated step 1 is now');
expect(orderMilestoneClasses('validated', 2) === '', 'validated step 2 is pending');
expect(orderMilestoneClasses('pending', 0) === 'now', 'pending step 0 is now');

// --- 3. Finding #01: Admin probe flash ---
echo "--- Finding #01: Admin probe flash ---\n";
[$err1, $ok1] = probeFlash(['error' => 'cURL timeout']);
expect($err1 !== '' && $ok1 === '', 'probe curl error returns error flash');
[$err2, $ok2] = probeFlash(['status' => 200, 'match' => false, 'mmp_pid' => '123', 'utm_content' => 'x']);
expect($err2 !== '' && $ok2 === '', 'probe mismatch returns warning/error flash');
[$err3, $ok3] = probeFlash(['status' => 200, 'match' => true, 'mmp_pid' => '123', 'utm_content' => 'sub1']);
expect($err3 === '' && $ok3 !== '', 'probe match returns success flash');

// --- 4. Finding #02: Admin sync flash ---
echo "--- Finding #02: Admin sync flash ---\n";
[$err4, $ok4] = syncFlash(['seen' => 0, 'matched' => 0, 'imported' => 0, 'skipped' => 0], false);
expect($err4 !== '' && $ok4 === '', 'sync 0 seen returns error flash');
[$err5, $ok5] = syncFlash(['seen' => 10, 'matched' => 0, 'imported' => 0, 'skipped' => 10], false);
expect($err5 !== '' && $ok5 === '', 'sync 0 imported non-dry returns error flash');
[$err6, $ok6] = syncFlash(['seen' => 10, 'matched' => 2, 'imported' => 0, 'skipped' => 8], true);
expect($err6 === '' && str_contains($ok6, 'Xem trước'), 'sync dry run returns preview flash');
[$err7, $ok7] = syncFlash(['seen' => 10, 'matched' => 2, 'imported' => 2, 'skipped' => 8], false);
expect($err7 === '' && str_contains($ok7, 'thành công'), 'sync with imported returns success flash');

// --- 5. Finding #04 & Rule: mklink flash for made/failed counts ---
echo "--- Finding #04 & Rule: mklink flash ---\n";
[$err8, $ok8] = mklinkFlash(0, 2);
expect($err8 !== '' && $ok8 === '', 'mklink 0 made returns error flash');
[$err9, $ok9] = mklinkFlash(1, 1);
expect($err9 === '' && str_contains($ok9, '1 link') && str_contains($ok9, '1 link lỗi'), 'partial success shows both counts');
[$err10, $ok10] = mklinkFlash(1, 0);
expect($err10 === '' && $ok10 === 'Đã tạo link hoàn tiền.', 'mklink 1 made returns success flash');
[$err11, $ok11] = mklinkFlash(3, 0);
expect($err11 === '' && $ok11 === 'Đã tạo 3 link hoàn tiền.', 'mklink 3 made returns success count');

[$e12, $o12] = mklinkFlash(0, 0, 2);
expect($e12 === '' && str_contains($o12, '2 link đang được tạo'), 'pending-only is not shown as an error');
[$e13, $o13] = mklinkFlash(1, 1, 1);
expect($e13 === '' && str_contains($o13, 'đang được tạo') && str_contains($o13, '1 link lỗi'), 'mixed made/pending/failed');

// --- 6. Finding #06: Guest referral info ---
echo "--- Finding #06: Guest referral info ---\n";
$refGuest = referralLinkInfo('guest:0123456789abcdef0123456789abcdef', 'bot_shopee');
expect($refGuest['is_guest'] === true && $refGuest['link'] === '', 'guest cannot generate referral link');
$refNoBot = referralLinkInfo('tg:999888', '');
expect($refNoBot['is_guest'] === false && $refNoBot['link'] === '' && $refNoBot['error'] !== '', 'tg user without bot username reports config error');
$refOk = referralLinkInfo('tg:999888', 'bot_shopee');
expect($refOk['is_guest'] === false && $refOk['link'] === 'https://t.me/bot_shopee?start=ref_999888', 'tg user gets valid referral link');

// --- 7. Finding #11: extLink css class ---
echo "--- Finding #11: extLink css class ---\n";
$html1 = extLink('https://shopee.vn/product/1/2', 'Mở Shopee');
expect(str_contains($html1, 'class="btn ghost"'), 'extLink defaults to btn ghost class');
$html2 = extLink('https://shopee.vn/product/1/2', 'Mở Shopee', 'custom-cls');
expect(str_contains($html2, 'class="custom-cls"'), 'extLink accepts custom class');

// --- 8. Finding #20: Admin withdraw action labels ---
echo "--- Finding #20: Admin action labels ---\n";
expect(adminWithdrawActionLabel('approve') === 'đã duyệt', 'action approve is translated');
expect(adminWithdrawActionLabel('reject') === 'đã từ chối', 'action reject is translated');
expect(adminWithdrawActionLabel('cancel') === 'đã hủy', 'action cancel is translated');
expect(adminWithdrawActionLabel('paid') === 'đã xác nhận chi trả', 'action paid is translated');

// --- 9. Markup regression checks in dashboard.php & admin.php ---
echo "--- Markup checks in dashboard.php & admin.php ---\n";
$dashContent = file_get_contents(__DIR__.'/public/dashboard.php');
$adminContent = file_get_contents(__DIR__.'/public/admin.php');
$cssContent = file_get_contents(__DIR__.'/public/assets/style.css');

// Check data-once on forms in dashboard
expect(preg_match('/<form method="post"[^>]*data-once[^>]*>.*?name="action"\s+value="mklink"/s', $dashContent) === 1, 'mklink form has data-once');
expect(preg_match('/<form method="post"[^>]*data-once[^>]*>.*?name="action"\s+value="bank"/s', $dashContent) === 1, 'bank form has data-once');
expect(preg_match('/<form method="post"[^>]*data-once[^>]*>.*?name="action"\s+value="withdraw"/s', $dashContent) === 1, 'withdraw form has data-once');

// Check required inputs on bank form in dashboard
expect(str_contains($dashContent, 'id="bank_name" name="bank_name" placeholder="VD: Vietcombank" required'), 'bank_name is required');
expect(str_contains($dashContent, 'id="bank_acct" name="bank_acct" placeholder="VD: 0123456789" required'), 'bank_acct is required');
expect(str_contains($dashContent, 'id="bank_holder" name="bank_holder" placeholder="VD: NGUYEN VAN A" required'), 'bank_holder is required');

// Check coordinator extra item: assertLinkQuota before presignLinks, last_link_at only when made > 0
expect(preg_match('/assertLinkQuota\(\$d,\s*\$c,\s*\$uid\);\s*\$prepared\s*=\s*presignLinks/s', $dashContent) === 1, 'assertLinkQuota called before presignLinks');
expect(preg_match('/if\s*\(\$made\s*>\s*0\)\s*q\(\$d,\s*[\'"]UPDATE cb_users SET last_link_at=\? WHERE id=\?[\'"],\s*\[time\(\),\s*\$uid\]\);/', $dashContent) === 1, 'last_link_at updated only when made > 0');

// Check finding #14: markread form is conditional and has data-once
expect(str_contains($dashContent, '<?php if ($notes && unreadCount($c) > 0): ?>'), 'markread form only shown when unread notes exist');

// Check CSS rules
echo "--- CSS rules checks ---\n";
expect(str_contains($cssContent, 'word-break: break-all;'), '.mono has word-break: break-all');
expect(str_contains($cssContent, 'overflow-wrap: anywhere;'), '.mono has overflow-wrap: anywhere');
expect(str_contains($cssContent, 'input:focus-visible, textarea:focus-visible, select:focus-visible'), 'focus-visible explicitly styled for form inputs');
expect(str_contains($cssContent, 'table.responsive td:has(input)'), 'table responsive td:has(input) has special mobile flex styles');
expect(str_contains($cssContent, '@media (max-width: 420px)'), 'header has max-width 420px breakpoint to prevent wrapping');
expect(str_contains($cssContent, ':root[data-theme="dark"] .badge-count { color: #111827; font-weight: 800; }') && (bool)preg_match('~@media \(prefers-color-scheme: dark\) \{\s*:root:not\(\[data-theme="light"\]\) \.badge-count \{ color: #111827;~', $cssContent) && !preg_match('~\.badge-count,\s*@media~', $cssContent), 'dark mode badge-count rules are valid CSS (no @media inside a selector list)');
expect(!str_contains($cssContent, 'details.faq'), 'dead details.faq CSS rule is removed');

echo "=== All UI Regression Tests Passed ===\n";
