<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Excel;
use App\Engine\Transform;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

return [
    'op' => 'read_rows',
    'title' => 'Чтение строк из файла',
    'description' => 'Читает лист Excel или CSV и превращает строки в наборы полей. Колонки задаются буквами: "B", "AD" или диапазоном "G:J". Если полю соответствует несколько колонок, значение собирается в список (параметр multi). Умеет заполнять объединённые ячейки и преобразовывать значения.',
    'network' => false,
    'ai' => false,
    'params' => [
        'file' => ['type' => 'string', 'required' => true, 'desc' => 'Псевдоним входного файла (обычно input)'],
        'sheet' => ['type' => 'int|string', 'default' => 1, 'desc' => 'Номер листа (с 1) или его имя'],
        'header_rows' => ['type' => 'int[]', 'default' => [1], 'desc' => 'Строки заголовков'],
        'data_from_row' => ['type' => 'int', 'desc' => 'Первая строка данных (по умолчанию — следующая после заголовка)'],
        'columns' => ['type' => 'object', 'required' => true, 'desc' => 'Поле => буква колонки или список букв, например {"article": "B", "links": ["G","H","I","J"]}'],
        'multi' => ['type' => 'string', 'default' => 'list', 'desc' => 'Поведение при нескольких колонках: list — список значений, first — первое непустое, concat — склеить'],
        'concat_separator' => ['type' => 'string', 'default' => ';', 'desc' => 'Разделитель для режима concat'],
        'transform' => ['type' => 'object', 'desc' => 'Поле => правила преобразования значения'],
        'skip_if_empty' => ['type' => 'string[]', 'desc' => 'Пропустить строку, если эти поля пусты'],
        'skip_totals' => ['type' => 'bool', 'default' => true, 'desc' => 'Пропускать строки-итоги: «Итого», «Итог», «Общий итог», «Всего» и формулы ПРОМЕЖУТОЧНЫЙ.ИТОГ (SUBTOTAL). В отчётах это подытоги, а не данные'],
        'fill_down' => ['type' => 'string[]', 'desc' => 'Заполнять пустые значения предыдущим непустым (для объединённых ячеек)'],
        'fill_merged' => ['type' => 'bool', 'default' => true, 'desc' => 'Разворачивать объединённые ячейки'],
        'keep_empty' => ['type' => 'bool', 'default' => false, 'desc' => 'Оставлять полностью пустые строки'],
        'limit' => ['type' => 'int', 'desc' => 'Ограничить число прочитанных строк'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $path = $ctx->inputPath((string) $p['file']);
        $spreadsheet = Excel::load($path);
        $sheet = Excel::sheet($spreadsheet, $p['sheet'] ?? 1);

        $headerRows = array_map('intval', (array) ($p['header_rows'] ?? [1]));
        $headerRow = $headerRows[0] ?? 1;
        $dataFrom = (int) ($p['data_from_row'] ?? ($headerRow + 1));

        $lastRow = $sheet->getHighestDataRow();
        $lastColumn = $sheet->getHighestDataColumn();

        if ($lastRow < $dataFrom) {
            $ctx->warn("Лист «{$sheet->getTitle()}» не содержит данных с строки {$dataFrom}");

            return;
        }

        $grid = Excel::readRange($sheet, 1, $lastRow, $lastColumn);
        if ((bool) ($p['fill_merged'] ?? true)) {
            Excel::fillMerged($sheet, $grid);
        }

        $mapping = [];
        foreach ((array) $p['columns'] as $field => $spec) {
            $letters = Excel::columns($spec);
            if ($letters !== []) {
                $mapping[(string) $field] = $letters;
            }
        }

        if ($mapping === []) {
            throw new \RuntimeException('Не задано ни одной колонки для чтения');
        }

        $multi = (string) ($p['multi'] ?? 'list');
        $concatSeparator = (string) ($p['concat_separator'] ?? ';');
        $transforms = (array) ($p['transform'] ?? []);
        $skipIfEmpty = array_map('strval', (array) ($p['skip_if_empty'] ?? []));
        $skipTotals = (bool) ($p['skip_totals'] ?? true);
        $fillDown = array_map('strval', (array) ($p['fill_down'] ?? []));
        $keepEmpty = (bool) ($p['keep_empty'] ?? false);
        $limit = (int) ($p['limit'] ?? 0);

        /**
         * Строка-итог из готового отчёта: «Итого», «Общий итог», «X Итог», «Всего».
         * Такие строки — подытоги, и если сложить их вместе с данными, сумма вырастет
         * в разы. Признак берётся по подписи (слово целиком) или по формуле
         * ПРОМЕЖУТОЧНЫЙ.ИТОГ (SUBTOTAL), которую Excel ставит только в подытоги.
         */
        $isTotalRow = static function (array $record, array $mapping, Worksheet $sheet, int $row): bool {
            foreach ($record as $field => $value) {
                if ($field === '_row' || is_array($value)) {
                    continue;
                }

                $text = Excel::text($value);
                if ($text === '' || is_numeric(str_replace([' ', ','], ['', '.'], $text))) {
                    continue;
                }

                if (preg_match('~(^|[^\p{L}])(итог|итого|итоги|всего|total|subtotal)([^\p{L}]|$)~u', mb_strtolower($text)) === 1) {
                    return true;
                }
            }

            foreach ($mapping as $letters) {
                foreach ($letters as $letter) {
                    $raw = strtoupper(trim((string) $sheet->getCell($letter . $row)->getValue()));
                    if (str_starts_with($raw, '=SUBTOTAL(')) {
                        return true;
                    }
                }
            }

            return false;
        };

        $previous = [];
        $read = 0;
        $skipped = 0;
        $skippedTotals = 0;

        for ($row = $dataFrom; $row <= $lastRow; $row++) {
            $record = ['_row' => $row];
            $allEmpty = true;

            foreach ($mapping as $field => $letters) {
                $values = [];
                foreach ($letters as $letter) {
                    $values[] = Excel::text(Excel::cell($grid, $row, $letter));
                }

                if ($multi === 'list') {
                    $value = count($values) === 1 ? $values[0] : $values;
                } elseif ($multi === 'concat') {
                    $value = implode($concatSeparator, array_filter($values, static fn ($v) => $v !== ''));
                } else {
                    $value = '';
                    foreach ($values as $candidate) {
                        if ($candidate !== '') {
                            $value = $candidate;
                            break;
                        }
                    }
                }

                if (isset($transforms[$field])) {
                    $rules = (array) $transforms[$field];
                    if (is_array($value)) {
                        $value = array_map(static fn ($item) => Transform::apply($item, $rules), $value);
                    } else {
                        $value = Transform::apply($value, $rules);
                    }
                }

                $record[$field] = $value;
                if (!Excel::isEmpty($value)) {
                    $allEmpty = false;
                }
            }

            foreach ($fillDown as $field) {
                if (Excel::isEmpty($record[$field] ?? null) && isset($previous[$field])) {
                    $record[$field] = $previous[$field];
                    $allEmpty = false;
                }
            }

            foreach ($mapping as $field => $letters) {
                $previous[$field] = $record[$field] ?? null;
            }

            if ($allEmpty && !$keepEmpty) {
                $skipped++;
                continue;
            }

            $skip = false;
            foreach ($skipIfEmpty as $field) {
                if (Excel::isEmpty($record[$field] ?? null)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                $skipped++;
                continue;
            }

            if ($skipTotals && $isTotalRow($record, $mapping, $sheet, $row)) {
                $skippedTotals++;
                continue;
            }

            $ctx->rows[] = $record;
            $read++;

            if ($limit > 0 && $read >= $limit) {
                break;
            }
        }

        $ctx->increment('прочитано строк', $read);
        $ctx->info("Прочитано строк: {$read}" . ($skipped > 0 ? ", пропущено: {$skipped}" : ''));

        if ($skippedTotals > 0) {
            $ctx->increment('пропущено строк-итогов', $skippedTotals);
            $ctx->info(
                "Пропущено строк-итогов: {$skippedTotals}"
                . ' (в них написано «Итого», «Итог» или «Всего» — это подытоги, а не данные)'
            );
        }

        $spreadsheet->disconnectWorksheets();
    },
];
