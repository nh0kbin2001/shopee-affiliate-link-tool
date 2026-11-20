<?php
declare(strict_types=1);
// Zalo OA channel helpers (TEST). Requires an OA linked to a Zalo app,
// an access token, and followers messaging the OA first (OA cannot initiate).
function zaloApi(array $c, string $path, array $data): array {
    if ($c['zalo_access_token'] === '') throw new RuntimeException('Zalo not configured.');
    $ch = curl_init('https://openapi.zalo.me'.$path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data, JSON_THROW_ON_ERROR), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'access_token: '.$c['zalo_access_token']], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 3]);
    $raw = curl_exec($ch); curl_close($ch); $r = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($r) || ($r['error'] ?? -1) !== 0) throw new RuntimeException('Zalo call failed: '.(is_array($r) ? (string)($r['message'] ?? 'unknown') : 'no response'));
    return $r;
}
function zaloSend(array $c, string $uid, string $text): void {
    // Customer-service reply to a follower. Text capped per Zalo limits.
    zaloApi($c, '/v3.0/oa/message/cs', ['recipient' => ['user_id' => $uid], 'message' => ['text' => mb_substr($text, 0, 1800)]]);
}
