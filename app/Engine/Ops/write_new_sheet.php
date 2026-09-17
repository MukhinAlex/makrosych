<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Excel;

return [
    'op' => 'write_new_sheet',
    'title' => 'Создание файла результата',
    'description' => 'Формирует новый файл Excel из текущего набора строк. Колонки задаются списком: буква, заголовок и поле результата либо формула Excel. Значения-списки склеиваются через ";". Итоговая строка задаётся параметром totals.',
    'network' => false,
    'ai' => false,
    'params' => [
        'columns' => ['type' => 'object[]', 'required' => true, 'desc' => 'Колонки: [{"column": "A", "title": "Артикул", "field": "article"}, {"column": "D", "title": "Сумма", "formula": "=B{row}*C{row}"}]. В формуле доступны {row} — номер строки, {last} — номер последней строки с данными, {col:поле} — буква колонки с полем, {поле} — значение поля'],
        'output' => ['type' => 'string', 'default' => 'результат.xlsx', 'desc' => 'Имя файла результата'],
        'sheet_name' => ['type' => 'string', 'default' => 'Результат', 'desc' => 'Название листа'],
        'totals' => ['type' => 'object[]', 'desc' => 'Итоговая строка: [{"column": "D", "label": "Итого", "formula": "=SUM(D2:D{last})"}]'],
        'bold_totals' => ['type' => 'bool', 'default' => true, 'desc' => 'Итоговую строку полужирным'],
        'autosize' => ['type' => 'bool', 'default' => true, 'desc' => 'Подобрать ширину колонок'],
        'freeze_header' => ['type' => 'bool', 'default' => true, 'desc' => 'Закрепить строку заголовков'],
        'bold_header' => ['type' => 'bool', 'default' => true, 'desc' => 'Заголовки полужирным'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $columns = (array) $p['columns'];
        if ($columns === []) {
            throw new \RuntimeException('Не задан список колонок результата');
        }

        $output = (string) ($p['output'] ?? 'результат.xlsx');
        $sheetName = (string) ($p['sheet_name'] ?? 'Результат');
        $totals = (array) ($p['totals'] ?? []);
        $boldTotals = (bool) ($p['bold_totals'] ?? true);
        $autosize = (bool) ($p['autosize'] ?? true);
        $freezeHeader = (bool) ($p['freeze_header'] ?? true);
        $boldHeader = (bool) ($p['bold_header'] ?? true);

        $spreadsheet = Excel::newSpreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($sheetName, 0, 31));

        // Буквы колонок по полям — для формул вида {col:поле}{row}
        $fieldColumns = [];
        foreach ($columns as $column) {
            $column = (array) $column;
            $field = (string) ($column['field'] ?? '');
            if ($field !== '') {
                $fieldColumns[$field] = strtoupper((string) ($column['column'] ?? ''));
            }
        }

        foreach ($columns as $column) {
            $column = (array) $column;
            $letter = strtoupper((string) ($column['column'] ?? ''));
            if ($letter === '') {
                continue;
            }

            $sheet->setCellValue($letter . '1', (string) ($column['title'] ?? $letter));
            if ($boldHeader) {
                $sheet->getStyle($letter . '1')->getFont()->setBold(true);
            }
        }

        $lastDataRow = 1 + count($ctx->rows);
        $formulasWritten = 0;

        $rowNumber = 2;
        foreach ($ctx->rows as $record) {
            foreach ($columns as $column) {
                $column = (array) $column;
                $letter = strtoupper((string) ($column['column'] ?? ''));
                if ($letter === '') {
                    continue;
                }

                $formula = (string) ($column['formula'] ?? '');
                if ($formula !== '') {
                    $rendered = Excel::renderFormula(
                        $formula,
                        ['row' => $rowNumber, 'last' => $lastDataRow],
                        $fieldColumns,
                        $record
                    );
                    if (str_contains($rendered, '{')) {
                        $ctx->warn("Формула для {$letter}{$rowNumber} содержит неподставленные значения и пропущена: {$rendered}");
                        continue;
                    }

                    $sheet->setCellValue($letter . $rowNumber, $rendered);
                    $formulasWritten++;
                    continue;
                }

                $field = (string) ($column['field'] ?? '');
                if ($field === '') {
                    continue;
                }

                $value = $record[$field] ?? '';
                if (is_array($value)) {
                    $value = implode(';', array_map(static fn ($item) => is_array($item) ? '' : (string) $item, $value));
                } elseif (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }

                // Посчитанные итоги (сумма, среднее) записываем числами, чтобы Excel
                // мог их складывать. Значения, прочитанные из файла, остаются текстом:
                // так сохраняются коды и штрихкоды с ведущими нулями.
                $sheet->setCellValueExplicit(
                    $letter . $rowNumber,
                    $value,
                    is_int($value) || is_float($value)
                        ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                        : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
            }
            $rowNumber++;
        }

        // Итоговая строка под данными
        if ($totals !== []) {
            $totalsRow = $lastDataRow + 1;
            foreach ($totals as $total) {
                $total = (array) $total;
                $letter = strtoupper((string) ($total['column'] ?? ''));
                if ($letter === '') {
                    continue;
                }

                if (isset($total['label'])) {
                    $sheet->setCellValue($letter . $totalsRow, (string) $total['label']);
                }

                $formula = (string) ($total['formula'] ?? '');
                if ($formula !== '') {
                    $rendered = Excel::renderFormula(
                        $formula,
                        ['row' => $totalsRow, 'last' => $lastDataRow],
                        $fieldColumns,
                        []
                    );
                    if (str_contains($rendered, '{')) {
                        $ctx->warn("Формула итога для {$letter}{$totalsRow} содержит неподставленные значения и пропущена: {$rendered}");
                    } else {
                        $sheet->setCellValue($letter . $totalsRow, $rendered);
                        $formulasWritten++;
                    }
                }

                if ($boldTotals) {
                    $sheet->getStyle($letter . $totalsRow)->getFont()->setBold(true);
                }
            }
        }

        if ($freezeHeader) {
            $sheet->freezePane('A2');
        }

        if ($autosize) {
            foreach ($columns as $column) {
                $letter = strtoupper((string) (((array) $column)['column'] ?? ''));
                if ($letter !== '') {
                    $sheet->getColumnDimension($letter)->setAutoSize(true);
                }
            }
        }

        $rowsWritten = $rowNumber - 2;

        if ($ctx->dryRun) {
            $ctx->plan('excel', $output, ['rows' => $rowsWritten, 'formulas' => $formulasWritten]);
            $ctx->increment('строк результата', $rowsWritten);
            $ctx->increment('формул записано', $formulasWritten);
            $ctx->info(
                "Проверка: в файл «{$output}» будет записано строк — {$rowsWritten}"
                . ($formulasWritten > 0 ? ", формул: {$formulasWritten}" : '')
            );

            return;
        }

        $path = $ctx->outPath($output);
        Excel::save($spreadsheet, $path);
        $spreadsheet->disconnectWorksheets();

        $ctx->increment('строк результата', $rowsWritten);
        $ctx->increment('формул записано', $formulasWritten);
        $ctx->files[] = ['kind' => 'excel', 'status' => 'ok', 'path' => $path, 'rows' => $rowsWritten, 'formulas' => $formulasWritten];
        $ctx->plan('excel', $output, ['rows' => $rowsWritten, 'formulas' => $formulasWritten]);
        $ctx->info(
            "Создан файл «{$output}»: строк — {$rowsWritten}"
            . ($formulasWritten > 0 ? ", формул: {$formulasWritten}" : '')
        );
    },
];
