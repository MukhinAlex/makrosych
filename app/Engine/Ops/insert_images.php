<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Excel;
use App\Engine\FileSearch;
use App\Engine\RowFiller;
use App\Lib\Http;
use App\Lib\Paths;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Drawing as DrawingHelper;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

return [
    'op' => 'insert_images',
    'title' => 'Вставка изображений в шаблон',
    'description' => 'Вставляет изображения в ячейки готового шаблона Excel. Картинка берётся по ссылке (http/https, Яндекс Диск, data:) или из уже скачанного файла. Строка шаблона находится сопоставлением колонки-ключа со значением поля. Несколько изображений в одной ячейке разделяются параметром separator и размещаются вправо или вниз.',
    'network' => true,
    'ai' => false,
    'params' => [
        'template' => ['type' => 'string', 'required' => true, 'desc' => 'Псевдоним файла-шаблона (обычно template)'],
        'sheet' => ['type' => 'int|string', 'default' => 1, 'desc' => 'Лист шаблона'],
        'match' => ['type' => 'object', 'required' => true, 'desc' => 'Ключ сопоставления: {"column": "A", "field": "code"}'],
        'images' => ['type' => 'object', 'required' => true, 'desc' => 'Колонка шаблона => {"url_field": "поле со ссылкой или путём к файлу"} либо {"template": "https://сайт/фото/{code}.jpg"}, либо {"folder": "C:/Фото", "pattern": "{name}.*"} — поиск файла в папке по маске; размер: "height", "width", "fit"; несколько ссылок: "separator": ";", "direction": "right"'],
        'cells' => ['type' => 'object', 'desc' => 'Дополнительно заполнить колонки шаблона значениями: {"B": "name", "E": "price"}'],
        'formulas' => ['type' => 'object', 'desc' => 'Дополнительно записать формулы Excel: {"F": "=D{row}*E{row}"}. Доступны {row} и {col:поле}'],
        'hyperlinks' => ['type' => 'object', 'desc' => 'Дополнительно поставить активные ссылки: {"C": {"template": "https://сайт/catalog/{code}"}} либо {"C": {"folder": "C:/Фото", "pattern": "{name}.*"}} — ссылка на найденный файл (file:///); keep_text=true (по умолчанию) сохраняет текст ячейки'],
        'insert_columns' => ['type' => 'object', 'desc' => 'Вставить колонки со сдвигом вправо: {"B": "Изображение"}. Буква — место в итоговом файле, заголовок пишется в строку header_row'],
        'header_row' => ['type' => 'int', 'default' => 1, 'desc' => 'Строка заголовков шаблона — куда писать название вставленной колонки'],
        'wrap_text' => ['type' => 'bool|string[]', 'desc' => 'Перенос текста в записываемых колонках: true либо список колонок'],
        'vertical_align' => ['type' => 'string', 'desc' => 'Вертикальное выравнивание записанных ячеек: top, center, bottom'],
        'data_from_row' => ['type' => 'int', 'default' => 2, 'desc' => 'С какой строки искать совпадения'],
        'missing' => ['type' => 'string', 'default' => 'skip', 'desc' => 'Что делать, если совпадение не найдено: skip — пропустить, error — остановить'],
        'output' => ['type' => 'string', 'default' => 'результат.xlsx', 'desc' => 'Имя файла результата'],
        'cache_dir' => ['type' => 'string', 'default' => 'изображения', 'desc' => 'Папка для скачанных изображений внутри каталога результата'],
        'height' => ['type' => 'int', 'desc' => 'Высота изображений по умолчанию, пикселей'],
        'width' => ['type' => 'int', 'desc' => 'Ширина изображений по умолчанию, пикселей'],
        'fit' => ['type' => 'string', 'default' => 'proportional', 'desc' => 'Вписывание: proportional — по заданной стороне с сохранением пропорций, width, height, both, cell — по размеру ячейки'],
        'offset_x' => ['type' => 'int', 'default' => 2, 'desc' => 'Отступ от левого края ячейки, пикселей'],
        'offset_y' => ['type' => 'int', 'default' => 2, 'desc' => 'Отступ от верхнего края ячейки, пикселей'],
        'separator' => ['type' => 'string', 'desc' => 'Разделитель, если в ячейке несколько ссылок (например ";")'],
        'direction' => ['type' => 'string', 'default' => 'right', 'desc' => 'Куда размещать несколько изображений: right — вправо, down — вниз'],
        'auto_row_height' => ['type' => 'bool', 'default' => true, 'desc' => 'Увеличить высоту строки под изображение (строка только расширяется), чтобы фото не перекрывало строки ниже'],
        'auto_column_width' => ['type' => 'bool', 'default' => true, 'desc' => 'Расширить колонку до ширины изображения (колонка только расширяется), чтобы фото не перекрывало соседние ячейки'],
        'convert_unsupported' => ['type' => 'bool', 'default' => true, 'desc' => 'Преобразовывать WebP и другие неподдерживаемые форматы в PNG'],
        'timeout' => ['type' => 'int', 'default' => 60, 'desc' => 'Таймаут запроса, секунд'],
        'retries' => ['type' => 'int', 'default' => 2, 'desc' => 'Число попыток скачивания'],
        'skip_existing' => ['type' => 'bool', 'default' => true, 'desc' => 'Не скачивать повторно уже сохранённые изображения'],
        'max' => ['type' => 'int', 'default' => 500, 'desc' => 'Ограничение числа изображений за запуск'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $templatePath = $ctx->inputPath((string) $p['template']);
        $spreadsheet = Excel::load($templatePath, false);
        $sheet = Excel::sheet($spreadsheet, $p['sheet'] ?? 1);

        $match = (array) $p['match'];
        $matchColumn = strtoupper((string) ($match['column'] ?? ''));
        $matchField = (string) ($match['field'] ?? '');
        if ($matchColumn === '' || $matchField === '') {
            throw new \RuntimeException('Не задан ключ сопоставления (match)');
        }

        $images = (array) $p['images'];
        if ($images === []) {
            throw new \RuntimeException('Не заданы колонки для изображений (images)');
        }

        $dataFrom = (int) ($p['data_from_row'] ?? 2);
        $missing = (string) ($p['missing'] ?? 'skip');
        $output = (string) ($p['output'] ?? 'результат.xlsx');
        $cells = (array) ($p['cells'] ?? []);
        $formulas = (array) ($p['formulas'] ?? []);
        $hyperlinks = (array) ($p['hyperlinks'] ?? []);
        $cacheDir = trim(str_replace('\\', '/', (string) ($p['cache_dir'] ?? 'изображения')), '/');
        $fit = strtolower((string) ($p['fit'] ?? 'proportional'));
        $defaultWidth = (int) ($p['width'] ?? 0);
        $defaultHeight = (int) ($p['height'] ?? 0);
        $offsetX = (int) ($p['offset_x'] ?? 2);
        $offsetY = (int) ($p['offset_y'] ?? 2);
        $autoRowHeight = (bool) ($p['auto_row_height'] ?? true);
        $autoColumnWidth = (bool) ($p['auto_column_width'] ?? true);
        $convert = (bool) ($p['convert_unsupported'] ?? true);
        $timeout = (int) ($p['timeout'] ?? 60);
        $retries = (int) ($p['retries'] ?? 2);
        $skipExisting = (bool) ($p['skip_existing'] ?? true);
        $max = (int) ($p['max'] ?? 500);

        $wrapText = $p['wrap_text'] ?? false;
        $wrapColumns = [];
        if ($wrapText === true) {
            $wrapColumns = array_map(static fn ($column) => strtoupper((string) $column), array_keys($cells));
        } elseif (is_array($wrapText)) {
            $wrapColumns = array_map('strtoupper', array_map('strval', $wrapText));
        }
        $verticalAlign = strtolower((string) ($p['vertical_align'] ?? ''));

        // Новые колонки вставляются до поиска строк: буквы заданы для итогового файла
        $insertColumns = (array) ($p['insert_columns'] ?? []);
        $insertedColumns = 0;
        if ($insertColumns !== []) {
            $insertedColumns = Excel::insertColumns($sheet, $insertColumns, (int) ($p['header_row'] ?? 1));
            $ctx->info("В шаблон вставлено новых колонок: {$insertedColumns}");
        }

        $index = Excel::rowIndex($sheet, $matchColumn, $dataFrom);

        $inserted = 0;
        $downloaded = 0;
        $skipped = 0;
        $errors = 0;
        $planned = 0;
        $formulasSet = 0;
        $linksSet = 0;
        $notFound = [];
        $sequences = [];
        $rowNeeds = [];
        $columnNeeds = [];
        $folderWarnings = [];
        $limitHit = false;

        foreach ($ctx->rows as $record) {
            if ($limitHit) {
                break;
            }

            $key = Excel::text($record[$matchField] ?? null);
            if ($key === '') {
                continue;
            }

            $targetRow = $index[$key] ?? null;
            if ($targetRow === null) {
                $notFound[] = $key;
                if ($missing === 'error') {
                    throw new \RuntimeException("В шаблоне не найдена строка для ключа «{$key}»");
                }
                continue;
            }

            // Значения, формулы и ссылки записываются вместе с картинками — в тот же файл
            if ($cells !== [] || $formulas !== [] || $hyperlinks !== []) {
                $filled = RowFiller::apply(
                    $ctx,
                    $sheet,
                    $targetRow,
                    $cells,
                    $formulas,
                    $hyperlinks,
                    $record,
                    $wrapColumns,
                    $verticalAlign
                );
                $formulasSet += $filled['formulas'];
                $linksSet += $filled['links'];
            }

            foreach ($images as $column => $spec) {
                $letter = strtoupper((string) $column);
                $spec = is_array($spec) ? $spec : ['url_field' => (string) $spec];
                $field = (string) ($spec['url_field'] ?? $spec['source_field'] ?? $spec['field'] ?? '');
                $urlTemplate = (string) ($spec['template'] ?? '');
                $folderTemplate = (string) ($spec['folder'] ?? '');

                if ($field === '' && $urlTemplate === '' && $folderTemplate === '') {
                    continue;
                }

                if ($folderTemplate !== '') {
                    // Картинка ищется в папке по маске: расширение и лишние слова в имени не важны
                    $folder = Excel::renderTemplate($folderTemplate, $record);
                    $mask = Excel::renderTemplate((string) ($spec['pattern'] ?? '*'), $record);
                    $found = FileSearch::find($folder, $mask, (bool) ($spec['recursive'] ?? false));

                    if ($found === null) {
                        $folderKey = $folder . '|' . $mask;
                        if (!isset($folderWarnings[$folderKey])) {
                            $folderWarnings[$folderKey] = true;
                            $ctx->warn(
                                is_dir($folder)
                                    ? "В папке {$folder} нет файла по маске «{$mask}»"
                                    : "Папка не найдена: {$folder}"
                            );
                        }
                        continue;
                    }

                    $values = [$found];
                } else {
                    $raw = $field !== ''
                        ? ($record[$field] ?? null)
                        : Excel::renderTemplate($urlTemplate, $record);

                    $values = op_images_values($raw, $spec, $p);
                }

                if ($values === []) {
                    continue;
                }

                $direction = strtolower((string) ($spec['direction'] ?? $p['direction'] ?? 'right'));
                $baseColumn = Coordinate::columnIndexFromString($letter);
                $position = 0;

                foreach ($values as $value) {
                    $value = trim((string) $value, " \t\n\r\"'");
                    if ($value === '') {
                        continue;
                    }

                    if ($inserted + $planned >= $max) {
                        $ctx->warn("Достигнут предел в {$max} изображений — обработка остановлена");
                        $limitHit = true;
                        break;
                    }

                    if ($ctx->dryRun) {
                        $plannedColumn = $direction === 'down' ? $letter : Excel::columnLetter($baseColumn + $position);
                        $plannedRow = $direction === 'down' ? $targetRow + $position : $targetRow;
                        $ctx->plan('image', $plannedColumn . $plannedRow, ['url' => $value, 'key' => $key]);
                        $planned++;
                        $position++;
                        continue;
                    }

                    $next = ($sequences[$key] ?? 0) + 1;
                    $fetched = op_images_fetch($ctx, $value, [
                        'cache_dir' => $cacheDir,
                        'key' => $key,
                        'index' => $next,
                        'timeout' => $timeout,
                        'retries' => $retries,
                        'skip_existing' => $skipExisting,
                        'convert' => $convert,
                    ]);
                    $sequences[$key] = $next + max(1, count($fetched['paths']));
                    $downloaded += $fetched['downloaded'];
                    $skipped += $fetched['skipped'];

                    if (!$fetched['ok']) {
                        $errors++;
                        $ctx->warn("Изображение для «{$key}» не получено ({$value}): {$fetched['error']}");
                        continue;
                    }

                    foreach ($fetched['paths'] as $local) {
                        $cellColumn = $direction === 'down' ? $letter : Excel::columnLetter($baseColumn + $position);
                        $cellRow = $direction === 'down' ? $targetRow + $position : $targetRow;
                        $cell = $cellColumn . $cellRow;
                        $position++;

                        try {
                            $drawing = new Drawing();
                            $drawing->setPath($local);
                        } catch (\Throwable $e) {
                            $errors++;
                            $ctx->warn("Не удалось вставить изображение в {$cell}: " . $e->getMessage());
                            continue;
                        }

                        $imageWidth = (int) $drawing->getImageWidth();
                        $imageHeight = (int) $drawing->getImageHeight();
                        $box = $fit === 'cell' ? op_images_box($sheet, $cellColumn, $cellRow, $offsetX, $offsetY) : null;
                        $dimensions = op_images_dimensions(
                            $fit,
                            $imageWidth,
                            $imageHeight,
                            (int) ($spec['width'] ?? $defaultWidth),
                            (int) ($spec['height'] ?? $defaultHeight),
                            $box
                        );

                        // Пропорциональное вписывание: задаётся одна сторона.
                        // Точный размер (по ячейке или обе стороны) — сразу две.
                        $bothSides = ($fit === 'cell' || $fit === 'both')
                            && $dimensions['width'] > 0
                            && $dimensions['height'] > 0;

                        $drawing->setResizeProportional(!$bothSides);

                        if ($bothSides) {
                            $drawing->setWidthAndHeight($dimensions['width'], $dimensions['height']);
                        } else {
                            if ($dimensions['width'] > 0) {
                                $drawing->setWidth($dimensions['width']);
                            }
                            if ($dimensions['height'] > 0) {
                                $drawing->setHeight($dimensions['height']);
                            }
                        }

                        $drawing->setCoordinates($cell);
                        $drawing->setOffsetX($offsetX);
                        $drawing->setOffsetY($offsetY);
                        $drawing->setName($key . '_' . ($inserted + 1));
                        $drawing->setDescription($key);
                        $drawing->setWorksheet($sheet);

                        $inserted++;
                        $finalHeight = (int) $drawing->getHeight();
                        if ($finalHeight <= 0) {
                            $finalHeight = $imageHeight;
                        }

                        $finalWidth = (int) $drawing->getWidth();
                        if ($finalWidth <= 0) {
                            $finalWidth = $imageWidth;
                        }

                        // Что нужно строке и колонке, чтобы изображение не перекрывало соседние ячейки
                        $rowNeeds[$cellRow] = max($rowNeeds[$cellRow] ?? 0, $offsetY + $finalHeight);
                        $columnNeeds[$cellColumn] = max($columnNeeds[$cellColumn] ?? 0, $offsetX + $finalWidth);
                        $ctx->files[] = ['kind' => 'image', 'status' => 'ok', 'path' => $local, 'cell' => $cell, 'key' => $key];
                    }
                }
            }
        }

        // Строка и колонка только расширяются: оформление шаблона не уменьшается
        if ($autoRowHeight && $rowNeeds !== []) {
            foreach ($rowNeeds as $row => $pixels) {
                $dimension = $sheet->getRowDimension((int) $row);
                $current = $dimension->getRowHeight();
                if ($current < 0) {
                    $current = $sheet->getDefaultRowDimension()->getRowHeight();
                }

                $needed = DrawingHelper::pixelsToPoints((int) $pixels);
                if ($current < 0 || $needed > $current) {
                    $dimension->setRowHeight($needed);
                }
            }
        }

        if ($autoColumnWidth && $columnNeeds !== []) {
            foreach ($columnNeeds as $column => $pixels) {
                $dimension = $sheet->getColumnDimension((string) $column);
                $current = $dimension->getWidth();
                if ($current < 0) {
                    $current = $sheet->getDefaultColumnDimension()->getWidth();
                }

                $needed = (float) DrawingHelper::pixelsToCellDimension(
                    (int) $pixels,
                    $sheet->getStyle($column . '1')->getFont()
                );
                if ($current < 0 || $needed > $current) {
                    $dimension->setWidth($needed);
                }
            }
        }

        if ($ctx->dryRun) {
            $ctx->plan('excel', $output, ['images' => $planned, 'formulas' => $formulasSet, 'links' => $linksSet, 'columns' => $insertedColumns, 'not_found' => count($notFound)]);
            $ctx->increment('изображений вставлено', $planned);
            $ctx->increment('формул записано', $formulasSet);
            $ctx->increment('ссылок поставлено', $linksSet);
            $ctx->increment('колонок вставлено', $insertedColumns);
            $ctx->info(
                "Проверка: изображений будет вставлено — {$planned}"
                . ($insertedColumns > 0 ? ", вставлено колонок: {$insertedColumns}" : '')
                . ($formulasSet > 0 ? ", формул: {$formulasSet}" : '')
                . ($linksSet > 0 ? ", ссылок: {$linksSet}" : '')
                . ($notFound !== [] ? ", не найдено ключей: " . count($notFound) : '')
            );

            return;
        }

        $path = $ctx->outPath($output);
        Excel::save($spreadsheet, $path);
        $spreadsheet->disconnectWorksheets();

        $ctx->increment('изображений вставлено', $inserted);
        $ctx->increment('изображений скачано', $downloaded);
        $ctx->increment('пропущено изображений', $skipped);
        $ctx->increment('ошибок изображений', $errors);
        $ctx->increment('формул записано', $formulasSet);
        $ctx->increment('ссылок поставлено', $linksSet);
        $ctx->increment('колонок вставлено', $insertedColumns);
        $ctx->files[] = ['kind' => 'excel', 'status' => 'ok', 'path' => $path, 'images' => $inserted, 'formulas' => $formulasSet, 'links' => $linksSet, 'columns' => $insertedColumns];
        $ctx->plan('excel', $output, ['images' => $inserted, 'formulas' => $formulasSet, 'links' => $linksSet, 'columns' => $insertedColumns, 'not_found' => count($notFound)]);
        $ctx->info(
            "Изображений вставлено: {$inserted}"
            . ($downloaded > 0 ? ", скачано: {$downloaded}" : '')
            . ($skipped > 0 ? ", уже было: {$skipped}" : '')
            . ($insertedColumns > 0 ? ", вставлено колонок: {$insertedColumns}" : '')
            . ($formulasSet > 0 ? ", формул: {$formulasSet}" : '')
            . ($linksSet > 0 ? ", ссылок: {$linksSet}" : '')
            . ($errors > 0 ? ", ошибок: {$errors}" : '')
        );
    },
];

/**
 * Разбор значения поля на список ссылок или путей к файлам.
 *
 * @param array<string, mixed> $spec
 * @param array<string, mixed> $params
 * @return array<int, string>
 */
function op_images_values(mixed $raw, array $spec, array $params): array
{
    if (is_array($raw)) {
        $values = array_map(static fn ($item) => is_scalar($item) ? (string) $item : '', $raw);
    } else {
        $separator = $spec['separator'] ?? $params['separator'] ?? null;
        $text = (string) $raw;

        if ($separator === null || $separator === '') {
            $values = [$text];
        } elseif ($separator === 'newline' || $separator === '\n') {
            $values = preg_split('~\r\n|\r|\n~', $text) ?: [];
        } else {
            $values = explode((string) $separator, $text);
        }
    }

    $values = array_values(array_filter(array_map('trim', $values), static fn (string $value) => $value !== ''));

    if (isset($spec['index'])) {
        $position = (int) $spec['index'] - 1;

        return array_key_exists($position, $values) ? [$values[$position]] : [];
    }

    return $values;
}

/**
 * Получение локальных файлов изображений: скачивание по ссылке или поиск готового файла.
 *
 * @param array<string, mixed> $options
 * @return array{ok: bool, paths: array<int, string>, downloaded: int, skipped: int, error: string}
 */
function op_images_fetch(Context $ctx, string $value, array $options): array
{
    $fail = static fn (string $error): array => [
        'ok' => false, 'paths' => [], 'downloaded' => 0, 'skipped' => 0, 'error' => $error,
    ];

    $cacheDir = (string) $options['cache_dir'];
    $key = Paths::sanitizeFilename((string) $options['key']);
    $index = (int) $options['index'];
    $convert = (bool) $options['convert'];

    // Изображение в самой ячейке (data:image/...;base64,...)
    if (preg_match('~^data:image/([a-zA-Z0-9.+-]+);base64,~', $value, $matches) === 1) {
        $binary = base64_decode(substr($value, strlen($matches[0])), true);
        if ($binary === false || $binary === '') {
            return $fail('не удалось прочитать изображение из data-ссылки');
        }

        $extension = strtolower($matches[1]) === 'jpeg' ? 'jpg' : (string) preg_replace('~[^a-z0-9]~', '', strtolower($matches[1]));
        $path = $ctx->outPath($cacheDir . '/' . $key . '/' . $index . '.' . $extension);
        if (@file_put_contents($path, $binary) === false) {
            return $fail('не удалось записать изображение');
        }

        $usable = op_images_usable($path, $convert);

        return $usable['ok']
            ? ['ok' => true, 'paths' => [$usable['path']], 'downloaded' => 1, 'skipped' => 0, 'error' => '']
            : $fail($usable['error']);
    }

    // Готовый файл на диске: абсолютный путь или путь внутри каталога результата
    if (!Http::isUrl($value)) {
        $candidates = [$value, $ctx->outDir . '/' . ltrim(str_replace('\\', '/', $value), '/')];
        foreach ($candidates as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            $usable = op_images_usable($candidate, $convert);

            return $usable['ok']
                ? ['ok' => true, 'paths' => [$usable['path']], 'downloaded' => 0, 'skipped' => 1, 'error' => '']
                : $fail($usable['error']);
        }

        return $fail("файл не найден: {$value}");
    }

    // Ссылка: Яндекс Диск разрешается в набор файлов, обычная ссылка скачивается напрямую
    $targets = [];
    if (Http::isYandex($value)) {
        $resolved = Http::resolve($value);
        if (!$resolved['success']) {
            return $fail($resolved['error']);
        }

        foreach ($resolved['files'] as $file) {
            $targets[] = ['url' => (string) $file['url'], 'name' => (string) $file['name']];
        }
    } else {
        $targets[] = ['url' => $value, 'name' => basename((string) (parse_url($value, PHP_URL_PATH) ?: ''))];
    }

    $paths = [];
    $downloaded = 0;
    $skipped = 0;

    foreach ($targets as $offset => $target) {
        if ($target['url'] === '') {
            continue;
        }

        $extension = Http::guessExtension($target['name'] !== '' ? $target['name'] : $target['url'], 'jpg');
        $path = $ctx->outPath($cacheDir . '/' . $key . '/' . ($index + $offset) . '.' . $extension);

        $fresh = false;
        if ((bool) $options['skip_existing'] && is_file($path) && (int) @filesize($path) > 0) {
            $skipped++;
        } else {
            $result = Http::download($target['url'], $path, (int) $options['timeout'], (int) $options['retries']);
            if (!$result['success']) {
                $ctx->files[] = [
                    'kind' => 'image', 'status' => 'error', 'path' => $path,
                    'url' => $target['url'], 'error' => $result['error'],
                ];
                continue;
            }

            $downloaded++;
            $fresh = true;
        }

        $usable = op_images_usable($path, $convert);
        if (!$usable['ok']) {
            // Скачанный мусор (например страница вместо фото) не оставляем в кэше
            if ($fresh) {
                @unlink($path);
            }

            $ctx->warn("Изображение {$target['url']} пропущено: {$usable['error']}");
            continue;
        }

        $paths[] = $usable['path'];
    }

    if ($paths === []) {
        return ['ok' => false, 'paths' => [], 'downloaded' => $downloaded, 'skipped' => $skipped, 'error' => 'не удалось получить изображение'];
    }

    return ['ok' => true, 'paths' => $paths, 'downloaded' => $downloaded, 'skipped' => $skipped, 'error' => ''];
}

/**
 * Проверка формата изображения. Excel принимает PNG, JPEG, GIF и BMP,
 * поэтому WebP и другие форматы при необходимости преобразуются в PNG.
 *
 * @return array{ok: bool, path: string, error: string}
 */
function op_images_usable(string $path, bool $convert): array
{
    $info = @getimagesize($path);
    $type = is_array($info) ? (int) ($info[2] ?? 0) : 0;

    if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_BMP], true)) {
        return ['ok' => true, 'path' => $path, 'error' => ''];
    }

    if (!$convert) {
        return ['ok' => false, 'path' => $path, 'error' => 'формат не поддерживается Excel (нужны PNG, JPEG, GIF или BMP)'];
    }

    $contents = @file_get_contents($path);
    $image = $contents === false ? false : @imagecreatefromstring($contents);
    if ($image === false) {
        return ['ok' => false, 'path' => $path, 'error' => 'по адресу не изображение — возможно, фото для этого товара отсутствует'];
    }

    $target = (string) preg_replace('~\.[a-zA-Z0-9]{1,5}$~', '', $path) . '.png';
    $saved = @imagepng($image, $target, 6);
    imagedestroy($image);

    if ($saved === false) {
        return ['ok' => false, 'path' => $path, 'error' => 'не удалось преобразовать изображение в PNG'];
    }

    if ($target !== $path) {
        @unlink($path);
    }

    return ['ok' => true, 'path' => $target, 'error' => ''];
}

