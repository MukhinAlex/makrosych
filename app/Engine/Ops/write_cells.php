<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Excel;
use App\Engine\RowFiller;

return [
    'op' => 'write_cells',
    'title' => 'Заполнение шаблона',
    'description' => 'Записывает значения, формулы и активные гиперссылки в готовый шаблон Excel, сохраняя оформление. Строка шаблона находится сопоставлением колонки-ключа (обычно артикула или кода) со значением поля из сценария. Гиперссылка ставится параметром hyperlinks и может сохранить существующий текст ячейки, формула — параметром formulas.',
    'network' => false,
    'ai' => false,
    'params' => [
        'template' => ['type' => 'string', 'required' => true, 'desc' => 'Псевдоним файла-шаблона (обычно template)'],
        'sheet' => ['type' => 'int|string', 'default' => 1, 'desc' => 'Лист шаблона'],
        'match' => ['type' => 'object', 'required' => true, 'desc' => 'Ключ сопоставления: {"column": "A", "field": "code"}'],
        'cells' => ['type' => 'object', 'desc' => 'Колонка шаблона => поле результата: {"F": "barcodes", "K": "weights"}'],
        'formulas' => ['type' => 'object', 'desc' => 'Формулы Excel: колонка => формула, например {"D": "=B{row}*C{row}"}. Доступны {row} — номер строки шаблона, {col:поле} — буква колонки с записанным полем, {поле} — значение поля'],
        'hyperlinks' => ['type' => 'object', 'desc' => 'Активные ссылки: колонка => {"url_field": "поле со ссылкой"} либо {"template": "https://сайт/catalog/{code}"} либо {"folder": "C:/Фото", "pattern": "{name}.*"} — ссылка на файл, найденный в папке; keep_text=true (по умолчанию) оставляет текст ячейки прежним'],
        'insert_columns' => ['type' => 'object', 'desc' => 'Вставить колонки со сдвигом вправо: {"B": "Изображение"}. Буква — место в итоговом файле, заголовок пишется в строку header_row'],
        'header_row' => ['type' => 'int', 'default' => 1, 'desc' => 'Строка заголовков шаблона — куда писать название вставленной колонки'],
        'data_from_row' => ['type' => 'int', 'default' => 2, 'desc' => 'С какой строки искать совпадения'],
        'missing' => ['type' => 'string', 'default' => 'skip', 'desc' => 'Что делать, если совпадение не найдено: skip — пропустить, error — остановить'],
        'output' => ['type' => 'string', 'default' => 'результат.xlsx', 'desc' => 'Имя файла результата'],
        'wrap_text' => ['type' => 'bool|string[]', 'desc' => 'Включить перенос текста: true — во всех записываемых колонках, либо список колонок, например ["F"]. Нужно, когда значение содержит перенос строки'],
        'vertical_align' => ['type' => 'string', 'desc' => 'Вертикальное выравнивание записанных ячеек: top, center, bottom'],
        'add_missing_sheet' => ['type' => 'string', 'desc' => 'Имя листа, куда выписать не найденные ключи (для контроля)'],
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

        $cells = (array) ($p['cells'] ?? []);
        $formulas = (array) ($p['formulas'] ?? []);
        $hyperlinks = (array) ($p['hyperlinks'] ?? []);

        if ($cells === [] && $formulas === [] && $hyperlinks === []) {
            throw new \RuntimeException('Не задано ни колонок для записи (cells), ни формул (formulas), ни ссылок (hyperlinks)');
        }

        $dataFrom = (int) ($p['data_from_row'] ?? 2);
        $missing = (string) ($p['missing'] ?? 'skip');
        $output = (string) ($p['output'] ?? 'результат.xlsx');
        $missingSheetName = (string) ($p['add_missing_sheet'] ?? '');

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

        $written = 0;
        $linksSet = 0;
        $formulasSet = 0;
        $notFound = [];

        foreach ($ctx->rows as $record) {
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

            $filled = RowFiller::apply($ctx, $sheet, $targetRow, $cells, $formulas, $hyperlinks, $record, $wrapColumns, $verticalAlign);
            $formulasSet += $filled['formulas'];
            $linksSet += $filled['links'];

            $written++;
        }

        if ($missingSheetName !== '' && $notFound !== []) {
            $sheet->getParent()->createSheet()->setTitle($missingSheetName);
            $extra = $sheet->getParent()->getSheetByName($missingSheetName);
            if ($extra !== null) {
                $extra->setCellValue('A1', 'Ключи, не найденные в шаблоне');
                $rowNumber = 2;
                foreach ($notFound as $key) {
                    $extra->setCellValue('A' . $rowNumber, $key);
                    $rowNumber++;
                }
            }
        }

        if ($ctx->dryRun) {
            $ctx->plan('excel', $output, ['rows' => $written, 'links' => $linksSet, 'formulas' => $formulasSet, 'columns' => $insertedColumns, 'not_found' => count($notFound)]);
            $ctx->increment('строк заполнено', $written);
            $ctx->increment('ссылок поставлено', $linksSet);
            $ctx->increment('формул записано', $formulasSet);
            $ctx->increment('колонок вставлено', $insertedColumns);
            $ctx->info(
                "Проверка шаблона: заполнено строк {$written}"
                . ($insertedColumns > 0 ? ", вставлено колонок: {$insertedColumns}" : '')
                . ($linksSet > 0 ? ", ссылок: {$linksSet}" : '')
                . ($formulasSet > 0 ? ", формул: {$formulasSet}" : '')
                . ($notFound !== [] ? ", не найдено ключей: " . count($notFound) : '')
            );

            return;
        }

        $path = $ctx->outPath($output);
        Excel::save($spreadsheet, $path);
        $spreadsheet->disconnectWorksheets();

        $ctx->increment('строк заполнено', $written);
        $ctx->increment('ссылок поставлено', $linksSet);
        $ctx->increment('формул записано', $formulasSet);
        $ctx->increment('колонок вставлено', $insertedColumns);
        $ctx->files[] = ['kind' => 'excel', 'path' => $path, 'status' => 'ok', 'rows' => $written, 'links' => $linksSet, 'formulas' => $formulasSet, 'columns' => $insertedColumns];
        $ctx->plan('excel', $output, ['rows' => $written, 'links' => $linksSet, 'formulas' => $formulasSet, 'columns' => $insertedColumns, 'not_found' => count($notFound)]);
        $ctx->info(
            "Шаблон заполнен: строк {$written}"
            . ($insertedColumns > 0 ? ", вставлено колонок: {$insertedColumns}" : '')
            . ($linksSet > 0 ? ", ссылок: {$linksSet}" : '')
            . ($formulasSet > 0 ? ", формул: {$formulasSet}" : '')
            . ($notFound !== [] ? ", не найдено ключей: " . count($notFound) : '')
        );
    },
];
