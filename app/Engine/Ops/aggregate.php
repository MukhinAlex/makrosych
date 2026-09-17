<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Excel;
use App\Engine\Totals;

return [
    'op' => 'aggregate',
    'title' => 'Итоги по группе (сумма, среднее, количество)',
    'description' => 'Считает итоги по строкам группы: сумму, среднее, минимум, максимум, количество, число различных значений, первое/последнее значение или склейку через разделитель. Работает после операции group_by (берёт строки группы из поля _items) — например, «сумма хранения по артикулу». В отличие от join_values, которая только склеивает значения в текст, эта операция действительно складывает числа. Без предварительной группировки подводит итог по всей таблице и оставляет одну строку. Если нужны не итоги, а строки данных со строкой «Итог» после каждой группы, используйте операцию subtotals.',
    'network' => false,
    'ai' => false,
    'params' => [
        'from' => ['type' => 'string', 'default' => '_items', 'desc' => 'Поле со списком строк группы (его создаёт group_by)'],
        'map' => ['type' => 'object', 'required' => true, 'desc' => 'Результат => правило. Правило — имя поля (тогда считается сумма) или объект {"field": "storage_sum", "agg": "sum", "round": 2}; если поле не указано, берётся поле с именем результата. Виды итогов (agg): sum — сумма, avg — среднее, min, max, count — количество строк (или непустых значений указанного поля), unique_count — число различных, first, last, join — склейка через разделитель'],
        'separator' => ['type' => 'string', 'default' => ';', 'desc' => 'Разделитель для итога join'],
        'skip_empty' => ['type' => 'bool', 'default' => true, 'desc' => 'Не учитывать пустые значения'],
        'sort' => ['type' => 'bool', 'default' => false, 'desc' => 'Сортировать значения для итога join'],
        'round' => ['type' => 'int', 'desc' => 'Округлить числовые итоги до N знаков (если не задано в правиле)'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $from = (string) ($p['from'] ?? '_items');
        $map = (array) ($p['map'] ?? []);
        if ($map === []) {
            throw new \RuntimeException('Не задано, какие итоги считать (map)');
        }

        if ($ctx->rows === []) {
            $ctx->warn('Подводить итоги не по чему: строк нет');

            return;
        }

        // Группировки не было — считаем итог по всей таблице
        $grouped = false;
        foreach ($ctx->rows as $row) {
            if (is_array($row[$from] ?? null)) {
                $grouped = true;
                break;
            }
        }

        $records = $grouped ? $ctx->rows : [[$from => $ctx->rows]];

        $result = [];
        $computed = 0;
        $skipped = 0;
        $sample = '';

        foreach ($records as $record) {
            $items = $record[$from] ?? null;
            if (!is_array($items)) {
                $items = [$record];
            }

            $totals = Totals::compute($items, $map, [
                'separator' => $p['separator'] ?? ';',
                'skip_empty' => $p['skip_empty'] ?? true,
                'sort' => $p['sort'] ?? false,
                'round' => $p['round'] ?? null,
            ]);

            $computed += $totals['computed'];
            $skipped += $totals['skipped'];
            if ($sample === '') {
                $sample = $totals['sample'];
            }

            $result[] = $grouped ? $record + $totals['values'] : $totals['values'];
        }

        $ctx->rows = $result;
        $ctx->increment('итогов посчитано', $computed);
        $ctx->info('Посчитано итогов: ' . $computed . ' в ' . count($result) . ' строках');

        if (!$grouped) {
            $ctx->info('Группировки не было: итоги посчитаны по всей таблице');
        }

        if ($skipped > 0) {
            $ctx->warn(
                'В числовые итоги не попали нечисловые значения: ' . $skipped
                . ($sample !== '' ? ', например «' . Excel::text($sample) . '»' : '')
            );
        }
    },
];
