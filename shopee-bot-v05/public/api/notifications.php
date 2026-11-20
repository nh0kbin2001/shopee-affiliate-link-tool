<?php
declare(strict_types=1);
require __DIR__.'/../_boot.php';
web_boot();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$u = web_user();
if ($u === null) { http_response_code(401); echo json_encode(['error' => 'unauthenticated']); exit; }
try {
    $c = cfg(); $d = db($c);
    $after = (int)($_GET['after'] ?? 0);
    $unread = (int)q($d, 'SELECT COUNT(*) FROM cb_notifications WHERE user_id=? AND read_at IS NULL', [$u])->fetchColumn();
    $rows = q($d, 'SELECT id,kind,title,body,amount,created_at FROM cb_notifications WHERE user_id=? AND id>? ORDER BY id DESC LIMIT 10', [$u, $after])->fetchAll();
    $items = [];
    foreach (array_reverse($rows) as $r) {
        $items[] = ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'title' => (string)$r['title'], 'body' => (string)$r['body'], 'amount' => $r['amount'] === null ? null : (int)$r['amount'], 'created_at' => (string)$r['created_at']];
    }
    echo json_encode(['unread' => $unread, 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['error' => 'server']);
}
