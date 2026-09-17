<?php

declare(strict_types=1);

/**
 * Сборка архива программы для раздачи.
 *
 * Запуск из каталога program:
 *   php tools/make_release.php                  полный архив
 *   php tools/make_release.php --trim           без файлов PHP, которые программе не нужны
 *   php tools/make_release.php --out=путь.zip   свой путь к архиву
 *
 * Каталог data/ в архив не попадает никогда: там сценарии пользователя, загруженные
 * файлы и ключ API. Проверка в конце сборки не даёт собрать такой архив по ошибке.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

/** Файлы программы в корне каталога. */
const RELEASE_FILES = [
    'Макросыч.exe',
    'start.bat',
    'stop.bat',
    'README.md',
    'VERSION',
    'LICENSE',
    'NOTICE',
    'composer.json',
    'composer.lock',
];

/**
 * Каталоги программы.
 *
 * PLAN.md и documentation_makros.md — внутренние документы, в раздачу не идут.
 */
const RELEASE_DIRS = ['app', 'tools', 'launcher', 'runtime', 'vendor'];

/** Файлы, нужные только в каталоге разработки: они работают с образцами files/ и 0104/. */
const RELEASE_SKIP = ['tools/selftest.php', 'tools/apitest.php', 'tools/seed_recipes.php'];

/** Что убирает --trim: файлы PHP, не подключённые в runtime/php/php.ini. */
const RELEASE_TRIM = [
    'runtime/php/phpdbg.exe',
    'runtime/php/php-cgi.exe',
    'runtime/php/php8embed.lib',
    'runtime/php/deplister.exe',
    'runtime/php/phar.phar',
    'runtime/php/pharcommand.phar',
    'runtime/php/phar.phar.bat',
    'runtime/php/snapshot.txt',
    'runtime/php/news.txt',
    'runtime/php/php.ini-development',
    'runtime/php/php.ini-production',
    'runtime/php/libpq.dll',            // PostgreSQL: расширения pgsql программе не нужны
    'runtime/php/libenchant2.dll',      // проверка орфографии enchant не используется
    'runtime/php/glib-2.dll',
    'runtime/php/gmodule-2.dll',
    'runtime/php/gobject-2.dll',
];

/** Файлы, которые не нужны в архиве никогда. */
function release_junk(string $path): bool
{
    $name = basename($path);

    return str_ends_with($name, '.log')
        || str_ends_with($name, '.tmp')
        || in_array($name, ['Thumbs.db', 'Desktop.ini', '.gitignore', '.gitattributes'], true);
}

/** Урезаемые файлы: список выше плюс библиотеки ICU — они нужны только расширению intl. */
function release_trimmed(string $path): bool
{
    if (in_array($path, RELEASE_TRIM, true)) {
        return true;
    }
    if (str_starts_with($path, 'runtime/php/dev/')) {
        return true;
    }

    return preg_match('~^runtime/php/icu[a-z]*[0-9]+\.dll$~', $path) === 1;
}

/** @param array<int, string> $args */
function release_parse_args(array $args): array
{
    $out = null;
    $trim = false;

    foreach ($args as $arg) {
        if ($arg === '--trim') {
            $trim = true;
            continue;
        }
        if (str_starts_with($arg, '--out=')) {
            $out = substr($arg, 6);
            continue;
        }

        fwrite(STDERR, "Неизвестный параметр: {$arg}\n");
        fwrite(STDERR, "Использование: php tools/make_release.php [--trim] [--out=путь.zip]\n");
        exit(1);
    }

    return [$out, $trim];
}

/**
 * Файлы архива: относительный путь => путь на диске.
 *
 * @return array<string, string>
 */
function release_collect(string $root): array
{
    $files = [];

    foreach (RELEASE_FILES as $name) {
        $files[$name] = $root . '/' . $name;
    }

    foreach (RELEASE_DIRS as $dir) {
        $base = $root . '/' . $dir;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }

            $relative = $dir . '/' . str_replace('\\', '/', substr($item->getPathname(), strlen($base) + 1));
            $files[$relative] = $item->getPathname();
        }
    }

    ksort($files);

    return $files;
}

