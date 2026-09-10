<?php
/**
 * Роутер для Render.com
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Health-check для UptimeRobot и проверки живости
if ($uri === '/healthz') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'OK ' . date('c');
    exit;
}

// Редирект /index.html → /
if (preg_match('#/index\.html$#i', $uri)) {
    header('Location: ' . preg_replace('#/index\.html$#i', '/', $uri), true, 301);
    exit;
}

// Проксирование /sub, /dashboard, /api/dashboard → proxy.php
if (preg_match('#^/(sub|dashboard|api/dashboard)#i', $uri)) {
    parse_str($_SERVER['QUERY_STRING'], $existingParams);
    $_GET = array_merge($existingParams, ['__path' => $uri]);
    require __DIR__ . '/proxy.php';
    exit;
}

// Статические файлы
$filePath = __DIR__ . $uri;
if ($uri === '/' || $uri === '') {
    $filePath = __DIR__ . '/index.html';
}

if (file_exists($filePath) && is_file($filePath)) {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'html' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript',
        'json' => 'application/json', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ];
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
    }
    readfile($filePath);
    exit;
}

// 404
http_response_code(404);
if (file_exists(__DIR__ . '/404.html')) {
    readfile(__DIR__ . '/404.html');
} else {
    echo '404 Not Found';
}
exit;
