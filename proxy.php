<?php
/**
 * PHP-прокси для Render.com
 * Автоопределение протокола бэкенда (https/http) + игнор ошибок сертификатов
 */

error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

// ============ НАСТРОЙКИ ============
define('BACKEND_HOST', 'node.ettacent.dev:25601'); // хост и порт Python-сервера
define('PROXY_TIMEOUT', 60);      // макс. время запроса (сек)
define('CONNECT_TIMEOUT', 15);    // макс. время подключения (сек)

// ============ ПОДГОТОВКА ЗАПРОСА ============
$path = $_GET['__path'] ?? '/';

$queryParams = $_GET;
unset($queryParams['__path']);
$debug = isset($queryParams['__debug']);
unset($queryParams['__debug']);
$cleanQuery = http_build_query($queryParams);

$uri = $path . ($cleanQuery ? '?' . $cleanQuery : '');

$method = $_SERVER['REQUEST_METHOD'];
$requestBody = '';
if ($method !== 'GET' && $method !== 'HEAD') {
    $requestBody = file_get_contents('php://input') ?: '';
}

// Заголовки, передаваемые на бэкенд
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
        CURLOPT_SSL_VERIFYPEER => false, // НЕ проверять сертификат (лечит TLS-ошибку)
        CURLOPT_SSL_VERIFYHOST => false, // НЕ проверять hostname сертификата
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

// ============ ВЫПОЛНЕНИЕ: сначала https, потом http ============
$attempts = [];
$final = null;

foreach (['https://', 'http://'] as $scheme) {
    $final = proxyRequest($scheme, $uri, $headers, $method, $requestBody);
    $attempts[] = $scheme . ' => errno ' . $final['errno'] . ' (' . $final['error'] . '), HTTP ' . $final['httpCode'];

    if ($final['errno'] === 0 && $final['httpCode'] > 0) {
        break; // успех — дальше не пробуем
    }
}

ob_end_clean();

// ============ РЕЖИМ ДИАГНОСТИКИ: добавь &__debug=1 к URL ============
if ($debug) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== PROXY DEBUG ===\n";
    echo "URI: {$uri}\n";
    echo "Method: {$method}\n\n";
    echo "Attempts:\n" . implode("\n", $attempts) . "\n\n";
    echo "Response headers:\n" . substr($final['response'], 0, $final['headerSize']) . "\n";
    echo "Response body (first 500 chars):\n" . substr($final['response'], $final['headerSize'], 500) . "\n";
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
    if ($headerLine === '' || strpos($headerLine, ':') === false) {
        continue;
    }
    [$name] = explode(':', $headerLine, 2);
    if (!in_array(strtolower(trim($name)), $skipHeaders, true)) {
        header($headerLine);
    }
}

header('Content-Length: ' . strlen($responseBody));
http_response_code($final['httpCode']);
echo $responseBody;
exit;
