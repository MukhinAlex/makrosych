<?php

declare(strict_types=1);

use App\Engine\Context;

return [
    'op' => 'zip_output',
    'title' => 'Упаковка результата в ZIP',
    'description' => 'Собирает все файлы из папки результатов (или её подпапки) в ZIP-архив. Удобно, когда на выходе много изображений.',
    'network' => false,
    'ai' => false,
    'params' => [
        'source' => ['type' => 'string', 'default' => '', 'desc' => 'Подпапка внутри результатов (пусто — вся папка результатов)'],
        'output' => ['type' => 'string', 'default' => 'результат.zip', 'desc' => 'Имя архива'],
        'include_root' => ['type' => 'bool', 'default' => false, 'desc' => 'Включать имя папки в пути внутри архива'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $source = (string) ($p['source'] ?? '');
        $output = (string) ($p['output'] ?? 'результат.zip');
        $includeRoot = (bool) ($p['include_root'] ?? false);

        $dir = $source === '' ? $ctx->outDir : $ctx->outPath($source);
        if (!is_dir($dir)) {
            throw new \RuntimeException("Папка для упаковки не найдена: {$dir}");
        }

        $zipName = $output;
        $zipPath = $ctx->outPath($output);

        // Архив не должен затирать уже созданный файл результата
        if (op_zip_conflicts($ctx, $zipPath)) {
            $zipName = (string) preg_replace('~\.[a-zA-Z0-9]{1,5}$~', '', $output) . '_архив.zip';
            $zipPath = $ctx->outPath($zipName);
            $ctx->warn("Имя архива совпало с файлом результата — архив сохранён как «{$zipName}»");
        }

        $files = op_zip_collect($dir, $zipPath);

        if ($files === []) {
            $ctx->warn('Нет файлов для упаковки в архив');

            return;
        }

        if ($ctx->dryRun) {
            $ctx->plan('zip', $zipName, ['files' => count($files)]);
            $ctx->info('Проверка: в архив «' . $zipName . '» войдёт файлов — ' . count($files));

            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Не удалось создать архив: {$zipPath}");
        }

        $rootName = $includeRoot ? basename($dir) . '/' : '';
        foreach ($files as $file) {
            $relative = $rootName . ltrim(substr($file, strlen($dir)), '/\\');
            $zip->addFile($file, str_replace('\\', '/', $relative));
        }
        $zip->close();

        $ctx->files[] = ['kind' => 'zip', 'status' => 'ok', 'path' => $zipPath, 'files' => count($files)];
        $ctx->plan('zip', $zipName, ['files' => count($files)]);
        $ctx->info('Создан архив «' . $zipName . '»: файлов — ' . count($files));
    },
];

/**
 * Проверка, что архив не затрёт созданный файл результата.
 *
 * Модель может назвать архив так же, как файл Excel, — тогда архив уничтожил бы результат.
 */
function op_zip_conflicts(Context $ctx, string $zipPath): bool
{
    $target = str_replace('\\', '/', $zipPath);

    if (is_file($target) && op_zip_is_archive($target) === false) {
        return true;
    }

    $targetName = basename($target);
    foreach (array_merge($ctx->files, $ctx->planned) as $item) {
        if (($item['kind'] ?? '') === 'zip') {
            continue;
        }

        $path = str_replace('\\', '/', (string) ($item['path'] ?? ''));
        if ($path !== '' && ($path === $target || basename($path) === $targetName)) {
            return true;
        }
    }

    return false;
}

/** Файл является ZIP-архивом. */
function op_zip_is_archive(string $path): bool
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }

    $signature = (string) fread($handle, 2);
    fclose($handle);

    return $signature === 'PK';
}

/**
 * Список файлов для архива (кроме самого архива).
 *
 * @return string[]
 */
function op_zip_collect(string $dir, string $exclude): array
{
    $exclude = str_replace('\\', '/', $exclude);
    $result = [];

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $item) {
        /** @var \SplFileInfo $item */
        if (!$item->isFile()) {
            continue;
        }

        $path = str_replace('\\', '/', $item->getPathname());
        if ($path === $exclude) {
            continue;
        }

        $result[] = $item->getPathname();
    }

    return $result;
}
