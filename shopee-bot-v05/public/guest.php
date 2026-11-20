<?php
declare(strict_types=1);
// Start (or resume) a guest session bound to this browser's cookie.
require __DIR__.'/_boot.php';
$c = cfg(); web_boot();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: index.php'); exit; }
csrf_check();
if (!$c['guest_mode']) { header('Location: index.php'); exit; }
if (web_user() === null) {
    $uid = guest_start();
    try { q(db($c), 'INSERT IGNORE INTO cb_users(id) VALUES(?)', [$uid]); } catch (Throwable $e) {}
}
header('Location: dashboard.php'); exit;
