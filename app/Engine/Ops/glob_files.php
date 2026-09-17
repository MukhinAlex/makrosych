<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Lib\Paths;

return [
    'op' => 'glob_files',
    'title' => 'Сбор файлов из папки',
    'description' => 'Находит файлы по маске в папке и делает их доступными для следующих операций. Папку можно задать как output (папка результатов), input (папка входного файла) или указать путь.',
    'network' => false,
    'ai' => false,
    'params' => [
        'dir' => ['type' => 'string', 'default' => 'output', 'desc' => 'Папка: output, input или путь'],
        'pattern' => ['type' => 'string', 'default' => '*', 'desc' => 'Маска файлов, например *.webp'],
        'recursive' => ['type' => 'bool', 'default' => true, 'desc' => 'Обходить вложенные папки'],
        'sort' => ['type' => 'string', 'default' => 'name', 'desc' => 'Сортировка: name или mtime'],
        'limit' => ['type' => 'int', 'default' => 5000, 'desc' => 'Ограничение числа файлов'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $dir = op_glob_resolve_dir($ctx, (string) ($p['dir'] ?? 'output'));
        if (!is_dir($dir)) {
            throw new \RuntimeException("Папка не найдена: {$dir}");
        }

        $pattern = (string) ($p['pattern'] ?? '*');
        $recursive = (bool) ($p['recursive'] ?? true);
        $sort = (string) ($p['sort'] ?? 'name');
        $limit = (int) ($p['limit'] ?? 5000);

        $files = [];
        $iterator = $recursive
            ? new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            )
            : new \DirectoryIterator($dir);

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }
            if (!fnmatch($pattern, $item->getFilename())) {
                continue;
            }

            $files[] = ['path' => str_replace('\\', '/', $item->getPathname()), 'name' => $item->getFilename(), 'size' => $item->getSize(), 'mtime' => $item->getMTime()];
            if (count($files) >= $limit) {
                $ctx->warn("Достигнут предел в {$limit} файлов");
                break;
            }
        }

        usort($files, static fn (array $a, array $b) => $sort === 'mtime' ? $a['mtime'] <=> $b['mtime'] : strcmp($a['name'], $b['name']));

        foreach ($files as $file) {
            $ctx->files[] = ['kind' => 'found', 'status' => 'ok'] + $file;
        }

        $ctx->increment('найдено файлов', count($files));
        $ctx->info('Найдено файлов по маске ' . $pattern . ': ' . count($files) . ' в ' . Paths::relativeToData($dir));
    },
];

function op_glob_resolve_dir(Context $ctx, string $dir): string
{
    if ($dir === 'output' || $dir === '') {
        return $ctx->outDir;
    }

    if ($dir === 'input') {
        $first = reset($ctx->inputs);
        if (is_string($first) && $first !== '') {
            return dirname($first);
        }

        return $ctx->workDir;
    }

    $normalized = str_replace('\\', '/', $dir);
    if (preg_match('~^[a-zA-Z]:/~', $normalized) === 1 || str_starts_with($normalized, '//')) {
        return $normalized;
    }

    return $ctx->outPath($normalized);
}
