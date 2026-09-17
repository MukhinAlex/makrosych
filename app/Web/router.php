<?php

declare(strict_types=1);

/**
 * Маршрутизатор встроенного веб-сервера PHP.
 *
 * Запросы /api/ передаются HTTP-обработчику, остальное — статические файлы
 * интерфейса. Страница интерфейса получает токен приложения, чтобы обращаться к API.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Lib\Paths;

$uri = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$webDir = __DIR__;

if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/../Api/index.php';

    return true;
}

if ($uri === '/' || $uri === '/index.html') {
    $token = '';
    $tokenFile = Paths::tokenFile();
    if (is_file($tokenFile)) {
        $token = trim((string) file_get_contents($tokenFile));
    }
    if ($token === '') {
        $token = bin2hex(random_bytes(24));
        file_put_contents($tokenFile, $token);
    }

    $html = (string) file_get_contents($webDir . '/index.html');
    $html = str_replace('__APP_TOKEN__', htmlspecialchars($token, ENT_QUOTES), $html);

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $html;

    return true;
}

// Статические файлы — только из каталога интерфейса
$candidate = realpath($webDir . $uri);
$webRoot = realpath($webDir);
if ($candidate !== false && $webRoot !== false && str_starts_with($candidate, $webRoot) && is_file($candidate)) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Не найдено';

return true;
