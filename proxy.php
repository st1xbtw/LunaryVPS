<?php
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

// ⚙️ НАСТРОЙКИ
define('BACKEND_URL', 'http://node.ettacent.dev:25601');
define('PROXY_TIMEOUT', 60);
define('FORWARDED_HOST', $_SERVER['HTTP_HOST'] ?? 'localhost');

// Получаем целевой путь
$path = $_GET['__path'] ?? '/';
$queryParams = $_GET;
unset($queryParams['__path']);
$cleanQuery = http_build_query($queryParams);

$url = BACKEND_URL . $path . ($cleanQuery ? '?' . $cleanQuery : '');

// Заголовки для бэкенда
$headers = [
    'X-Forwarded-Proto: https',
    'X-Forwarded-Host: ' . FORWARDED_HOST,
    'X-Real-IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
    'X-Forwarded-For: ' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
];

if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
}
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $headers[] = 'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'];
}
if (isset($_SERVER['HTTP_ACCEPT'])) {
    $headers[] = 'Accept: ' . $_SERVER['HTTP_ACCEPT'];
}
if (isset($_SERVER['HTTP_USER_AGENT'])) {
    $headers[] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT'];
}

// cURL запрос
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => PROXY_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_HEADER         => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'HEAD') {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $body = file_get_contents('php://input');
    if ($body !== '' && $body !== false) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
}

$response = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

ob_end_clean();

// Обработка ошибок соединения
if ($errno) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Proxy Error: {$error}";
    exit;
}

// Разделение заголовков и тела
$responseHeaders = substr($response, 0, $headerSize);
$responseBody = substr($response, $headerSize);

// Пропускаем только опасные заголовки
$skipHeaders = [
    'transfer-encoding',
    'connection',
    'keep-alive',
    'proxy-connection',
];

foreach (explode("\r\n", trim($responseHeaders)) as $headerLine) {
    if ($headerLine === '' || strpos($headerLine, ':') === false) {
        continue;
    }
    [$name] = explode(':', $headerLine, 2);
    $lowerName = strtolower(trim($name));
    
    if (!in_array($lowerName, $skipHeaders, true)) {
        header($headerLine);
    }
}

header('Content-Length: ' . strlen($responseBody));
http_response_code($httpCode);
echo $responseBody;
exit;
