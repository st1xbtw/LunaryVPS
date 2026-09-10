<?php
/**
 * Роутер для Render.com (заменяет .htaccess)
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Редирект index.html → /
if (preg_match('#/index\.html$#i', $uri)) {
    $newUri = preg_replace('#/index\.html$#i', '/', $uri);
    header('Location: ' . $newUri, true, 301);
    exit;
}

// Проксирование /sub/* → proxy.php
if (preg_match('#^/sub#i', $uri)) {
    $_GET['__path'] = $uri;
    // Пробрасываем остальные GET-параметры
    parse_str($_SERVER['QUERY_STRING'], $existingParams);
    $_GET = array_merge($existingParams, ['__path' => $uri]);
    require __DIR__ . '/proxy.php';
    exit;
}

// Проксирование /dashboard/* → proxy.php
if (preg_match('#^/dashboard#i', $uri)) {
    $_GET['__path'] = $uri;
    parse_str($_SERVER['QUERY_STRING'], $existingParams);
    $_GET = array_merge($existingParams, ['__path' => $uri]);
    require __DIR__ . '/proxy.php';
    exit;
}

// Проксирование /api/dashboard/* → proxy.php
if (preg_match('#^/api/dashboard#i', $uri)) {
    $_GET['__path'] = $uri;
    parse_str($_SERVER['QUERY_STRING'], $existingParams);
    $_GET = array_merge($existingParams, ['__path' => $uri]);
    require __DIR__ . '/proxy.php';
    exit;
}

// Статические файлы (index.html, 404.html, css, js и т.д.)
$filePath = __DIR__ . $uri;
if ($uri === '/' || $uri === '') {
    $filePath = __DIR__ . '/index.html';
}

if (file_exists($filePath) && is_file($filePath)) {
    // Определяем MIME-тип
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'html' => 'text/html',
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
    ];
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
    }
    readfile($filePath);
    exit;
}

// 404
if (file_exists(__DIR__ . '/404.html')) {
    http_response_code(404);
    readfile(__DIR__ . '/404.html');
} else {
    http_response_code(404);
    echo '404 Not Found';
}
exit;
