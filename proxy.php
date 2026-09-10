<?php
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

define('BACKEND_HOST', 'node.ettacent.dev:25601');
define('PROXY_TIMEOUT', 45);
define('CONNECT_TIMEOUT', 10);

// ============ ПОДГОТОВКА ============
$path = $_GET['__path'] ?? '/';
$queryParams = $_GET;
unset($queryParams['__path']);
$debug = isset($queryParams['__debug']);
unset($queryParams['__debug']);

$method = $_SERVER['REQUEST_METHOD'];
$requestBody = '';
if ($method !== 'GET' && $method !== 'HEAD') {
    $requestBody = file_get_contents('php://input') ?: '';
}

$headers = [
    'X-Forwarded-Proto: https',
    'X-Forwarded-Host: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
    'X-Real-IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
    'X-Forwarded-For: ' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
];
if (isset($_SERVER['CONTENT_TYPE']))       $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
if (isset($_SERVER['HTTP_AUTHORIZATION'])) $headers[] = 'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'];
if (isset($_SERVER['HTTP_ACCEPT']))        $headers[] = 'Accept: ' . $_SERVER['HTTP_ACCEPT'];
if (isset($_SERVER['HTTP_USER_AGENT']))    $headers[] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT'];

// ============ КАНДИДАТЫ URL (разные форматы подписки) ============
$candidates = [];
$qs = http_build_query($queryParams);
$candidates[] = $path . ($qs ? '?' . $qs : '');

// Если /sub?id=xxx — пробуем также /sub/xxx и /sub/xxx?остальные=параметры
if (preg_match('#^/sub/?$#i', $path) && isset($queryParams['id'])) {
    $id = $queryParams['id'];
    $rest = $queryParams;
    unset($rest['id']);
    $restQs = http_build_query($rest);
    $candidates[] = '/sub/' . rawurlencode($id) . ($restQs ? '?' . $restQs : '');
    $candidates[] = '/sub/' . rawurlencode($id);
}
$candidates = array_values(array_unique($candidates));

// ============ ФУНКЦИЯ ЗАПРОСА ============
function proxyRequest($scheme, $uri, $headers, $method, $requestBody) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $scheme . BACKEND_HOST . $uri,
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
        if ($requestBody !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        }
    }
    $response = curl_exec($ch);
    $result = [
        'errno'      => curl_errno($ch),
        'error'      => curl_error($ch),
        'headerSize' => curl_getinfo($ch, CURLINFO_HEADER_SIZE),
        'httpCode'   => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'response'   => $response,
    ];
    curl_close($ch);
    return $result;
}

// ============ ПРОВЕРКА: ПОХОЖЕ ЛИ НА ПОДПИСКУ (не HTML) ============
function isSubscriptionLike($result) {
    if ($result['errno'] !== 0 || $result['httpCode'] < 200 || $result['httpCode'] >= 400) {
        return false;
    }
    $respHeaders = substr($result['response'], 0, $result['headerSize']);
    if (preg_match('/^Content-Type:\s*text\/html/im', $respHeaders)) {
        return false;
    }
    $body = substr($result['response'], $result['headerSize']);
    $head = strtolower(ltrim(substr($body, 0, 200)));
    if (strpos($head, '<!doctype') === 0 || strpos($head, '<html') === 0) {
        return false;
    }
    return true;
}

// ============ ПЕРЕБОР КАНДИДАТОВ ============
$attempts = [];
$final = null;
$found = false;

foreach ($candidates as $uri) {
    foreach (['http://', 'https://'] as $scheme) {
        $final = proxyRequest($scheme, $uri, $headers, $method, $requestBody);
        $ok = isSubscriptionLike($final);
        $attempts[] = $scheme . $uri . ' => errno ' . $final['errno'] . ', HTTP ' . $final['httpCode'] . ($ok ? '  [ПОДПИСКА НАЙДЕНА]' : '  [не подписка]');

        if ($final['errno'] === 0 && $final['httpCode'] > 0) {
            if ($ok) $found = true;
            break; // бэкенд ответил — протокол верный, схему не меняем
        }
        // errno != 0 → пробуем другую схему
    }
    if ($found) break;
}

ob_end_clean();

// ============ ДИАГНОСТИКА ============
if ($debug) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== PROXY DEBUG ===\nMethod: {$method}\n\nAttempts:\n" . implode("\n", $attempts) . "\n\n";
    echo "Response headers:\n" . substr($final['response'], 0, $final['headerSize']) . "\n";
    echo "Body (first 500 chars):\n" . substr($final['response'], $final['headerSize'], 500) . "\n";
    exit;
}

// ============ ОШИБКА СОЕДИНЕНИЯ ============
if ($final['errno'] !== 0 || $final['httpCode'] === 0) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Proxy Error: ' . $final['error'] . ' (errno ' . $final['errno'] . ')';
    exit;
}

// ============ ОТВЕТ КЛИЕНТУ ============
$responseHeaders = substr($final['response'], 0, $final['headerSize']);
$responseBody    = substr($final['response'], $final['headerSize']);

$skipHeaders = ['transfer-encoding', 'connection', 'keep-alive', 'proxy-connection'];
foreach (explode("\r\n", trim($responseHeaders)) as $headerLine) {
    if ($headerLine === '' || strpos($headerLine, ':') === false) continue;
    [$name] = explode(':', $headerLine, 2);
    if (!in_array(strtolower(trim($name)), $skipHeaders, true)) {
        header($headerLine);
    }
}

header('Content-Length: ' . strlen($responseBody));
http_response_code($final['httpCode']);
echo $responseBody;
exit;
