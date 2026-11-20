<?php
declare(strict_types=1);
// Link relay endpoint for link-relay-extension (Chrome, admin's affiliate tab).
// Auth: header X-Relay-Key = config relay_key (64 hex). JSON in, JSON out.
//   {"action":"pull","limit":3}  -> {"jobs":[{"id":1,"sub_id":"...","origin_url":"https://shopee.vn/product/1/2"}]}
//   {"action":"push","results":[{"id":1,"ok":true,"short_link":"https://s.shopee.vn/..."},{"id":2,"ok":false,"error":"..."}]}
//   {"action":"ping","status":"ok|paused","note":"..."} -> {"ok":true}
require __DIR__.'/../_boot.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function relayOut(int $code, array $body): void { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') relayOut(405, ['error' => 'method']);
try { $c = cfg(); } catch (Throwable $e) { relayOut(503, ['error' => 'config']); }
$key = (string)($_SERVER['HTTP_X_RELAY_KEY'] ?? '');
if ($c['relay_key'] === '' || !hash_equals((string)$c['relay_key'], $key)) relayOut(401, ['error' => 'auth']);
$raw = file_get_contents('php://input', false, null, 0, 65536);
$in = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($in)) relayOut(400, ['error' => 'json']);
try {
    $d = db($c);
    $act = (string)($in['action'] ?? '');
    if ($act === 'pull') {
        relayOut(200, ['jobs' => relayPull($d, (int)($in['limit'] ?? 3))]);
    } elseif ($act === 'push') {
        $res = is_array($in['results'] ?? null) ? array_slice($in['results'], 0, 10) : [];
        $out = [];
        foreach ($res as $r) {
            if (!is_array($r) || !is_int($r['id'] ?? null)) continue;
            $out[] = ['id' => $r['id'], 'result' => relayPush($d, $r['id'], ($r['ok'] ?? false) === true, is_string($r['short_link'] ?? null) ? $r['short_link'] : '', is_string($r['error'] ?? null) ? $r['error'] : '')];
        }
        setSetting($d, 'relay_seen_at', date('Y-m-d H:i:s'));
        relayOut(200, ['results' => $out]);
    } elseif ($act === 'ping') {
        $st = in_array($in['status'] ?? '', ['ok', 'paused'], true) ? (string)$in['status'] : 'ok';
        setSetting($d, 'relay_seen_at', date('Y-m-d H:i:s'));
        setSetting($d, 'relay_status', $st.(is_string($in['note'] ?? null) && $in['note'] !== '' ? ' — '.mb_substr($in['note'], 0, 300) : ''));
        relayOut(200, ['ok' => true]);
    }
    relayOut(400, ['error' => 'action']);
} catch (Throwable $e) {
    error_log('relay: '.$e->getMessage());
    relayOut(500, ['error' => 'server']);
}
