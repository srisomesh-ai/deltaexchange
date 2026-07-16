<?php
/**
 * delta-proxy.php  —  Delta Exchange India signing proxy
 * ------------------------------------------------------
 * Holds your API key/secret SERVER-SIDE and signs authenticated requests.
 * The browser NEVER sees the secret.
 *
 * SETUP:
 *   1. Create delta-config.php ON THE SERVER (see below) with your keys.
 *      This file is NOT in the repo, so your secrets never touch GitHub and
 *      are never overwritten when this proxy is redeployed.
 *   2. Upload this file to Hostinger (e.g. /public_html/delta-proxy.php).
 *   3. Whitelist your Hostinger server's OUTBOUND IP in Delta > API settings.
 *      (Find it: run  curl ifconfig.me  from hPanel terminal.)
 *
 * delta-config.php  (create by hand on server, same folder as this file):
 *   <?php
 *   $API_KEY      = 'your_delta_api_key';
 *   $API_SECRET   = 'your_delta_api_secret';
 *   $PROXY_TOKEN  = 'a_password_you_invent';   // must match dashboard ⚙ token
 *   $ALLOWED_ORIGIN = '*';   // or 'https://yourdomain.com' to lock down
 *
 * SECURITY:
 *   - Keep this file's URL private. Anyone who can POST here + knows the token
 *     can trade your account.
 */

// ====== READ INPUT FIRST (so we can pick env), THEN LOAD CONFIG ======
$raw = file_get_contents('php://input');
$req = json_decode($raw, true);
if (!is_array($req)) $req = [];   // allow empty for OPTIONS/whatismyip

// env: 'testnet' loads delta-config-testnet.php + testnet base url
$env = ($req['env'] ?? '') === 'testnet' ? 'testnet' : 'live';
if ($env === 'testnet') {
    $BASE_URL = 'https://cdn-ind.testnet.deltaex.org';
    $cfgFile  = __DIR__ . '/delta-config-testnet.php';
} else {
    $BASE_URL = 'https://api.india.delta.exchange';
    $cfgFile  = __DIR__ . '/delta-config.php';
}
if (!file_exists($cfgFile)) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>basename($cfgFile).' missing on server. Create it with your '.$env.' API keys.']);
    exit;
}
require $cfgFile;   // defines $API_KEY, $API_SECRET, $PROXY_TOKEN, $ALLOWED_ORIGIN
if (!isset($ALLOWED_ORIGIN)) $ALLOWED_ORIGIN = '*';
if (empty($API_KEY) || empty($API_SECRET) || empty($PROXY_TOKEN)) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>basename($cfgFile).' is missing one of API_KEY / API_SECRET / PROXY_TOKEN.']);
    exit;
}
// ====================================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . $ALLOWED_ORIGIN);
header('Access-Control-Allow-Headers: Content-Type, X-Proxy-Token');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ---- request already parsed at top into $req ----

// ---- PUBLIC passthrough (market data): no token, no signature ----
// Dashboard sends {"public":true,"method":"GET","path":"/v2/tickers/BTCUSD"}
// Only GET requests to whitelisted public paths are allowed.
$isPublic = !empty($req['public']);
if ($isPublic) {
    $pMethod = strtoupper($req['method'] ?? 'GET');
    $pPath   = $req['path']  ?? '';
    $pQuery  = $req['query'] ?? '';
    $publicOk = (strpos($pPath, '/v2/products') === 0)
             || (strpos($pPath, '/v2/tickers') === 0)
             || (strpos($pPath, '/v2/l2orderbook') === 0)
             || (strpos($pPath, '/v2/history/candles') === 0);
    if ($pMethod !== 'GET' || !$publicOk) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'public path not allowed']);
        exit;
    }
    $ch = curl_init($BASE_URL . $pPath . $pQuery);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: bgps-delta-proxy', 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    http_response_code($resp === false ? 502 : $status);
    echo ($resp === false) ? json_encode(['success'=>false,'error'=>'upstream fail']) : $resp;
    exit;
}

// ---- gate: shared token (authenticated calls only) ----
$hdrToken = $_SERVER['HTTP_X_PROXY_TOKEN'] ?? '';
if (!hash_equals($PROXY_TOKEN, $hdrToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

$method  = strtoupper($req['method'] ?? 'GET');       // GET / POST / PUT / DELETE
$path    = $req['path']   ?? '';                       // e.g. /v2/orders
$query   = $req['query']  ?? '';                       // e.g. ?product_id=27&state=open  (include leading ?)
$bodyArr = $req['body']   ?? null;                     // array|null

if ($path === '' || strpos($path, '/v2/') !== 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid path']);
    exit;
}

$bodyStr   = ($bodyArr === null) ? '' : json_encode($bodyArr, JSON_UNESCAPED_SLASHES);
$timestamp = (string) time();

// prehash: method + timestamp + path + query + body
$prehash   = $method . $timestamp . $path . $query . $bodyStr;
$signature = hash_hmac('sha256', $prehash, $API_SECRET);

$url = $BASE_URL . $path . $query;

$ch = curl_init($url);
$headers = [
    'api-key: ' . $API_KEY,
    'timestamp: ' . $timestamp,
    'signature: ' . $signature,
    'User-Agent: bgps-delta-proxy',
    'Content-Type: application/json',
];
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 15,
]);
if ($method !== 'GET' && $bodyStr !== '') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyStr);
}

$resp   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'curl: ' . $err]);
    exit;
}

http_response_code($status);
echo $resp;
