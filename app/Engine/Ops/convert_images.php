<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Lib\Paths;

return [
    'op' => 'convert_images',
    'title' => 'Конвертация изображений',
    'description' => 'Преобразует изображения из одного формата в другой (например, WebP в JPG). Работает в выбранной папке, при необходимости удаляет исходные файлы. Требует расширение GD.',
    'network' => false,
    'ai' => false,
    'params' => [
        'dir' => ['type' => 'string', 'default' => 'output', 'desc' => 'Папка: output, input или путь'],
        'from' => ['type' => 'string', 'default' => 'webp', 'desc' => 'Исходный формат (webp, png, gif, jpg, bmp)'],
        'to' => ['type' => 'string', 'default' => 'jpg', 'desc' => 'Целевой формат (jpg, png, webp)'],
        'quality' => ['type' => 'int', 'default' => 90, 'desc' => 'Качество для jpg/webp, 0–100'],
        'delete_source' => ['type' => 'bool', 'default' => true, 'desc' => 'Удалять исходные файлы после конвертации'],
        'recursive' => ['type' => 'bool', 'default' => true, 'desc' => 'Обходить вложенные папки'],
        'skip_existing' => ['type' => 'bool', 'default' => true, 'desc' => 'Пропускать уже сконвертированные файлы'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $dir = op_convert_resolve_dir($ctx, (string) ($p['dir'] ?? 'output'));
        if (!is_dir($dir)) {
            throw new \RuntimeException("Папка не найдена: {$dir}");
        }

        $from = strtolower((string) ($p['from'] ?? 'webp'));
        $to = strtolower((string) ($p['to'] ?? 'jpg'));
        $quality = max(0, min(100, (int) ($p['quality'] ?? 90)));
        $deleteSource = (bool) ($p['delete_source'] ?? true);
        $recursive = (bool) ($p['recursive'] ?? true);
        $skipExisting = (bool) ($p['skip_existing'] ?? true);

        $loader = op_convert_loader($from);
        if ($loader === null || !function_exists($loader)) {
            throw new \RuntimeException("Расширение GD не поддерживает чтение формата «{$from}»");
        }

        $saver = op_convert_saver($to);
        if ($saver === null || !function_exists($saver)) {
            throw new \RuntimeException("Расширение GD не поддерживает запись формата «{$to}»");
        }

        $iterator = $recursive
            ? new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            )
            : new \DirectoryIterator($dir);

        $converted = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile() || strtolower($item->getExtension()) !== $from) {
                continue;
            }

            $source = str_replace('\\', '/', $item->getPathname());
            $target = preg_replace('~\.' . preg_quote($from, '~') . '$~i', '.' . $to, $source) ?? $source;

            if ($ctx->dryRun) {
                $ctx->plan('image', Paths::relativeToData($target), ['from' => $source, 'delete_source' => $deleteSource]);
                $converted++;
                continue;
            }

            if ($skipExisting && is_file($target)) {
                $skipped++;
                continue;
            }

            $image = @$loader($source);
            if ($image === false) {
                $errors++;
                $ctx->files[] = ['kind' => 'image', 'status' => 'error', 'path' => $source, 'error' => 'Не удалось прочитать файл'];
                continue;
            }

            $ok = $to === 'jpg'
                ? @imagejpeg($image, $target, $quality)
                : @$saver($image, $target, $quality);
            imagedestroy($image);

            if ($ok) {
                $converted++;
                $ctx->files[] = ['kind' => 'image', 'status' => 'ok', 'path' => $target, 'source' => $source];
                if ($deleteSource) {
                    @unlink($source);
                }
            } else {
                $errors++;
                $ctx->files[] = ['kind' => 'image', 'status' => 'error', 'path' => $source, 'error' => 'Не удалось сохранить файл'];
            }
        }

        $ctx->increment('изображений сконвертировано', $converted);
        $ctx->increment('ошибок конвертации', $errors);
        $ctx->info(
            ($ctx->dryRun ? 'Будет сконвертировано: ' : 'Сконвертировано: ') . $converted
            . ($skipped > 0 ? ", пропущено: {$skipped}" : '')
            . ($errors > 0 ? ", ошибок: {$errors}" : '')
        );
    },
];

function op_convert_resolve_dir(Context $ctx, string $dir): string
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

function op_convert_loader(string $format): ?string
{
    return match ($format) {
        'webp' => 'imagecreatefromwebp',
        'png' => 'imagecreatefrompng',
        'gif' => 'imagecreatefromgif',
        'jpg', 'jpeg' => 'imagecreatefromjpeg',
        'bmp' => 'imagecreatefrombmp',
        default => null,
    };
}

function op_convert_saver(string $format): ?string
{
    return match ($format) {
        'png' => 'imagepng',
        'webp' => 'imagewebp',
        'gif' => 'imagegif',
        'jpg', 'jpeg' => 'imagejpeg',
        default => null,
    };
}
