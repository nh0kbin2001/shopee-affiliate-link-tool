<?php
declare(strict_types=1);
// Loads core.php + web.php for the web pages. Only public/*.php is web-exposed.
// Locates the private folder: CB_PRIVATE_PATH env first, then common layouts.
$cands = [];
if (($e = getenv('CB_PRIVATE_PATH')) !== false && $e !== '') $cands[] = $e;
$cands[] = dirname(__DIR__).'/private';
$cands[] = dirname(__DIR__).'/cashback_private';
$cands[] = dirname(dirname(__DIR__)).'/cashback_private';
$private = null;
foreach ($cands as $p) if (is_file($p.'/core.php')) { $private = $p; break; }
if ($private === null) { http_response_code(503); exit('Configuration missing.'); }
require $private.'/core.php';
require $private.'/web.php';
