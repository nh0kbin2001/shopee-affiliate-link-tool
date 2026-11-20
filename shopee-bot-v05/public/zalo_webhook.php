<?php
declare(strict_types=1);
// Zalo OA callback entry. Register this URL (with ?secret=) in the Zalo app
// dashboard: https://bot.example.com/zalo_webhook.php?secret=<webhook_secret>
// The query secret replaces Telegram-style header verification for Zalo.
// Only followers can message the OA; strangers get their key for admin approval.
$cands = [];
if (($e = getenv('CB_PRIVATE_PATH')) !== false && $e !== '') $cands[] = $e;
$cands[] = dirname(__DIR__).'/private';
$cands[] = dirname(__DIR__).'/cashback_private';
$cands[] = dirname(dirname(__DIR__)).'/cashback_private';
$private = null;
foreach ($cands as $p) if (is_file($p.'/core.php')) { $private = $p; break; }
if ($private === null) { http_response_code(503); error_log('Cashback TEST zalo webhook: core.php not found.'); exit; }
require $private.'/core.php';
require $private.'/zalo.php';
try {
    $c = cfg();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals($c['webhook_secret'], (string)($_GET['secret'] ?? ''))) { http_response_code(403); exit; }
    if ($c['zalo_access_token'] === '') { http_response_code(503); echo 'Zalo disabled'; exit; }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) { http_response_code(413); exit; }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) { http_response_code(413); exit; }
    $u = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $event = (string)($u['event_name'] ?? '');
    $d = db($c);
    if ($event === 'follow') {
        $sid = (string)($u['sender']['id'] ?? '');
        if ($sid !== '') queue($d, 'zalo:follow:'.$sid.':'.(string)($u['timestamp'] ?? time()), 'zalo', $sid, 'Chào bạn! Key của bạn: '.zaloKey($sid).'. Gửi key này cho admin để được duyệt, sau đó gửi link Shopee để nhận link tracking. [TEST]');
    } elseif ($event === 'user_send_text') {
        $sid = (string)($u['sender']['id'] ?? ''); $mid = (string)($u['message']['msg_id'] ?? '');
        if ($sid === '' || $mid === '') { http_response_code(400); echo 'Invalid request'; exit; }
        $senderKey = in_array(zaloKey($sid), $c['allowed_users'], true) ? zaloKey($sid) : null;
        $ctx = ['kind' => 'message', 'channel' => 'zalo', 'target' => $sid, 'chat_id' => $sid, 'private' => true, 'sender_key' => $senderKey, 'sender_ref' => $sid, 'text' => (string)($u['message']['text'] ?? ''), 'key' => 'zalo:'.$mid];
        processUpdate($d, $c, $ctx, prepareLinks($c, $ctx, ['text' => $ctx['text']]));
        flushForUpdate($d, $c, $ctx);
    }
    // Other events (unfollow, etc.) are acknowledged without action.
    header('Content-Type: text/plain'); echo 'OK';
} catch (InvalidArgumentException|JsonException $e) { http_response_code(400); echo 'Invalid request'; }
catch (Throwable $e) { error_log('Cashback TEST zalo webhook failed (details suppressed).'); http_response_code(503); echo 'Retry'; }
