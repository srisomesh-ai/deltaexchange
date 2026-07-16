<?php
/**
 * delta-proxy.php  —  Delta Exchange India signing proxy
 * ------------------------------------------------------
 * Holds your API key/secret SERVER-SIDE and signs authenticated requests.
 * The browser NEVER sees the secret.
 *
 * SETUP:
 *   1. Put your keys in $API_KEY / $API_SECRET below.
 *   2. Upload to Hostinger (e.g. /public_html/delta-proxy.php).
 *   3. Whitelist your Hostinger server's OUTBOUND IP in Delta > API settings.
 *      (Find it: create phpinfo() or run  curl ifconfig.me  from hPanel terminal.)
 *   4. Set $ALLOWED_ORIGIN to your dashboard URL to lock down CORS.
 *
 * SECURITY:
 *   - Add a shared secret ($PROXY_TOKEN) so only your dashboard can call this.
 *   - Keep this file's URL private. Anyone who can POST here can trade your account.
 */

// ====== CONFIG ======================================================
$API_KEY      = 'PASTE_YOUR_DELTA_API_KEY';
$API_SECRET   = 'PASTE_YOUR_DELTA_API_SECRET';
$BASE_URL     = 'https://api.india.delta.exchange';   // India production
$PROXY_TOKEN  = 'CHANGE_ME_to_a_long_random_string';  // must match dashboard
$ALLOWED_ORIGIN = '*';   // set to 'https://yourdomain.com' in production
// ====================================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . $ALLOWED_ORIGIN);
header('Access-Control-Allow-Headers: Content-Type, X-Proxy-Token');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ---- read request ----
$raw = file_get_contents('php://input');
$req = json_decode($raw, true);
if (!is_array($req)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'bad json']);
    exit;
}

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
