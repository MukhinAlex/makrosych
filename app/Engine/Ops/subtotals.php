<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Excel;
use App\Engine\Totals;

return [
    'op' => 'subtotals',
    'title' => 'Промежуточные итоги (строки данных и «Итог» после группы)',
    'description' => 'Выводит строки данных как есть, а после каждой группы добавляет строку-подытог с подписью («X Итог»), в самом конце — общий итог. Это расклад Excel «Промежуточные итоги»: данные не сворачиваются, а дополняются подытогами. Работает после операции group_by (строки группы берутся из поля _items) и обычно ставится перед write_new_sheet вместо aggregate. Итоги считаются теми же правилами, что и в aggregate (sum, avg, min, max, count, unique_count, first, last, join).',
    'network' => false,
    'ai' => false,
    'params' => [
        'from' => ['type' => 'string', 'default' => '_items', 'desc' => 'Поле со списком строк группы (его создаёт group_by)'],
        'label' => ['type' => 'object', 'required' => true, 'desc' => 'Куда и как писать подпись подытога: {"field": "article", "suffix": " Итог"} или {"field": "article", "template": "{article} Итог"}'],
        'map' => ['type' => 'object', 'required' => true, 'desc' => 'Что считать в подытоге: результат => правило, как в aggregate, например {"storage_sum": {"agg": "sum", "round": 2}}. Если поле не указано, берётся поле с именем результата — значение остаётся в своей колонке'],
        'grand_total' => ['type' => 'bool', 'default' => true, 'desc' => 'Добавить общий итог по всем строкам в конце'],
        'grand_label' => ['type' => 'string', 'default' => 'Общий итог', 'desc' => 'Подпись общего итога'],
        'keep' => ['type' => 'string[]', 'desc' => 'Какие поля оставить в строках данных (по умолчанию — все)'],
        'sort_details_by' => ['type' => 'string[]', 'desc' => 'Порядок строк внутри группы, например ["date"]'],
        'separator' => ['type' => 'string', 'default' => ';', 'desc' => 'Разделитель для итога join'],
        'skip_empty' => ['type' => 'bool', 'default' => true, 'desc' => 'Не учитывать пустые значения'],
        'sort' => ['type' => 'bool', 'default' => false, 'desc' => 'Сортировать значения для итога join'],
        'round' => ['type' => 'int', 'desc' => 'Округлить числовые итоги до N знаков (если не задано в правиле)'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $from = (string) ($p['from'] ?? '_items');
        $map = (array) ($p['map'] ?? []);
        if ($map === []) {
            throw new \RuntimeException('Не задано, что считать в подытогах (map)');
        }

        $label = (array) ($p['label'] ?? []);
        $labelField = (string) ($label['field'] ?? '');
        if ($labelField === '') {
            throw new \RuntimeException('Не указано поле для подписи подытога (label.field)');
        }

        $labelTemplate = (string) ($label['template'] ?? '');
        $labelPrefix = (string) ($label['prefix'] ?? '');
        $labelSuffix = array_key_exists('suffix', $label) ? (string) $label['suffix'] : ' Итог';

        $grandTotal = (bool) ($p['grand_total'] ?? true);
        $grandLabel = (string) ($p['grand_label'] ?? 'Общий итог');
        $keep = array_map('strval', (array) ($p['keep'] ?? []));
        $sortBy = array_map('strval', (array) ($p['sort_details_by'] ?? []));

        $options = [
            'separator' => $p['separator'] ?? ';',
            'skip_empty' => $p['skip_empty'] ?? true,
            'sort' => $p['sort'] ?? false,
            'round' => $p['round'] ?? null,
        ];

        if ($ctx->rows === []) {
            $ctx->warn('Выводить нечего: строк нет');

            return;
        }

        // Группировки не было — выводим строки списком и добавляем только общий итог
        $grouped = false;
        foreach ($ctx->rows as $row) {
            if (is_array($row[$from] ?? null)) {
                $grouped = true;
                break;
            }
        }

        $records = $grouped ? $ctx->rows : [[$from => $ctx->rows]];

        $result = [];
        $allItems = [];
        $groupSubtotals = [];
        $subtotalRows = 0;
        $computed = 0;
        $skipped = 0;
        $sample = '';

        $collect = static function (array $totals) use (&$computed, &$skipped, &$sample): void {
            $computed += $totals['computed'];
            $skipped += $totals['skipped'];
            if ($sample === '') {
                $sample = $totals['sample'];
            }
        };

        foreach ($records as $record) {
            $items = $record[$from] ?? null;
            if (!is_array($items)) {
                $items = [$record];
            }

            if ($sortBy !== []) {
                usort($items, static function ($left, $right) use ($sortBy): int {
                    foreach ($sortBy as $field) {
                        $a = Excel::text(is_array($left) ? ($left[$field] ?? '') : '');
                        $b = Excel::text(is_array($right) ? ($right[$field] ?? '') : '');
                        $compare = strnatcasecmp($a, $b);
                        if ($compare !== 0) {
                            return $compare;
                        }
                    }

                    return 0;
                });
            }

            // Строки данных — как есть
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $result[] = $keep === []
                    ? array_diff_key($item, [$from => null])
                    : array_intersect_key($item, array_flip($keep));
                $allItems[] = $item;
            }

            // Строка-подытог после группы
            if ($grouped) {
                $totals = Totals::compute($items, $map, $options);
                $collect($totals);

                $keyValue = $record[$labelField] ?? ($items[0][$labelField] ?? '');
                $labelText = $labelTemplate !== ''
                    ? Excel::renderTemplate($labelTemplate, $record)
                    : $labelPrefix . Excel::text($keyValue) . $labelSuffix;

                $subtotalRow = $totals['values'];
                $subtotalRow[$labelField] = $labelText;
                $result[] = $subtotalRow;
                $groupSubtotals[] = $subtotalRow;
                $subtotalRows++;
            }
        }

        // Общий итог по всем строкам
        if ($grandTotal && $allItems !== []) {
            $totals = Totals::compute($allItems, $map, $options);
            $collect($totals);

            $grandRow = $totals['values'];

            // Суммы складываются из уже посчитанных подытогов, чтобы общий итог сходился
            // с колонкой «X Итог»: если складывать исходные значения, из-за округления
            // каждой группы итог может отличаться на копейки. Остальные виды итогов
            // (среднее, количество, число различных) так считать нельзя — они берутся
            // по всем строкам.
            foreach ($map as $target => $spec) {
                [, $agg, $rule] = Totals::rule($spec);
                if ($agg !== 'sum') {
                    continue;
                }

                $sum = 0.0;
                foreach ($groupSubtotals as $groupRow) {
                    $sum += (float) ($groupRow[(string) $target] ?? 0);
                }

                $round = $rule['round'] ?? $options['round'];
                $grandRow[(string) $target] = $round !== null ? round($sum, (int) $round) : $sum;
            }

            $grandRow[$labelField] = $grandLabel;
            $result[] = $grandRow;
            $subtotalRows++;
        }

        $ctx->rows = $result;
        $ctx->increment('строк-итогов', $subtotalRows);
        $ctx->info(
            'Выведено строк: ' . count($result) . ', из них строк-итогов: ' . $subtotalRows
            . ' (строк данных: ' . count($allItems) . ')'
        );

        if (!$grouped) {
            $ctx->info('Группировки не было: строки выведены списком, добавлен только общий итог');
        }

        if ($skipped > 0) {
            $ctx->warn(
                'В числовые итоги не попали нечисловые значения: ' . $skipped
                . ($sample !== '' ? ', например «' . Excel::text($sample) . '»' : '')
            );
        }
    },
];
