<?php

declare(strict_types=1);

/**
 * Инициализация приложения: константы путей, автозагрузка, подключение PhpSpreadsheet.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('APP_DIR')) {
    define('APP_DIR', APP_ROOT . '/app');
}

// Версия программы берётся из файла VERSION в корне — при обновлении достаточно
// заменить файлы программы, оставив каталог data/ нетронутым.
if (!defined('APP_VERSION')) {
    $versionFile = APP_ROOT . '/VERSION';
    $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
    define('APP_VERSION', $version !== '' ? $version : '0.0.0');
}

// PhpSpreadsheet: сначала собственная сборка внутри program/vendor,
// затем общая библиотека родительского проекта (режим разработки).
$vendorCandidates = [
    APP_ROOT . '/vendor/autoload.php',
    dirname(APP_ROOT) . '/vendor/autoload.php',
];
foreach ($vendorCandidates as $vendorFile) {
    if (is_file($vendorFile)) {
        require_once $vendorFile;
        break;
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = APP_DIR . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