/**
 * Размер изображения по правилу вписывания.
 *
 * @param array{width: int, height: int}|null $box
 * @return array{width: int, height: int} Нулевая сторона означает «оставить исходный размер»
 */
function op_images_dimensions(string $fit, int $imageWidth, int $imageHeight, int $width, int $height, ?array $box): array
{
    if ($fit === 'cell' && $box !== null && $box['width'] > 0 && $box['height'] > 0 && $imageWidth > 0 && $imageHeight > 0) {
        $scale = min($box['width'] / $imageWidth, $box['height'] / $imageHeight);

        return [
            'width' => max(1, (int) round($imageWidth * $scale)),
            'height' => max(1, (int) round($imageHeight * $scale)),
        ];
    }

    if ($fit === 'both') {
        return ['width' => $width, 'height' => $height];
    }

    if ($fit === 'width' && $width > 0) {
        return ['width' => $width, 'height' => 0];
    }

    if ($height > 0) {
        return ['width' => 0, 'height' => $height];
    }

    if ($width > 0) {
        return ['width' => $width, 'height' => 0];
    }

    return ['width' => 0, 'height' => 0];
}

/**
 * Размер ячейки в пикселях — для вписывания изображения по размеру ячейки.
 *
 * @return array{width: int, height: int}
 */
function op_images_box(Worksheet $sheet, string $column, int $row, int $offsetX, int $offsetY): array
{
    $columnWidth = $sheet->getColumnDimension($column)->getWidth();
    if ($columnWidth < 0) {
        $columnWidth = 8.43;
    }

    $rowHeight = $sheet->getRowDimension($row)->getRowHeight();
    if ($rowHeight < 0) {
        $rowHeight = 15.0;
    }

    return [
        'width' => max(1, DrawingHelper::cellDimensionToPixels($columnWidth, $sheet->getStyle($column . $row)->getFont()) - 2 * $offsetX),
        'height' => max(1, DrawingHelper::pointsToPixels($rowHeight) - 2 * $offsetY),
    ];
}
