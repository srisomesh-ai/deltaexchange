<?php
/**
 * key-test.php — one-tap Delta TESTNET key checker.
 * Visit this file in your browser. It reads delta-config-testnet.php,
 * signs a real request to the demo exchange, and tells you plainly
 * whether the key works, what the balance is, or exactly what's wrong.
 * Safe: read-only request, never prints your secret.
 */
header('Content-Type: text/html; charset=utf-8');
echo "<body style='font-family:system-ui;max-width:640px;margin:40px auto;line-height:1.6'>";
echo "<h2>Delta TESTNET key check</h2>";

$cfg = __DIR__ . '/delta-config-testnet.php';
if (!is_file($cfg)) { echo "<p>❌ <b>delta-config-testnet.php not found</b> next to this file.</p>"; exit; }
require $cfg;
if (empty($API_KEY) || empty($API_SECRET)) { echo "<p>❌ <b>API_KEY / API_SECRET empty</b> in delta-config-testnet.php.</p>"; exit; }
echo "<p>✓ config loaded — key starts with <code>" . htmlspecialchars(substr($API_KEY,0,6)) . "…</code></p>";

$base = 'https://cdn-ind.testnet.deltaex.org';
$path = '/v2/wallet/balances';
$ts   = (string) time();
$sig  = hash_hmac('sha256', 'GET' . $ts . $path, $API_SECRET);

$ch = curl_init($base . $path);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'api-key: ' . $API_KEY,
        'timestamp: ' . $ts,
        'signature: ' . $sig,
        'User-Agent: hostinger-key-test',
        'Content-Type: application/json',
    ],
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) { echo "<p>❌ <b>network error:</b> " . htmlspecialchars($err) . "</p>"; exit; }
$j = json_decode($resp, true);

if ($http === 200 && !empty($j['success'])) {
    echo "<p style='font-size:20px'>✅ <b>KEY WORKS</b></p><ul>";
    $any=false;
    foreach (($j['result'] ?? []) as $b) {
        $bal = $b['available_balance'] ?? ($b['balance'] ?? '0');
        if ((float)$bal > 0) { echo "<li><b>" . htmlspecialchars($b['asset_symbol'] ?? '?') . "</b>: " . htmlspecialchars($bal) . "</li>"; $any=true; }
    }
    if(!$any) echo "<li>(key valid, but no funded assets — top up the demo wallet)</li>";
    echo "</ul><p>You're good — Connect in the dashboard will work.</p>";
} else {
    $code = $j['error']['code'] ?? ($j['error'] ?? 'unknown');
    echo "<p style='font-size:20px'>❌ <b>KEY REJECTED</b> (HTTP $http)</p>";
    echo "<p>Delta says: <code>" . htmlspecialchars(is_string($code)?$code:json_encode($code)) . "</code></p><ul>";
    $s = is_string($code) ? $code : json_encode($j);
    if (stripos($s,'ip') !== false) echo "<li>→ <b>Whitelist this server's IP</b> in the demo key settings: <code>2a02:4780:11:1982:0:3838:2d1c:1</code></li>";
    if (stripos($s,'signature') !== false) echo "<li>→ <b>Secret is wrong</b> — re-copy the API secret into delta-config-testnet.php (no spaces).</li>";
    if (stripos($s,'invalid_api_key') !== false || stripos($s,'unauthorized') !== false) echo "<li>→ <b>Key is wrong/inactive</b> — re-copy the API key, confirm it's a TESTNET key (not live), and that it has read + trade permissions.</li>";
    echo "<li>Raw response (first 300 chars): <code>" . htmlspecialchars(substr($resp,0,300)) . "</code></li></ul>";
}
echo "</body>";
