<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Excel;
use App\Engine\Transform;

return [
    'op' => 'read_cargo_blocks',
    'title' => 'Чтение блоков «грузовых мест»',
    'description' => 'Для файлов, где у товара в одной строке перечислены несколько однотипных блоков (грузовых мест). Число блоков берётся из колонки-счётчика, колонки блоков вычисляются от базовых со шагом либо задаются явным списком. Результат: одна строка на товар, внутри поле _items со списком блоков.',
    'network' => false,
    'ai' => false,
    'params' => [
        'file' => ['type' => 'string', 'required' => true, 'desc' => 'Псевдоним входного файла'],
        'sheet' => ['type' => 'int|string', 'default' => 1, 'desc' => 'Номер листа или имя'],
        'data_from_row' => ['type' => 'int', 'default' => 2, 'desc' => 'Первая строка с товарами'],
        'article' => ['type' => 'string', 'required' => true, 'desc' => 'Колонка с артикулом товара'],
        'count' => ['type' => 'string', 'desc' => 'Колонка с количеством блоков'],
        'blocks' => ['type' => 'object', 'required' => true, 'desc' => 'Колонки первого блока: {"length": "M", "width": "N", "weight": "Q", "barcode": "R"}'],
        'mode' => ['type' => 'string', 'default' => 'step', 'desc' => 'step — блоки вычисляются со шагом, explicit — заданы списком'],
        'step' => ['type' => 'int', 'default' => 7, 'desc' => 'Шаг колонок между блоками (для режима step)'],
        'list' => ['type' => 'object[]', 'desc' => 'Явный список блоков: [{"length": "M", "weight": "Q", "barcode": "R"}, …]'],
        'max' => ['type' => 'int', 'default' => 10, 'desc' => 'Ограничение числа блоков'],
        'transform' => ['type' => 'object', 'desc' => 'Поле блока => правила преобразования (например, {"weight": {"multiply": 1000}})'],
        'skip_empty_article' => ['type' => 'bool', 'default' => true, 'desc' => 'Пропускать строки без артикула'],
        'limit' => ['type' => 'int', 'desc' => 'Ограничить число товаров'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $path = $ctx->inputPath((string) $p['file']);
        $spreadsheet = Excel::load($path);
        $sheet = Excel::sheet($spreadsheet, $p['sheet'] ?? 1);

        $dataFrom = (int) ($p['data_from_row'] ?? 2);
        $lastRow = $sheet->getHighestDataRow();
        $lastColumn = $sheet->getHighestDataColumn();
        $grid = Excel::readRange($sheet, 1, $lastRow, $lastColumn);

        $articleCol = strtoupper((string) $p['article']);
        $countCol = isset($p['count']) ? strtoupper((string) $p['count']) : '';
        $base = (array) $p['blocks'];
        $mode = (string) ($p['mode'] ?? 'step');
        $step = (int) ($p['step'] ?? 7);
        $max = max(1, (int) ($p['max'] ?? 10));
        $explicit = (array) ($p['list'] ?? []);
        $transforms = (array) ($p['transform'] ?? []);
        $skipEmptyArticle = (bool) ($p['skip_empty_article'] ?? true);
        $limit = (int) ($p['limit'] ?? 0);

        $rows = 0;
        $blocks = 0;

        for ($row = $dataFrom; $row <= $lastRow; $row++) {
            $article = Excel::text(Excel::cell($grid, $row, $articleCol));
            if ($article === '' && $skipEmptyArticle) {
                continue;
            }

            $count = $countCol !== '' ? (int) Transform::numeric(Excel::cell($grid, $row, $countCol)) : $max;
            if ($count <= 0) {
                $count = $countCol === '' ? $max : 0;
            }
            $count = min($count, $max);

            $items = [];
            for ($index = 1; $index <= $count; $index++) {
                $columns = op_cargo_block_columns($base, $mode, $step, $explicit, $index);
                $item = [];
                $empty = true;

                foreach ($columns as $field => $letter) {
                    $value = Excel::text(Excel::cell($grid, $row, $letter));
                    if (isset($transforms[$field])) {
                        $value = Transform::apply($value, (array) $transforms[$field]);
                    }
                    $item[(string) $field] = $value;
                    if (!Excel::isEmpty($value)) {
                        $empty = false;
                    }
                }

                if (!$empty) {
                    $item['_index'] = $index;
                    $items[] = $item;
                }
            }

            if ($items === []) {
                continue;
            }

            $ctx->rows[] = [
                'article' => $article,
                '_items' => $items,
                '_count' => count($items),
                '_row' => $row,
            ];

            $rows++;
            $blocks += count($items);

            if ($limit > 0 && $rows >= $limit) {
                break;
            }
        }

        $ctx->increment('товаров', $rows);
        $ctx->increment('грузовых мест', $blocks);
        $ctx->info("Товаров: {$rows}, грузовых мест: {$blocks}");
        $spreadsheet->disconnectWorksheets();
    },
];

/**
 * Колонки одного блока.
 *
 * @param array<string, string> $base
 * @param array<int, array<string, string>> $explicit
 * @return array<string, string>
 */
function op_cargo_block_columns(array $base, string $mode, int $step, array $explicit, int $index): array
{
    if ($mode === 'explicit' && isset($explicit[$index - 1])) {
        return $explicit[$index - 1];
    }

    $offset = ($index - 1) * $step;
    $columns = [];
    foreach ($base as $field => $letter) {
        $columns[(string) $field] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(strtoupper((string) $letter)) + $offset
        );
    }

    return $columns;
}
