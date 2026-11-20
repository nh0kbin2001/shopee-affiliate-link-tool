<?php
declare(strict_types=1);
// Telegram webhook entry. Only public/*.php is web-exposed.
// Locates core.php: CB_PRIVATE_PATH env first, then common hosting layouts.
$cands = [];
if (($e = getenv('CB_PRIVATE_PATH')) !== false && $e !== '') $cands[] = $e;
$cands[] = dirname(__DIR__).'/private';
$cands[] = dirname(__DIR__).'/cashback_private';
$cands[] = dirname(dirname(__DIR__)).'/cashback_private';
$private = null;
foreach ($cands as $p) if (is_file($p.'/core.php')) { $private = $p; break; }
if ($private === null) { http_response_code(503); error_log('Cashback TEST tg webhook: core.php not found.'); exit; }
require $private.'/core.php';
try {
    $c = cfg();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals($c['webhook_secret'], $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '')) { http_response_code(403); exit; }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) { http_response_code(413); exit; }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) { http_response_code(413); exit; }
    $u = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $d = db($c);
    $cb = $u['callback_query'] ?? null;
    if (is_array($cb)) {
        // Inline button press: process, fast-deliver, always answer the press.
        $from = $cb['from'] ?? []; $uid = (int)($from['id'] ?? 0); $cbId = (string)($cb['id'] ?? '');
        if (($from['is_bot'] ?? false) || $uid <= 0 || $cbId === '') { echo 'OK'; exit; }
        $msg = $cb['message'] ?? [];
        $senderKey = in_array(tgKey($uid), $c['allowed_users'], true) ? tgKey($uid) : null;
        $ctx = ['kind' => 'callback', 'cb_id' => $cbId, 'channel' => 'tg', 'target' => (int)($msg['chat']['id'] ?? 0), 'chat_id' => (int)($msg['chat']['id'] ?? 0), 'private' => ($msg['chat']['type'] ?? '') === 'private', 'sender_key' => $senderKey, 'sender_ref' => (string)$uid, 'text' => '', 'data' => (string)($cb['data'] ?? ''), 'key' => 'tgc:'.$cbId];
        if ($ctx['target'] === 0) { echo 'OK'; exit; }
        $answer = '';
        try { $answer = processUpdate($d, $c, $ctx, []); flushForUpdate($d, $c, $ctx); }
        catch (Throwable $e) { error_log('Cashback TEST tg callback failed (details suppressed).'); if ($answer === '') $answer = 'Có lỗi, thử lại sau.'; }
        try { tgAnswer($c, $cbId, $answer); } catch (Throwable $e) { error_log('Cashback TEST tg answer failed (details suppressed).'); }
        header('Content-Type: text/plain'); echo 'OK'; exit;
    }
    if (!isset($u['update_id']) || !is_int($u['update_id'])) { http_response_code(400); echo 'Invalid request'; exit; }
    $m = $u['message'] ?? null;
    if (!is_array($m)) { echo 'OK'; exit; } // Non-message updates are ignored.
    $uid = (int)($m['from']['id'] ?? 0); $chat = (int)($m['chat']['id'] ?? 0);
    if (($m['from']['is_bot'] ?? false) || $uid <= 0 || $chat === 0) { echo 'OK'; exit; }
    $senderKey = in_array(tgKey($uid), $c['allowed_users'], true) ? tgKey($uid) : null;
    $ctx = ['kind' => 'message', 'channel' => 'tg', 'target' => $chat, 'chat_id' => $chat, 'private' => ($m['chat']['type'] ?? '') === 'private', 'sender_key' => $senderKey, 'sender_ref' => (string)$uid, 'text' => (string)($m['text'] ?? ''), 'key' => 'tg:'.$u['update_id']];
    processUpdate($d, $c, $ctx, prepareLinks($c, $ctx, $m));
    // Fast-path: deliver this update's replies now (~seconds); cron sweeps the rest.
    flushForUpdate($d, $c, $ctx);
    header('Content-Type: text/plain'); echo 'OK';
} catch (InvalidArgumentException|JsonException $e) { http_response_code(400); echo 'Invalid request'; }
catch (Throwable $e) { error_log('Cashback TEST tg webhook failed (details suppressed).'); http_response_code(503); echo 'Retry'; }
