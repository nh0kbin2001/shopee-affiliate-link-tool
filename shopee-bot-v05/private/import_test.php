<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__.'/core.php';
// TEST-only local adapter contract; not a Shopee scraper or conversion endpoint.
try {
    $c = cfg(); $path = $argv[1] ?? '';
    if (!$path || !is_file($path) || filesize($path) > 100000) throw new InvalidArgumentException('Invalid event file.');
    $events = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (isset($events['event_id'])) $events = [$events];
    if (!is_array($events) || count($events) > 100) throw new InvalidArgumentException('Invalid batch.');
    $d = db($c); atomic($d, function () use ($d, $c, $events) { foreach ($events as $e) conversion($d, $c, $e); });
    echo "TEST events processed. Delivery by cron. No real Shopee data.\n";
} catch (Throwable $e) { fwrite(STDERR, "TEST import rejected; no partial batch committed. Check file fields and tracking token.\n"); exit(1); }
