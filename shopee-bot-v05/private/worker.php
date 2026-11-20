<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__.'/core.php';
require __DIR__.'/zalo.php';
try {
    $c = cfg(); $d = db($c);
    $w = relayWatchdog($d, $c);
    if ($w !== '') echo "Relay watchdog: {$w}\n";
    flushOutbox($d, $c, 10);
    $n = pruneOutbox($d, $c);
    $m = pruneNotifications($d);
    echo "Outbox flushed; pruned {$n} old sent rows, {$m} read notifications.\n";
} catch (Throwable $e) { fwrite(STDERR, "Worker failed; inspect configuration/extensions/database. Secrets omitted.\n"); exit(1); }
