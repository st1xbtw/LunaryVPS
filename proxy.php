<?php
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

define('BACKEND_HOST', 'node.ettacent.dev:25601');
define('PROXY_TIMEOUT', 30);
define('CONNECT_TIMEOUT', 10);

$path = $_GET['__path'] ?? '/';
$qp = $_GET;
unset($qp['__path']);
$debug = isset($qp['__debug']);
unset($qp['__debug']);

$method = $_SERVER['REQUEST_METHOD'];
$reqBody = '';
if ($method !== 'GET' && $method !== 'HEAD') {
    $reqBody = file_get_contents('php://input') ?: '';
}

$inUA = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isBrowser = (stripos($inUA, 'Mozilla') !== false);

// ---- Кандидаты URI ----
$qs = http_build_query($qp);
$cands = [$path . ($qs ? '?' . $qs : '')];
if (isset($qp['id'])) {
    $cands[] = '/sub/' . $qp['id'];
    $cands[] = '/sub/' . $qp['id'] . '/';
}
$cands = array_values(array_unique($cands));

// ---- Кандидаты User-Agent (VPN-клиенты) ----
$uas = [];
if (!$isBrowser && $inUA !== '') $uas[] = $inUA; // настоящий UA от Happ
$uas = array_merge($uas, [
    'Happ/2.6.0',
    'v2rayNG/1.9.6',
    'ClashforWindows/0.20.39',
    'sing-box/1.9.5',
    'Shadowrocket/1800',
]);
if ($isBrowser) $uas[] = $inUA;
$uas = array_values(array_unique(array_filter($uas)));

function makeHeaders($ua) {
    $h = [
        'X-Forwarded-Proto: https',
        'X-Forwarded-Host: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'X-Real-IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
        'X-Forwarded-For: ' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
        'Accept: */*',
        'User-Agent: ' . $ua,
    ];
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h[] = 'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'];
    return $h;
}

function proxyRequest($uri, $headers, $method, $reqBody) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'http://' . BACKEND_HOST . $uri,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => PROXY_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    if ($method !== 'GET' && $method !== 'HEAD') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($reqBody !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $reqBody);
    }
    $response = curl_exec($ch);
    $r = [
        'errno'      => curl_errno($ch),
        'error'      => curl_error($ch),
        'headerSize' => curl_getinfo($ch, CURLINFO_HEADER_SIZE),
        'httpCode'   => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'response'   => $response,
    ];
    curl_close($ch);
    return $r;
}

function isSubscription($r) {
    if ($r['errno'] !== 0 || $r['httpCode'] < 200 || $r['httpCode'] >= 400) return false;
    $h = substr($r['response'], 0, $r['headerSize']);
    if (preg_match('/^Content-Type:\s*text\/html/im', $h)) return false;
    $b = substr($r['response'], $r['headerSize']);
    if ($b === '') return false;
    $head = strtolower(ltrim(substr($b, 0, 200)));
    return !(strpos($head, '<!doctype') === 0 || strpos($head, '<html') === 0);
}

// ---- Перебор: URI × User-Agent ----
$attempts = [];
$final = null;
$found = false;

foreach ($cands as $uri) {
    $lastCode = 0;
    foreach ($uas as $ua) {
        $final = proxyRequest($uri, makeHeaders($ua), $method, $reqBody);
        $ok = isSubscription($final);
        $attempts[] = $uri . ' | UA="' . $ua . '" => HTTP ' . $final['httpCode'] . ($ok ? '  [ПОДПИСКА!]' : '');
        $lastCode = $final['httpCode'];
        if ($final['errno'] !== 0) break 2;          // сеть мертва — стоп
        if ($ok) { $found = true; break 2; }         // нашли конфиг
        if ($lastCode === 404) break;                // путь не существует, UA не важен
    }
}

ob_end_clean();

// ---- ДИАГНОСТИКА ----
if ($debug) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== PROXY DEBUG ===\nMethod: {$method}\nIncoming UA: {$inUA}\n\n";
    echo "Attempts:\n" . implode("\n", $attempts) . "\n\n";
    echo "Last headers:\n" . substr($final['response'], 0, $final['headerSize']) . "\n";
    $body = substr($final['response'], $final['headerSize']);
    echo "Last body (800 chars):\n" . substr($body, 0, 800) . "\n";
    // Если снова HTML — ищем внутри любые ссылки на подписку
    if (preg_match('/^Content-Type:\s*text\/html/im', substr($final['response'], 0, $final['headerSize']))) {
        preg_match_all('#["\'\(]([^"\'\s\)]*(?:sub|token|id=)[^"\'\s\)]*)["\'\)]#i', $body, $m);
        $links = array_values(array_unique($m[1]));
        echo "\nLinks found inside HTML:\n" . implode("\n", array_slice($links, 0, 25)) . "\n";
    }
    exit;
}

// ---- Ошибка сети ----
if ($final === null || $final['errno'] !== 0 || $final['httpCode'] === 0) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Proxy Error: ' . ($final['error'] ?? 'no response');
    exit;
}

// ---- Ответ клиенту ----
$responseHeaders = substr($final['response'], 0, $final['headerSize']);
$responseBody    = substr($final['response'], $final['headerSize']);

$skip = ['transfer-encoding', 'connection', 'keep-alive', 'proxy-connection'];
foreach (explode("\r\n", trim($responseHeaders)) as $line) {
    if ($line === '' || strpos($line, ':') === false) continue;
    [$name] = explode(':', $line, 2);
    if (!in_array(strtolower(trim($name)), $skip, true)) header($line);
}

header('Content-Length: ' . strlen($responseBody));
http_response_code($final['httpCode']);
echo $responseBody;
exit;
