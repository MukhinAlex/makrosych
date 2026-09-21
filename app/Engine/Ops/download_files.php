<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Excel;
use App\Lib\Http;
use App\Lib\Paths;

return [
    'op' => 'download_files',
    'title' => 'Скачивание файлов по ссылкам',
    'description' => 'Скачивает файлы по ссылкам из указанного поля и раскладывает их по папкам. Поддерживает ссылки Яндекс Диска и Облака Mail.ru (публичная ссылка автоматически разрешается в набор файлов, включая вложенные папки) и обычные http-ссылки. Имя и папка задаются шаблоном пути.',
    'network' => true,
    'ai' => false,
    'params' => [
        'url_field' => ['type' => 'string', 'default' => 'link', 'desc' => 'Поле со ссылкой'],
        'group_field' => ['type' => 'string', 'desc' => 'Поле, задающее папку (например, article). Значение доступно в шаблоне как {group}'],
        'path' => ['type' => 'string', 'default' => '{group}/{index}.{ext}', 'desc' => 'Шаблон пути относительно папки результата. Доступны {group}, {index}, {name}, {ext}, {row} и любые поля строки'],
        'separator' => ['type' => 'string', 'desc' => 'Разделитель, если в ячейке несколько ссылок (например, ";"). Если не задан, значение берётся как одна ссылка'],
        'resolve' => ['type' => 'bool', 'default' => true, 'desc' => 'Разрешать публичные ссылки облаков (Яндекс Диск, Облако Mail.ru) в набор файлов'],
        'timeout' => ['type' => 'int', 'default' => 60, 'desc' => 'Таймаут запроса, секунд'],
        'retries' => ['type' => 'int', 'default' => 2, 'desc' => 'Число попыток скачивания'],
        'skip_existing' => ['type' => 'bool', 'default' => true, 'desc' => 'Пропускать уже скачанные файлы'],
        'extension_fallback' => ['type' => 'string', 'default' => 'jpg', 'desc' => 'Расширение, если его нет в ссылке'],
        'max' => ['type' => 'int', 'default' => 2000, 'desc' => 'Ограничение числа файлов за запуск'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $urlField = (string) ($p['url_field'] ?? 'link');
        $groupField = (string) ($p['group_field'] ?? '');
        $pathTemplate = (string) ($p['path'] ?? '{group}/{index}.{ext}');
        $separator = isset($p['separator']) ? (string) $p['separator'] : null;
        $resolve = (bool) ($p['resolve'] ?? true);
        $timeout = (int) ($p['timeout'] ?? 60);
        $retries = (int) ($p['retries'] ?? 2);
        $skipExisting = (bool) ($p['skip_existing'] ?? true);
        $extensionFallback = (string) ($p['extension_fallback'] ?? 'jpg');
        $max = (int) ($p['max'] ?? 2000);

        $counters = [];
        $downloaded = 0;
        $skipped = 0;
        $errors = 0;
        $planned = 0;

        foreach ($ctx->rows as $row) {
            if ($downloaded + $skipped + $errors >= $max) {
                $ctx->warn("Достигнут предел в {$max} файлов — обработка ссылок остановлена");
                break;
            }

            $raw = $row[$urlField] ?? null;
            if (is_array($raw)) {
                $urls = $raw;
            } elseif ($separator !== null && $separator !== '') {
                $urls = explode($separator, (string) $raw);
            } else {
                $urls = [(string) $raw];
            }

            $group = $groupField !== '' ? Excel::text($row[$groupField] ?? '') : '';

            foreach ($urls as $url) {
                $url = trim(trim((string) $url), "\"'");
                if ($url === '') {
                    continue;
                }

                $targets = [];
                if ($resolve && Http::isCloud($url)) {
                    if ($ctx->dryRun) {
                        $ctx->plan('file', 'публичная ссылка облака будет разрешена', ['url' => $url, 'group' => $group]);
                        $planned++;
                        continue;
                    }

                    $resolved = Http::resolve($url);
                    if (!$resolved['success']) {
                        $errors++;
                        $ctx->files[] = [
                            'kind' => 'download',
                            'status' => 'error',
                            'group' => $group,
                            'url' => $url,
                            'error' => $resolved['error'],
                        ];
                        continue;
                    }

                    foreach ($resolved['files'] as $file) {
                        $targets[] = ['url' => $file['url'], 'name' => $file['name'], 'ext' => Http::guessExtension($file['name'], $extensionFallback)];
                    }
                } else {
                    $targets[] = ['url' => $url, 'name' => basename((string) (parse_url($url, PHP_URL_PATH) ?: '')), 'ext' => Http::guessExtension($url, $extensionFallback)];
                }

                foreach ($targets as $target) {
                    $index = ($counters[$group] ?? 0) + 1;
                    $counters[$group] = $index;

                    $relative = op_download_render_path($pathTemplate, [
                        'group' => $group,
                        'article' => $group,
                        'index' => $index,
                        'row' => (string) ($row['_row'] ?? ''),
                        'name' => pathinfo($target['name'] !== '' ? $target['name'] : 'file', PATHINFO_FILENAME),
                        'ext' => $target['ext'],
                    ], $row);

                    $relative = str_replace('\\', '/', $relative);
                    if (str_contains($relative, '..')) {
                        $errors++;
                        $ctx->error("Недопустимый путь: {$relative}");
                        continue;
                    }

                    if ($ctx->dryRun) {
                        $ctx->plan('file', $relative, ['url' => $target['url'], 'group' => $group]);
                        $planned++;
                        continue;
                    }

                    $absolute = $ctx->outPath($relative);

                    if ($skipExisting && is_file($absolute) && filesize($absolute) > 0) {
                        $skipped++;
                        $ctx->files[] = ['kind' => 'download', 'status' => 'skipped', 'path' => $absolute, 'group' => $group, 'url' => $target['url']];
                        continue;
                    }

                    $result = Http::download($target['url'], $absolute, $timeout, $retries);
                    if ($result['success']) {
                        $downloaded++;
                        $ctx->files[] = [
                            'kind' => 'download',
                            'status' => 'ok',
                            'path' => $absolute,
                            'group' => $group,
                            'url' => $target['url'],
                            'size' => $result['size'],
                        ];
                    } else {
                        $errors++;
                        $ctx->files[] = [
                            'kind' => 'download',
                            'status' => 'error',
                            'path' => $absolute,
                            'group' => $group,
                            'url' => $target['url'],
                            'error' => $result['error'],
                        ];
                    }
                }
            }
        }

        $ctx->increment('скачано файлов', $downloaded);
        $ctx->increment('пропущено файлов', $skipped);
        $ctx->increment('ошибок скачивания', $errors);

        if ($ctx->dryRun) {
            $ctx->info("Проверка: будет скачано файлов — {$planned}" . ($errors > 0 ? ", проблемных ссылок: {$errors}" : ''));

            return;
        }

        $ctx->info("Скачано: {$downloaded}" . ($skipped > 0 ? ", пропущено: {$skipped}" : '') . ($errors > 0 ? ", ошибок: {$errors}" : ''));
    },
];

/**
 * Подстановка значений в шаблон пути.
 *
 * @param array<string, string> $values
 * @param array<string, mixed> $row
 */
function op_download_render_path(string $template, array $values, array $row): string
{
    return (string) preg_replace_callback(
        '~\{([a-zA-Z0-9_]+)\}~',
        static function (array $matches) use ($values, $row): string {
            $key = $matches[1];
            if (array_key_exists($key, $values)) {
                return Paths::sanitizeFilename((string) $values[$key]);
            }
            if (array_key_exists($key, $row)) {
                $value = $row[$key];
                if (is_array($value)) {
                    $value = implode('_', array_map('strval', $value));
                }

                return Paths::sanitizeFilename(Excel::text($value));
            }

            return '';
        },
        $template
    );
}