/**
 * Обязательные файлы сборки: без них архив раздавать нельзя.
 *
 * @return array<int, string>
 */
function release_missing(string $root): array
{
    $required = array_merge(RELEASE_FILES, RELEASE_DIRS, [
        'app/bootstrap.php',
        'app/Web/index.html',
        'app/Web/router.php',
        'launcher/Makrosych.cs',
        'launcher/makrosych.ico',
        'runtime/php/php.exe',
        'runtime/php/php.ini',
        'runtime/php/extras/ssl/cacert.pem',
        'vendor/autoload.php',
        'vendor/phpoffice/phpspreadsheet/LICENSE',
    ]);

    $missing = [];
    foreach ($required as $name) {
        $path = $root . '/' . $name;
        if (!is_file($path) && !is_dir($path)) {
            $missing[] = $name;
        }
    }

    return $missing;
}

[$out, $trim] = release_parse_args(array_slice($argv, 1));

$root = APP_ROOT;
$folder = 'Макросыч-' . APP_VERSION;          // каталог внутри архива — по-русски, как увидит пользователь
$archiveName = 'makrosych-' . APP_VERSION;    // имя файла — латиницей: кириллицу в именах портят браузеры и архиваторы

if ($out === null || $out === '') {
    $out = dirname($root) . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . $archiveName . '.zip';
}

$missing = release_missing($root);
if ($missing !== []) {
    fwrite(STDERR, "Не хватает файлов программы — архив не собран:\n  " . implode("\n  ", $missing) . "\n");
    exit(1);
}

$outDir = dirname($out);
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Не удалось создать каталог: {$outDir}\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Не удалось открыть архив на запись: {$out}\n");
    exit(1);
}

$added = 0;
$trimmed = 0;
$skipped = [];
$perTop = [];

foreach (release_collect($root) as $relative => $absolute) {
    if (in_array($relative, RELEASE_SKIP, true)) {
        $skipped[] = $relative;
        continue;
    }
    if (release_junk($relative)) {
        continue;
    }
    if ($trim && release_trimmed($relative)) {
        $trimmed++;
        continue;
    }

    $zip->addFile($absolute, $folder . '/' . $relative);
    $added++;
    $top = str_contains($relative, '/') ? explode('/', $relative)[0] : '(корень)';
    $perTop[$top] = ($perTop[$top] ?? 0) + 1;
}

$leak = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string) $zip->getNameIndex($i);
    if (str_contains($name, '/data/') || str_contains($name, 'settings.json')) {
        $leak[] = $name;
    }
}

if ($leak !== []) {
    $zip->close();
    @unlink($out);
    fwrite(STDERR, "В архив попали данные пользователя — сборка отменена:\n  " . implode("\n  ", array_slice($leak, 0, 10)) . "\n");
    exit(1);
}

$zip->close();

ksort($perTop);
$size = (float) filesize($out);

echo "Макросыч " . APP_VERSION . " — архив собран\n\n";
echo '  Архив:   ' . $out . "\n";
echo '  Размер:  ' . number_format($size / 1048576, 1, ',', ' ') . " МБ\n";
echo '  Файлов:  ' . number_format($added, 0, ',', ' ') . "\n";

foreach ($perTop as $top => $count) {
    echo '    ' . str_pad($top, 12) . $count . "\n";
}

if ($skipped !== []) {
    echo "\n  Не включены (нужны только при разработке): " . implode(', ', $skipped) . "\n";
}
if ($trim) {
    echo '  Урезано --trim: ' . $trimmed . " файлов PHP (не подключены в php.ini)\n";
}

echo "\nОсталось приложить архив к релизу на GitHub (тег v" . APP_VERSION . ").\n";
echo "Проверить сборку: распаковать в отдельный каталог (лучше с кириллицей в пути) и запустить Макросыч.exe.\n";
