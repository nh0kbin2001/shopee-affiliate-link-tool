<?php
declare(strict_types=1);
require __DIR__.'/_boot.php';
web_boot();
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
header('Location: index.php'); exit;
