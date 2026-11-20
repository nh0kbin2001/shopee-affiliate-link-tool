<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$__core = __DIR__.'/core.php'; if (!is_file($__core)) $__core = __DIR__.'/private/core.php'; require $__core;
function expect(bool $ok, string $name): void { if (!$ok) throw new RuntimeException('FAIL: '.$name); echo 'PASS: '.$name."\n"; }
function rejects(callable $fn, string $name): void { try { $fn(); } catch (InvalidArgumentException $e) { expect(true, $name); return; } expect(false, $name); }
function runtimeRejects(callable $fn, string $contains, string $name): void { try { $fn(); } catch (RuntimeException $e) { expect(str_contains($e->getMessage(), $contains), $name); return; } expect(false, $name); }
function outboxMsg(PDO $d, string $id): string { return (string)q($d, 'SELECT message FROM cb_outbox WHERE id=?', [$id])->fetchColumn(); }
function outboxRow(PDO $d, string $id): array { return q($d, 'SELECT * FROM cb_outbox WHERE id=?', [$id])->fetch() ?: []; }
function msgCtx(string $key, $target, ?string $sender, string $text, bool $private = true, string $senderRef = ''): array {
    return ['kind' => 'message', 'channel' => 'tg', 'target' => $target, 'chat_id' => $target, 'private' => $private, 'sender_key' => $sender, 'sender_ref' => $senderRef !== '' ? $senderRef : (string)$target, 'text' => $text, 'key' => $key];
}
function cbCtx(string $id, ?string $sender, string $data, $target = 1, string $senderRef = '1'): array {
    return ['kind' => 'callback', 'cb_id' => $id, 'channel' => 'tg', 'target' => $target, 'chat_id' => $target, 'private' => true, 'sender_key' => $sender, 'sender_ref' => $senderRef, 'text' => '', 'data' => $data, 'key' => 'tgc:'.$id];
}
$c = cfg(); $c["link_mode"] = "an_redir"; // tests stay offline
// Pure/offline regressions: these run before opening the database connection.
expect(reportToken('0123456789abcdefabcd----') === '0123456789abcdefabcd', 'reportToken strips Shopee suffix');
expect(reportToken('  0123456789abcdefabcd----  ') === '0123456789abcdefabcd', 'reportToken trims whitespace');
expect(reportToken('----') === '' && reportToken('') === '' && reportToken('abc-123') === '', 'reportToken rejects malformed tracking values');
expect(reportConversionId(240101234567890) === '240101234567890' && reportConversionId('X-1') === 'X-1' && reportConversionId(null) === '', 'reportConversionId accepts numeric order IDs safely');
$mapped = guessConversionRow(['sub_id1' => '0123456789abcdefabcd----', 'utm_content' => 'ffffffffffffffffffff----', 'checkout_id' => 240101234567890, 'commission' => 15000, 'status' => 'COMPLETED']);
expect($mapped['tracking_token'] === '0123456789abcdefabcd----' && $mapped['conversion_id'] === 240101234567890, 'guessConversionRow prefers sub_id1 and retains numeric order ID');
expect(productFromHtml('CONFIG={"httpUrl":"https:\\/\\/shopee.vn\\/opaanlp\\/1158330489\\/24557197489?x=1"}') === 'https://shopee.vn/product/1158330489/24557197489', 'productFromHtml parses escaped product URL');
expect(productFromHtml('<link rel="canonical" href="https://shopee.vn/ao-thun-i.1158330489.24557197489?x=1">') === 'https://shopee.vn/product/1158330489/24557197489', 'productFromHtml parses canonical product URL');
expect(productFromHtml('<html>browser check</html>') === null && productFromHtml('https://shopee.vn.evil.test/opaanlp/1/2') === null, 'productFromHtml rejects missing and untrusted URLs');
$urls = extractUrls(['text' => 'xem https://shopee.vn/product/1/2.', 'caption' => '', 'entities' => [['type' => 'text_link', 'url' => 'https://shopee.vn/product/3/4']]]);
expect(count($urls) === 2 && $urls[0] === 'https://shopee.vn/product/1/2', 'extractUrls strips punctuation and includes text links');
$manyUrls = extractUrls(['text' => implode(' ', array_map(fn($n) => 'https://shopee.vn/product/'.$n.'/'.$n, range(1, 7)))]);
expect(count($manyUrls) === 5, 'extractUrls caps URL count');
safeUrl('https://shope.ee/ABC'); safeUrl('https://vn.shp.ee/ABC');
safeUrl('https://shopee.vn:443/product/1/2');
rejects(fn() => safeUrl('http://shopee.vn/product/1/2'), 'safeUrl rejects HTTP');
rejects(fn() => safeUrl('https://a:b@shopee.vn/product/1/2'), 'safeUrl rejects credentials');
$prepCfg = $c; $prepCfg['resolve_short_links'] = false; $prepCfg['source_chats'] = [];
$prepCtx = ['channel' => 'tg', 'private' => true, 'chat_id' => 1];
expect(prepareLinks($prepCfg, $prepCtx, ['text' => 'https://evil.test/product/1/2']) === [], 'prepareLinks drops non-Shopee URL');
$shortPrepared = prepareLinks($prepCfg, $prepCtx, ['text' => 'https://s.shopee.vn/ABC']);
expect(count($shortPrepared) === 1 && isset($shortPrepared[0]['error']), 'prepareLinks reports short URL when resolution disabled');
expect(prepareLinks($prepCfg, ['channel' => 'tg', 'private' => false, 'chat_id' => -100], ['text' => 'https://shopee.vn/product/1/2']) === [], 'prepareLinks blocks unapproved group');
expect(prepareLinks($prepCfg, $prepCtx, ['text' => '/start https://shopee.vn/product/1/2']) === [], 'prepareLinks ignores commands');
rejects(fn() => resolveShort('http://s.shopee.vn/X'), 'resolveShort rejects HTTP');
rejects(fn() => resolveShort('https://evil.test/X'), 'resolveShort rejects untrusted host');
$hop = 0;
$resolved = resolveShort('https://s.shopee.vn/A', function ($url) use (&$hop) {
    $hop++;
    if ($hop === 1) return [301, 'https://shope.ee/B', ''];
    if ($hop === 2) return [302, 'https://vn.shp.ee/C', ''];
    return [200, '', 'CONFIG={"httpUrl":"https://shopee.vn/product/11/22"}'];
});
expect($resolved === 'https://shopee.vn/product/11/22' && $hop === 3, 'resolveShort follows validated redirects into product HTML');
rejects(fn() => resolveShort('https://s.shopee.vn/A', fn() => [301, 'https://evil.test/X', '']), 'resolveShort rejects untrusted redirect');
$manyHops = 0;
rejects(fn() => resolveShort('https://s.shopee.vn/A', function () use (&$manyHops) { $manyHops++; return [301, 'https://s.shopee.vn/next'.$manyHops, '']; }), 'resolveShort rejects redirect loop');
rejects(fn() => resolveShort('https://s.shopee.vn/A', fn() => [200, '', false]), 'resolveShort handles fetch failure');
$badMode = $c; $badMode['link_mode'] = 'custom-link';
runtimeRejects(fn() => cfgValidate($badMode), 'link_mode', 'cfgValidate rejects unknown link_mode');
$relayNoKey = $badMode; $relayNoKey['link_mode'] = 'relay'; $relayNoKey['relay_key'] = '';
runtimeRejects(fn() => cfgValidate($relayNoKey), 'relay_key', 'cfgValidate requires relay_key for relay mode');
$relayOk = $relayNoKey; $relayOk['relay_key'] = str_repeat('d', 64);
expect(cfgValidate($relayOk)['relay_wait_seconds'] === 12, 'cfgValidate accepts relay mode with key, default wait 12s');
if (!preg_match('/dbname=[^;]*test/i', $c['dsn'])) throw new RuntimeException('Use a dedicated TEST database with test in its name.');
$d = db($c); $d->beginTransaction();
try {
    $o = [];
    $id = random_int(100000000000, 999999999999); $other = $id + 1;
    $uk = tgKey($id); $ukOther = tgKey($other);
    q($d, 'INSERT INTO cb_users(id,bank) VALUES(?,?),(?,?)', [$uk, 'TEST BANK ONLY', $ukOther, 'TEST BANK ONLY']);
    $testCfg = $c; $testCfg['allowed_users'] = [$uk, $ukOther, 'zalo:zsid1']; $testCfg['admins'] = [$uk];
    $testCfg['max_links_per_day'] = 1000; $testCfg['link_cooldown_seconds'] = 0;
    $cfgW = $testCfg; $cfgW['min_withdraw_vnd'] = 1;
    $origin = 'https://shopee.vn/product/12/34';

    // --- customLink request seam (all responses simulated; no Shopee network) ---
    setSetting($d, 'affiliate_cookie', encBlob($c, 'SESSION=test; csrftoken=csrf-test'));
    $okFetch = fn($url, $body, $cookie, $headers) => [200, '{"data":{"batchCustomLink":[{"shortLink":"https://s.shopee.vn/AbC","failCode":0}]}}'];
    expect(customLink($d, $testCfg, $origin, '0123456789abcdefabcd', $okFetch) === 'https://s.shopee.vn/AbC', 'customLink injected HTTP success');
    runtimeRejects(fn() => customLink($d, $testCfg, $origin, '0123456789abcdefabcd', fn() => [200, '{"data":{"batchCustomLink":[{"shortLink":"https://s.shopee.vn/x","failCode":2,"message":"invalid session"}]}}']), 'failCode 2', 'customLink reports failCode and Shopee message');
    runtimeRejects(fn() => customLink($d, $testCfg, $origin, '0123456789abcdefabcd', fn() => [403, '<html>Forbidden</html>']), 'HTTP 403', 'customLink reports HTTP 403');
    setSetting($d, 'affiliate_cookie', '');
    runtimeRejects(fn() => customLink($d, $testCfg, $origin, '0123456789abcdefabcd', $okFetch), 'cookie', 'customLink reports missing cookie');
    $customCfg = $testCfg; $customCfg['link_mode'] = 'custom_link';
    $linkCountBeforeFailure = (int)q($d, 'SELECT COUNT(*) FROM cb_links WHERE user_id=?', [$uk])->fetchColumn();
    processUpdate($d, $customCfg, msgCtx('custom-link-failure-'.$id, $id, $uk, 'product'), [['origin' => $origin]], $okFetch);
    expect((int)q($d, 'SELECT COUNT(*) FROM cb_links WHERE user_id=?', [$uk])->fetchColumn() === $linkCountBeforeFailure, 'failed customLink does not insert cb_links');
    expect((int)q($d, 'SELECT last_link_at FROM cb_users WHERE id=?', [$uk])->fetchColumn() === 0, 'failed customLink does not start cooldown');
    $blockedCfg = $customCfg; $blockedCfg['max_links_per_day'] = 0; $httpCalls = 0;
    processUpdate($d, $blockedCfg, msgCtx('quota-before-presign-'.$id, $id, $uk, 'product'), [['origin' => 'https://shopee.vn/product/19/20']], function () use (&$httpCalls) { $httpCalls++; return [200, '']; });
    expect($httpCalls === 0, 'processUpdate checks quota before Custom Link HTTP call');
    $ck5k = 'SPC_EC='.str_repeat('a', 5000).'; csrftoken=abc';
    setSetting($d, 'affiliate_cookie', encBlob($c, $ck5k));
    expect(decBlob($c, setting($d, 'affiliate_cookie', '')) === $ck5k, 'setSetting keeps a 5 KB encrypted cookie intact');
    $pe = presignLinks($d, array_merge($testCfg, ['link_mode' => 'custom_link']), $uk, [['origin' => 'https://shopee.vn/product/55/56']], fn() => [403, '{"error":90309999}']);
    expect(isset($pe[0]['error']) && ($pe[0]['origin'] ?? '') === 'https://shopee.vn/product/55/56', 'presignLinks keeps origin on failure');
    setSetting($d, 'affiliate_cookie', encBlob($c, str_repeat('x', 12001), 13000));
    runtimeRejects(fn() => customLink($d, $testCfg, $origin, '0123456789abcdefabcd', $okFetch), '12.000', 'customLink reports oversized cookie');
    setSetting($d, 'affiliate_cookie', encBlob($c, 'SESSION=test'));
    runtimeRejects(fn() => customLink($d, $testCfg, $origin, '0123456789abcdefabcd', fn() => [200, '{"data":{"batchCustomLink":[{"shortLink":"https://evil.test/x","failCode":0}]}}']), 'allowlist', 'customLink rejects shortLink outside Shopee allowlist');

    // --- URL / tracking basics (from v0.1/v0.2) ---
    $origin = canonical('https://shopee.vn/abc-i.12.34?utm_source=x');
    expect($origin === 'https://shopee.vn/product/12/34', 'clean origin');
    rejects(fn() => canonical('https://shopee.vn.evil.test/product/12/34'), 'reject non-Shopee host');
    rejects(fn() => canonical('https://shopee.vn:8443/product/12/34'), 'reject custom port');
    $a = newLink($d, $c, $uk, $origin); $b = newLink($d, $c, $ukOther, $origin);
    expect($a['token'] !== $b['token'], 'separate customer tracking');
    expect(newLink($d, $c, $uk, $origin)['token'] === $a['token'], 'stable customer/product token');
    $syncId = 'syncfail'.bin2hex(random_bytes(5)); $syncSetting = 'sync-test-'.$syncId;
    $syncFetch = fn($page, $size) => ['data' => ['list' => $page === 1 ? [['sub_id1' => $a['token'].'----', 'checkout_id' => $syncId, 'commission' => 1200, 'status' => 'COMPLETED']] : []]];
    $dbFailureSeen = false;
    try { syncFromReport($d, $testCfg, 1, 2, false, $syncFetch, function () use ($d, $syncSetting) { setSetting($d, $syncSetting, 'partial'); throw new PDOException('synthetic DB failure'); }); }
    catch (PDOException $e) { $dbFailureSeen = true; }
    expect($dbFailureSeen && setting($d, $syncSetting, '') === '', 'syncFromReport lets DB failure roll back partial row writes');

    // --- user keys / routing ---
    expect(routeFor($uk) === ['tg', $id], 'route telegram key');
    expect(routeFor('zalo:zsid1') === ['zalo', 'zsid1'], 'route zalo key');
    rejects(fn() => routeFor('xx:1'), 'reject unknown key');

    // --- bank encryption ---
    $blob = encBlob($c, 'SESSION=abc; csrftoken=xyz');
    expect(str_starts_with($blob, 'gcmb1.') && decBlob($c, $blob) === 'SESSION=abc; csrftoken=xyz', 'encBlob roundtrip and prefix');
    $damagedBlob = substr($blob, 0, -4).(substr($blob, -4) === 'AAAA' ? 'BBBB' : 'AAAA');
    runtimeRejects(fn() => decBlob($c, $damagedBlob), 'unreadable', 'decBlob rejects modified ciphertext');
    runtimeRejects(fn() => decBlob($c, 'plain'), 'unreadable', 'decBlob rejects missing prefix');
    rejects(fn() => encBlob($c, str_repeat('x', 12001)), 'encBlob rejects oversized value');
    $enc = encBank($c, 'VCB | 123456 | TEST');
    expect(str_starts_with($enc, 'gcm1.'), 'bank stored encrypted');
    [$pt, $legacy] = decBank($c, $enc);
    expect($pt === 'VCB | 123456 | TEST' && $legacy === false, 'bank roundtrip');
    [$pt2, $legacy2] = decBank($c, 'TEST BANK ONLY');
    expect($pt2 === 'TEST BANK ONLY' && $legacy2 === true, 'legacy bank passthrough');
    expect(maskBank('VCB | 123456 | TEST') === 'VCB ***TEST', 'mask bank');

    // --- conversions / ledger ---
    $prefix = 't'.bin2hex(random_bytes(5));
    $e = ['event_id' => $prefix.'e1', 'conversion_id' => $prefix.'c1', 'tracking_token' => $a['token'], 'state' => 'settled', 'commission_vnd' => 20000];
    conversion($d, $testCfg, $e); $share = intdiv(20000 * $c['customer_share_bps'], 10000);
    expect(balance($d, $uk)['available'] === $share, 'credit agreed share');
    expect(str_contains(outboxMsg($d, 'event:'.$prefix.'e1:admin:'.$uk), 'hoàn tất'), 'admin notified on settled');
    expect(conversion($d, $testCfg, $e) === false && balance($d, $uk)['available'] === $share, 'idempotent event');
    $changed = $e; $changed['commission_vnd'] = 30000; rejects(fn() => conversion($d, $testCfg, $changed), 'reject altered duplicate event');

    // --- withdrawals (min + audit + transitions) ---
    $cfgMin = $testCfg; $cfgMin['min_withdraw_vnd'] = $share + 1000;
    rejects(fn() => withdraw($d, $cfgMin, $uk, $share, $prefix.'w9'), 'reject below minimum');
    rejects(fn() => withdraw($d, $testCfg, $uk, $share + 1, $prefix.'w0'), 'reject excess withdrawal');
    if ($share > 0) {
        $wid = $prefix.'w1'; withdraw($d, $testCfg, $uk, $share, $wid); expect(balance($d, $uk)['available'] === 0, 'reserve withdrawal');
        expect((int)q($d, "SELECT COUNT(*) FROM cb_audit WHERE action='withdraw.request' AND ref=?", [$wid])->fetchColumn() === 1, 'audit withdraw request');
        expect(transition($d, $testCfg, $wid, 'reject', '', $uk) !== '', 'reject replies');
        expect(balance($d, $uk)['available'] === $share, 'restore rejected withdrawal');
        $wid = $prefix.'w2'; withdraw($d, $testCfg, $uk, $share, $wid);
        $msg = transition($d, $testCfg, $wid, 'approve', '', $uk);
        expect(str_contains($msg, 'TEST BANK ONLY') && str_contains($msg, 'chưa mã hóa'), 'approve shows legacy bank warning');
        transition($d, $testCfg, $wid, 'paid', 'TEST_REF', $uk);
        rejects(fn() => transition($d, $testCfg, $wid, 'paid', 'TEST_REF', $uk), 'reject duplicate paid');
        expect((int)q($d, "SELECT COUNT(*) FROM cb_audit WHERE ref=?", [$wid])->fetchColumn() === 3, 'audit transitions');
    }
    $e['event_id'] = $prefix.'e2'; $e['state'] = 'rejected'; conversion($d, $testCfg, $e);
    expect(balance($d, $uk)['available'] === ($share > 0 ? -$share : 0), 'reverse rejected conversion after payout');

    // --- /bank command encrypts + masks ---
    handle($d, $testCfg, msgCtx('tg:900001', $id, $ukOther, '/bank VCB | 999 | NAME'), [], $o);
    expect(str_starts_with((string)q($d, 'SELECT bank FROM cb_users WHERE id=?', [$ukOther])->fetchColumn(), 'gcm1.'), '/bank encrypts');
    handle($d, $testCfg, msgCtx('tg:900002', $id, $ukOther, '/bank'), [], $o);
    expect(str_contains(outboxMsg($d, 'tg:900002'), '***'), '/bank view masked');

    // --- anti-spam quota + cooldown ---
    $ukQ = tgKey($id + 2); $ukC = tgKey($id + 3);
    $testCfg['allowed_users'][] = $ukQ; $testCfg['allowed_users'][] = $ukC;
    $cfgW['allowed_users'] = $testCfg['allowed_users'];
    $cfgQuota = $testCfg; $cfgQuota['max_links_per_day'] = 1; $cfgQuota['link_cooldown_seconds'] = 0;
    handle($d, $cfgQuota, msgCtx('tg:900011', $id, $ukQ, 'https://shopee.vn/product/99/88'), [['origin' => 'https://shopee.vn/product/99/88']], $o);
    rejects(fn() => handle($d, $cfgQuota, msgCtx('tg:900012', $id, $ukQ, 'x'), [['origin' => 'https://shopee.vn/product/99/89']], $o), 'reject over daily quota');
    $cfgCd = $testCfg; $cfgCd['link_cooldown_seconds'] = 3600;
    handle($d, $cfgCd, msgCtx('tg:900013', $id, $ukC, 'x'), [['origin' => 'https://shopee.vn/product/99/90']], $o);
    rejects(fn() => handle($d, $cfgCd, msgCtx('tg:900014', $id, $ukC, 'x'), [['origin' => 'https://shopee.vn/product/99/91']], $o), 'reject cooldown');

    // --- group finance block + /id shows own key ---
    $before = (int)q($d, 'SELECT COUNT(*) FROM cb_outbox')->fetchColumn();
    handle($d, $testCfg, msgCtx('tg:900021', -1001, $uk, '/balance', false), [], $o);
    expect((int)q($d, 'SELECT COUNT(*) FROM cb_outbox')->fetchColumn() === $before, 'no balances in group');
    handle($d, $testCfg, msgCtx('tg:900022', 555, null, '/id', true, '555'), [], $o);
    expect(str_contains(outboxMsg($d, 'tg:900022'), 'tg:555'), 'unknown user gets own key');

    // --- zalo channel link flow ---
    handle($d, $testCfg, ['kind' => 'message', 'channel' => 'zalo', 'target' => 'zsid1', 'chat_id' => 'zsid1', 'private' => true, 'sender_key' => 'zalo:zsid1', 'sender_ref' => 'zsid1', 'text' => 'xem https://shopee.vn/product/77/66', 'key' => 'zalo:zmsg1'], [['origin' => 'https://shopee.vn/product/77/66']], $o);
    $zr = q($d, 'SELECT channel,target FROM cb_outbox WHERE id=?', ['zalo:zmsg1'])->fetch();
    expect($zr['channel'] === 'zalo' && $zr['target'] === 'zsid1', 'zalo outbox routing');

    // === v0.3: one pending withdrawal per user (via processUpdate so audit persists) ===
    $ukP = tgKey($id + 10); $testCfg['allowed_users'][] = $ukP; $cfgW['allowed_users'] = $testCfg['allowed_users'];
    q($d, 'INSERT INTO cb_users(id,bank) VALUES(?,?)', [$ukP, 'PBANK']);
    $lkP = newLink($d, $cfgW, $ukP, 'https://shopee.vn/product/60/60');
    conversion($d, $cfgW, ['event_id' => $prefix.'eP', 'conversion_id' => $prefix.'cP', 'tracking_token' => $lkP['token'], 'state' => 'settled', 'commission_vnd' => 20000]);
    $pw = $prefix.'wp'; withdraw($d, $cfgW, $ukP, 100, $pw);
    $balP = balance($d, $ukP)['available'];
    $ans = processUpdate($d, $cfgW, msgCtx('tg:910001', $id + 10, $ukP, '/withdraw 100', true, (string)($id + 10)), []);
    expect($ans === '', 'no toast on message path');
    expect((int)q($d, "SELECT COUNT(*) FROM cb_audit WHERE action='blocked.withdraw-active' AND ref=?", ['tg:910001'])->fetchColumn() === 1, 'audit blocked second withdrawal');
    expect(str_contains(outboxMsg($d, 'error:tg:910001'), 'chờ xử lý'), 'blocked withdrawal explains');
    expect(balance($d, $ukP)['available'] === $balP, 'no extra hold from blocked withdrawal');

    // === v0.3: quota block is audited ===
    $ukQ2 = tgKey($id + 11); $testCfg['allowed_users'][] = $ukQ2; $cfgW['allowed_users'] = $testCfg['allowed_users'];
    $cfgQ2 = $cfgW; $cfgQ2['max_links_per_day'] = 1;
    processUpdate($d, $cfgQ2, msgCtx('tg:910011', $id + 11, $ukQ2, 'l1'), [['origin' => 'https://shopee.vn/product/61/61']]);
    processUpdate($d, $cfgQ2, msgCtx('tg:910012', $id + 11, $ukQ2, 'l2'), [['origin' => 'https://shopee.vn/product/62/62']]);
    expect((int)q($d, "SELECT COUNT(*) FROM cb_audit WHERE action='blocked.quota-daily' AND ref=?", ['tg:910012'])->fetchColumn() === 1, 'audit blocked quota');

    // === v0.3: menu button carries keyboard markup ===
    $ans = processUpdate($d, $testCfg, cbCtx('zm1', $uk, 'm:balance', $id, (string)$id));
    expect(is_string($ans), 'callback answers');
    $row = outboxRow($d, 'tgc:zm1');
    expect(str_contains($row['message'], 'Số dư') && str_contains((string)$row['markup'], 'inline_keyboard'), 'menu reply has buttons');

    // === v0.3: matchForUpdate finds exactly this update's rows (fast-path matching) ===
    $found = matchForUpdate($d, 'tgc:zm1');
    expect(count($found) > 0, 'fast-path matcher finds rows');
    foreach ($found as $r) expect($r['id'] === 'tgc:zm1' || str_starts_with($r['id'], 'tgc:zm1:') || $r['id'] === 'error:tgc:zm1', 'matcher only own rows');

    // === v0.3: approve by button, duplicate press is harmless ===
    $ukS = tgKey($id + 12); $testCfg['allowed_users'][] = $ukS; $cfgW['allowed_users'] = $testCfg['allowed_users'];
    q($d, 'INSERT INTO cb_users(id,bank) VALUES(?,?)', [$ukS, 'SBANK']);
    $lk = newLink($d, $cfgW, $ukS, 'https://shopee.vn/product/71/71');
    conversion($d, $cfgW, ['event_id' => $prefix.'eS', 'conversion_id' => $prefix.'cS', 'tracking_token' => $lk['token'], 'state' => 'settled', 'commission_vnd' => 20000]);
    $sw = $prefix.'wS'; withdraw($d, $cfgW, $ukS, 12000, $sw);
    $toast = processUpdate($d, $cfgW, cbCtx('za1', $uk, 'w:approve:'.$sw, $id, (string)$id));
    expect(str_contains($toast, 'Đã duyệt') && q($d, 'SELECT state FROM cb_withdrawals WHERE id=?', [$sw])->fetchColumn() === 'approved', 'approve by button');
    $before2 = balance($d, $ukS)['available'];
    $toast2 = processUpdate($d, $cfgW, cbCtx('za2', $uk, 'w:approve:'.$sw, $id, (string)$id));
    expect(str_contains($toast2, 'đã được xử lý') && balance($d, $ukS)['available'] === $before2, 'duplicate press harmless');
    expect((int)q($d, "SELECT COUNT(*) FROM cb_audit WHERE action='blocked.duplicate' AND ref=?", [$sw])->fetchColumn() === 1, 'audit duplicate press');
    $toast3 = processUpdate($d, $cfgW, cbCtx('za3', $ukOther, 'w:approve:'.$sw, $other, (string)$other));
    expect(str_contains($toast3, 'Chỉ admin'), 'non-admin button denied');

    // === v0.3: w:req lists pending with action buttons ===
    $ukR = tgKey($id + 13); $testCfg['allowed_users'][] = $ukR; $cfgW['allowed_users'] = $testCfg['allowed_users'];
    q($d, 'INSERT INTO cb_users(id,bank) VALUES(?,?)', [$ukR, 'RBANK']);
    $lkR = newLink($d, $cfgW, $ukR, 'https://shopee.vn/product/72/72');
    conversion($d, $cfgW, ['event_id' => $prefix.'eR', 'conversion_id' => $prefix.'cR', 'tracking_token' => $lkR['token'], 'state' => 'settled', 'commission_vnd' => 20000]);
    $rw = $prefix.'wR'; withdraw($d, $cfgW, $ukR, 5000, $rw);
    processUpdate($d, $cfgW, cbCtx('zr1', $uk, 'w:req', $id, (string)$id));
    $rq = outboxRow($d, 'tgc:zr1');
    expect(str_contains($rq['message'], $rw) && str_contains((string)$rq['markup'], 'w:approve:'.$rw), 'request list has action buttons');

    // === v0.3: button-driven withdraw input (pending_action) ===
    $ukT = tgKey($id + 14); $testCfg['allowed_users'][] = $ukT; $cfgW['allowed_users'] = $testCfg['allowed_users'];
    q($d, 'INSERT INTO cb_users(id,bank) VALUES(?,?)', [$ukT, 'TBANK']);
    $lkT = newLink($d, $cfgW, $ukT, 'https://shopee.vn/product/73/73');
    conversion($d, $cfgW, ['event_id' => $prefix.'eT', 'conversion_id' => $prefix.'cT', 'tracking_token' => $lkT['token'], 'state' => 'settled', 'commission_vnd' => 20000]);
    processUpdate($d, $cfgW, cbCtx('zt1', $ukT, 'm:withdraw', $id + 14, (string)($id + 14)));
    expect(q($d, 'SELECT pending_action FROM cb_users WHERE id=?', [$ukT])->fetchColumn() === 'withdraw', 'withdraw button arms input');
    processUpdate($d, $cfgW, msgCtx('tg:910031', $id + 14, $ukT, '9000', true, (string)($id + 14)), []);
    expect((int)q($d, "SELECT COUNT(*) FROM cb_withdrawals WHERE user_id=? AND state='requested'", [$ukT])->fetchColumn() === 1, 'typed amount creates request');
    expect(q($d, 'SELECT pending_action FROM cb_users WHERE id=?', [$ukT])->fetchColumn() === '', 'pending cleared after use');
    processUpdate($d, $cfgW, cbCtx('zt2', $ukT, 'm:bank', $id + 14, (string)($id + 14)));
    processUpdate($d, $cfgW, msgCtx('tg:910032', $id + 14, $ukT, '/cancel', true, (string)($id + 14)), []);
    expect(q($d, 'SELECT pending_action FROM cb_users WHERE id=?', [$ukT])->fetchColumn() === '', '/cancel clears pending');

    // === v0.3: stale/unknown button ===
    $toastX = processUpdate($d, $cfgW, cbCtx('zx1', $uk, 'xx:zzz', $id, (string)$id));
    expect(str_contains($toastX, 'hết hạn'), 'stale button answered');

    // === v0.4: resolveUserKey ===
    expect(resolveUserKey('123') === 'tg:123', 'resolve bare id');
    expect(resolveUserKey('tg:456') === 'tg:456', 'resolve tg key');
    rejects(fn() => resolveUserKey('bad key!'), 'reject bad user');

    // === v0.4: warranty policy settings ===
    processUpdate($d, $testCfg, cbCtx('zp1', $uk, 'a:setpolicy', $id, (string)$id));
    expect(q($d, 'SELECT pending_action FROM cb_users WHERE id=?', [$uk])->fetchColumn() === 'setpolicy', 'setpolicy button arms input');
    processUpdate($d, $testCfg, msgCtx('tg:920001', $id, $uk, 'Bảo hành 7 ngày nhé!', true, (string)$id), []);
    expect(setting($d, 'policy') === 'Bảo hành 7 ngày nhé!', 'admin sets policy');
    processUpdate($d, $testCfg, cbCtx('zp2', $ukOther, 'm:policy', $other, (string)$other));
    expect(str_contains(outboxMsg($d, 'tgc:zp2'), 'Bảo hành 7 ngày'), 'user reads policy');

    // === v0.4: admin wallet adjustment (missed update) ===
    $ukA = tgKey($id + 20); $testCfg['allowed_users'][] = $ukA;
    q($d, 'INSERT INTO cb_users(id) VALUES(?)', [$ukA]);
    $beforeA = balance($d, $ukA)['available'];
    processUpdate($d, $testCfg, msgCtx('tg:920011', $id, $uk, '/credit '.$ukA.' 5000 đền bù đơn sót', true, (string)$id), []);
    expect(balance($d, $ukA)['available'] === $beforeA + 5000, 'credit raises balance');
    expect(str_contains(outboxMsg($d, 'tg:920011:adjust:'.$ukA), 'cộng thêm'), 'user notified of credit');
    expect((int)q($d, "SELECT COUNT(*) FROM cb_audit WHERE action='wallet.adjust' AND ref=?", [$ukA])->fetchColumn() === 1, 'audit wallet adjust');
    processUpdate($d, $testCfg, msgCtx('tg:920012', $id, $uk, '/debit '.$ukA.' 2000 sửa sai', true, (string)$id), []);
    expect(balance($d, $ukA)['available'] === $beforeA + 3000, 'debit lowers balance');
    rejects(fn() => adjust($d, $testCfg, $uk, $ukA, 0, 'x'), 'reject zero adjustment');
    rejects(fn() => adjust($d, $testCfg, $uk, 'tg:999999999', 1000, 'x'), 'reject non-allowlisted user');

    // === v0.4: button-driven credit input ===
    $ukB = tgKey($id + 21); $testCfg['allowed_users'][] = $ukB;
    q($d, 'INSERT INTO cb_users(id) VALUES(?)', [$ukB]);
    processUpdate($d, $testCfg, cbCtx('zc1', $uk, 'a:credit', $id, (string)$id));
    expect(q($d, 'SELECT pending_action FROM cb_users WHERE id=?', [$uk])->fetchColumn() === 'credit', 'credit button arms input');
    $bB = balance($d, $ukB)['available'];
    processUpdate($d, $testCfg, msgCtx('tg:920021', $id, $uk, $ukB.' 7000 nộp bù', true, (string)$id), []);
    expect(balance($d, $ukB)['available'] === $bB + 7000, 'typed credit works');
    expect(q($d, 'SELECT pending_action FROM cb_users WHERE id=?', [$uk])->fetchColumn() === '', 'pending cleared after credit');

    // === v0.5: shared quota rule (assertLinkQuota) + web notifications ===
    $ukQ3 = tgKey($id + 30); $testCfg['allowed_users'][] = $ukQ3;
    q($d, 'INSERT INTO cb_users(id,bank) VALUES(?,?)', [$ukQ3, 'QBANK']);
    $cfgLQ = $testCfg; $cfgLQ['max_links_per_day'] = 1; $cfgLQ['link_cooldown_seconds'] = 0;
    assertLinkQuota($d, $cfgLQ, $ukQ3);
    newLink($d, $cfgLQ, $ukQ3, 'https://shopee.vn/product/80/80');
    rejects(fn() => assertLinkQuota($d, $cfgLQ, $ukQ3), 'assertLinkQuota blocks after limit');

    $ukN = tgKey($id + 31); $testCfg['allowed_users'][] = $ukN;
    q($d, 'INSERT INTO cb_users(id,bank) VALUES(?,?)', [$ukN, 'NBANK']);
    $lkN = newLink($d, $testCfg, $ukN, 'https://shopee.vn/product/81/81');
    conversion($d, $testCfg, ['event_id' => $prefix.'eN', 'conversion_id' => $prefix.'cN', 'tracking_token' => $lkN['token'], 'state' => 'settled', 'commission_vnd' => 20000]);
    expect((int)q($d, "SELECT COUNT(*) FROM cb_notifications WHERE user_id=? AND kind='money_in'", [$ukN])->fetchColumn() === 1, 'conversion notifies money_in');
    adjust($d, $testCfg, $uk, $ukN, 3000, 'bù đơn sót');
    expect((int)q($d, "SELECT COUNT(*) FROM cb_notifications WHERE user_id=? AND kind='money_in'", [$ukN])->fetchColumn() === 2, 'adjust notifies money_in');
    $cfgN = $testCfg; $cfgN['min_withdraw_vnd'] = 1;
    $pw2 = $prefix.'wN'; withdraw($d, $cfgN, $ukN, 5000, $pw2);
    transition($d, $cfgN, $pw2, 'approve', '', $uk);
    expect((int)q($d, "SELECT COUNT(*) FROM cb_notifications WHERE user_id=? AND kind='withdraw'", [$ukN])->fetchColumn() === 1, 'transition notifies withdraw');

    // Guest mode: guest links earn, conversions don't queue chat messages, login claims everything.
    $gk = guestKey(bin2hex(random_bytes(16))); $ukG = tgKey($id + 40); $testCfg['allowed_users'][] = $ukG;
    expect(isGuest($gk) && !isGuest($ukG), 'guest key format');
    q($d, 'INSERT INTO cb_users(id) VALUES(?)', [$gk]);
    $lgA = newLink($d, $testCfg, $gk, 'https://shopee.vn/product/91/91');
    $lgB = newLink($d, $testCfg, $gk, 'https://shopee.vn/product/92/92');
    $lgC = newLink($d, $testCfg, $gk, 'https://shopee.vn/product/93/93');
    $outBefore = (int)q($d, 'SELECT COUNT(*) FROM cb_outbox')->fetchColumn();
    conversion($d, $testCfg, ['event_id' => $prefix.'eG', 'conversion_id' => $prefix.'cG', 'tracking_token' => $lgA['token'], 'state' => 'settled', 'commission_vnd' => 10000]);
    conversion($d, $testCfg, ['event_id' => $prefix.'eG2', 'conversion_id' => $prefix.'cG2', 'tracking_token' => $lgB['token'], 'state' => 'pending', 'commission_vnd' => 8000]);
    expect((int)q($d, 'SELECT COUNT(*) FROM cb_outbox')->fetchColumn() === $outBefore + count($testCfg['admins']), 'guest conversion: admin note only, no chat to guest');
    $gBal = balance($d, $gk);
    q($d, 'INSERT INTO cb_users(id) VALUES(?)', [$ukG]);
    $luB = newLink($d, $testCfg, $ukG, 'https://shopee.vn/product/92/92');
    newLink($d, $testCfg, $ukG, 'https://shopee.vn/product/93/93');
    $r = atomic($d, fn() => claimGuest($d, $gk, $ukG));
    expect($r === ['links' => 2, 'orders' => 2], 'claimGuest counts');
    expect((int)q($d, 'SELECT COUNT(*) FROM cb_links WHERE user_id=?', [$gk])->fetchColumn() === 0 && !q($d, 'SELECT id FROM cb_users WHERE id=?', [$gk])->fetch(), 'claimGuest empties guest');
    expect(!q($d, 'SELECT token FROM cb_links WHERE token=?', [$lgC['token']])->fetch(), 'claimGuest drops duplicate link without orders');
    $retainedOrigin = (string)q($d, 'SELECT origin FROM cb_links WHERE token=?', [$lgB['token']])->fetchColumn();
    expect(str_ends_with($retainedOrigin, '#g'.$lgB['token']) && strlen($retainedOrigin) <= 192, 'claimGuest suffixes duplicate-with-orders origin within column limit');
    expect((int)q($d, 'SELECT COUNT(*) FROM cb_clicks WHERE token=?', [$lgC['token']])->fetchColumn() === 0, 'claimGuest removes clicks for dropped duplicate');
    expect((int)q($d, 'SELECT COUNT(*) FROM cb_notifications WHERE user_id=?', [$gk])->fetchColumn() === 0, 'claimGuest transfers guest notifications');
    expect((int)q($d, "SELECT COUNT(*) FROM cb_audit WHERE actor=? AND action='guest.claim' AND ref=?", [$ukG, $gk])->fetchColumn() === 1 && (int)q($d, "SELECT COUNT(*) FROM cb_notifications WHERE user_id=? AND kind='info'", [$ukG])->fetchColumn() >= 1, 'claimGuest records audit and user notification');
    expect((string)q($d, 'SELECT user_id FROM cb_links WHERE token=?', [$lgB['token']])->fetchColumn() === $ukG && (string)q($d, 'SELECT token FROM cb_links WHERE user_id=? AND origin=?', [$ukG, 'https://shopee.vn/product/92/92'])->fetchColumn() === $luB['token'], 'claimGuest keeps duplicate link with orders');
    $uBal = balance($d, $ukG);
    expect($uBal['available'] === $gBal['available'] && $uBal['pending'] === $gBal['pending'] && $uBal['available'] > 0, 'claimGuest moves balance');
    $thrown = false; try { claimGuest($d, $ukG, $gk); } catch (InvalidArgumentException $e) { $thrown = true; }
    expect($thrown, 'claimGuest rejects reversed keys');
    $notifyBeforeRetry = (int)q($d, 'SELECT COUNT(*) FROM cb_notifications WHERE user_id=?', [$ukG])->fetchColumn();
    expect(atomic($d, fn() => claimGuest($d, $gk, $ukG)) === ['links' => 0, 'orders' => 0], 'claimGuest empty retry is idempotent');
    expect((int)q($d, 'SELECT COUNT(*) FROM cb_notifications WHERE user_id=?', [$ukG])->fetchColumn() === $notifyBeforeRetry, 'claimGuest retry does not add notifications');

    // Link relay queue (extension): enqueue -> pull claims -> push completes; bad host rejected; timeout fails job.
    $cfgR = $testCfg; $cfgR['link_mode'] = 'relay'; $cfgR['relay_key'] = str_repeat('c', 64); $cfgR['relay_wait_seconds'] = 3;
    $rt1 = bin2hex(random_bytes(10)); $ticks = 0; $clock = 0.0;
    $u1 = relayLink($d, $cfgR, 'https://shopee.vn/product/71/71', $rt1, function (int $id) use ($d, &$ticks) {
        $ticks++;
        if ($ticks === 1) {
            $jobs = relayPull($d, 5);
            $mine = array_values(array_filter($jobs, fn($j) => $j['id'] === $id));
            expect(count($mine) === 1 && $mine[0]['origin_url'] === 'https://shopee.vn/product/71/71', 'relayPull claims queued job');
            expect(relayPush($d, $id, true, 'https://s.shopee.vn/AbCdE12', '') === 'done', 'relayPush completes working job');
            expect(relayPush($d, $id, true, 'https://s.shopee.vn/Other', '') === 'ignored', 'relayPush ignores already-done job');
        }
    }, function () use (&$clock) { return $clock += 0.5; });
    expect($u1 === 'https://s.shopee.vn/AbCdE12', 'relayLink returns pushed short link');
    $rt2 = bin2hex(random_bytes(10));
    $thrown = '';
    try {
        relayLink($d, $cfgR, 'https://shopee.vn/product/72/72', $rt2, function (int $id) use ($d) {
            relayPull($d, 5); relayPush($d, $id, true, 'https://evil.example/x', '');
        }, function () use (&$clock) { return $clock += 0.5; });
    } catch (RuntimeException $e) { $thrown = $e->getMessage(); }
    expect(str_contains($thrown, 'allowlist'), 'relay rejects short link outside Shopee allowlist');
    $rt3 = bin2hex(random_bytes(10)); $clock3 = 0.0; $thrown = '';
    try { relayLink($d, $cfgR, 'https://shopee.vn/product/73/73', $rt3, function () {}, function () use (&$clock3) { return $clock3 += 1.0; }); }
    catch (RelayPending $e) { $thrown = 'pending'; }
    expect($thrown === 'pending', 'relay timeout throws RelayPending (no hard fail)');
    // Durable queue: timeout with a user keeps the job, push later creates the link + notifies.
    $o4 = 'https://shopee.vn/product/74/74'; $rt4 = bin2hex(random_bytes(10)); $clock4 = 0.0;
    try { relayLink($d, $cfgR, $o4, $rt4, function () {}, function () use (&$clock4) { return $clock4 += 1.0; }, null, $uk); expect(false, 'durable: should be pending'); }
    catch (RelayPending $e) {}
    $j4 = q($d, 'SELECT * FROM cb_link_jobs WHERE token=?', [$rt4])->fetch();
    expect($j4['state'] === 'queued' && (int)$j4['async'] === 1 && $j4['user_id'] === $uk, 'durable: job kept queued+async for user');
    expect(relayHasPending($d, $uk, $o4), 'durable: relayHasPending sees it');
    $pre = presignLinks($d, $cfgR, $uk, [['origin' => $o4]]);
    expect(!empty($pre[0]['pending']) && (int)q($d, 'SELECT COUNT(*) FROM cb_link_jobs WHERE origin=?', [$o4])->fetchColumn() === 1, 'durable: repeat paste reuses pending job');
    $outB = (int)q($d, 'SELECT COUNT(*) FROM cb_outbox')->fetchColumn();
    $mine4 = array_values(array_filter(relayPull($d, 5), fn($x) => $x['sub_id'] === $rt4));
    expect(count($mine4) === 1 && relayPush($d, $mine4[0]['id'], true, 'https://s.shopee.vn/Durab1', '') === 'done', 'durable: late push accepted');
    expect((string)q($d, 'SELECT url FROM cb_links WHERE user_id=? AND origin=?', [$uk, $o4])->fetchColumn() === 'https://s.shopee.vn/Durab1', 'durable: cb_links row created on late push');
    expect((int)q($d, "SELECT COUNT(*) FROM cb_notifications WHERE user_id=? AND body LIKE ?", [$uk, '%Durab1%'])->fetchColumn() === 1, 'durable: web notification sent');
    expect((int)q($d, 'SELECT COUNT(*) FROM cb_outbox')->fetchColumn() === $outB + 1, 'durable: chat message queued');
    // Failed async job notifies too, without creating a link.
    $o5 = 'https://shopee.vn/product/75/75'; relayEnqueueAsync($d, $cfgR, $uk, $o5);
    $mine5 = array_values(array_filter(relayPull($d, 5), fn($x) => $x['origin_url'] === $o5));
    expect(relayPush($d, $mine5[0]['id'], false, '', 'blocked') === 'failed' && !q($d, 'SELECT 1 FROM cb_links WHERE user_id=? AND origin=?', [$uk, $o5])->fetch()
        && (int)q($d, "SELECT COUNT(*) FROM cb_notifications WHERE user_id=? AND body LIKE ?", [$uk, '%75/75%'])->fetchColumn() === 1, 'durable: failed job notifies, no link');
    // Watchdog: silent > 5 min alerts once, then recovery message.
    setSetting($d, 'relay_status', 'ok'); setSetting($d, 'relay_alert_at', '');
    setSetting($d, 'relay_seen_at', date('Y-m-d H:i:s', time() - 600));
    expect(relayWatchdog($d, $cfgR) === 'down', 'watchdog: alerts after 5 min silence');
    expect(relayWatchdog($d, $cfgR) === '', 'watchdog: no repeat alert within the hour');
    setSetting($d, 'relay_seen_at', date('Y-m-d H:i:s'));
    expect(relayWatchdog($d, $cfgR) === 'up' && relayWatchdog($d, $cfgR) === '', 'watchdog: one recovery message');
    expect(relayWatchdog($d, $testCfg) === '', 'watchdog: off outside relay mode');
    expect(relayPush($d, 99999999, true, 'https://s.shopee.vn/x', '') === 'unknown', 'relayPush unknown id');

    echo "All tests passed; changes rolled back.\n";
} finally { if ($d->inTransaction()) $d->rollBack(); }
