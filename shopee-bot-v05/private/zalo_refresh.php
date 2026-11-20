<?php
declare(strict_types=1);
// CLI helper: exchange the stored Zalo refresh token for a new access token.
// Prints the NEW tokens to this terminal only. Paste them into private/config.php.
// Usage: php zalo_refresh.php   (run from the private/ folder over SSH/terminal)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__.'/core.php';
try {
    $c = cfg();
    foreach (['zalo_app_id', 'zalo_app_secret', 'zalo_refresh_token'] as $k) if (($c[$k] ?? '') === '') throw new RuntimeException('Set '.$k.' in config first.');
    $ch = curl_init('https://oauth.zaloapp.com/v4/oa/access_token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['app_id' => $c['zalo_app_id'], 'grant_type' => 'refresh_token', 'refresh_token' => $c['zalo_refresh_token']]), CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'secret_key: '.$c['zalo_app_secret']], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 4]);
    $raw = curl_exec($ch); curl_close($ch); $r = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($r) || ($r['access_token'] ?? '') === '') throw new RuntimeException('Refresh failed: '.(is_array($r) ? json_encode($r) : 'no response'));
    echo "OK. Update private/config.php NOW (old refresh token is invalidated):\n";
    echo "  'zalo_access_token' => '".$r['access_token']."',\n";
    echo "  'zalo_refresh_token' => '".($r['refresh_token'] ?? $c['zalo_refresh_token'])."',\n";
    echo "Expires in ".(string)($r['expires_in'] ?? '?')." seconds.\n";
} catch (Throwable $e) { fwrite(STDERR, "Zalo refresh failed; check app id/secret, refresh token and outbound access.\n"); exit(1); }
