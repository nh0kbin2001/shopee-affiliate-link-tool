<?php
declare(strict_types=1);
// Optional click counter: /go.php?t=<token> records a click then 302s to the
// affiliate link. Only clicks from the web are counted (bot links are direct).
require __DIR__.'/_boot.php';
$t = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{20}$/', $t)) { http_response_code(404); exit('Not found'); }
try {
    $c = cfg(); $d = db($c);
    $row = q($d, 'SELECT url FROM cb_links WHERE token=?', [$t])->fetch();
    if (!$row) { http_response_code(404); exit('Not found'); }
    // Rate-limit per session to avoid inflating counts on refresh.
    $seen = $_SESSION['clicks'] ?? [];
    if (!in_array($t, $seen, true)) {
        q($d, 'INSERT INTO cb_clicks(token) VALUES(?)', [$t]);
        $seen[] = $t; $_SESSION['clicks'] = $seen;
    }
    header('Location: '.(string)$row['url'], true, 302); exit;
} catch (Throwable $e) { http_response_code(500); exit('Server error'); }
