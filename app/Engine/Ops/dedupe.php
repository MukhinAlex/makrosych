<?php

declare(strict_types=1);

use App\Engine\Context;

return [
    'op' => 'dedupe',
    'title' => 'Убрать дубликаты строк',
    'description' => 'Оставляет по одной строке на каждое значение ключа — убирает повторы (например, товар, попавший в выгрузку несколько раз). Аналог «Удалить дубликаты» в Excel: первая строка ключа остаётся как есть, а в отдельное поле можно записать, сколько раз ключ встретился. Строки с пустым ключом по умолчанию сохраняются как есть.',
    'network' => false,
    'ai' => false,
    'params' => [
        'by' => ['type' => 'string|string[]', 'required' => true, 'desc' => 'Поле или список полей, по которым строки считаются одинаковыми, например "article" или ["article", "date"]'],
        'keep' => ['type' => 'string', 'default' => 'first', 'desc' => 'Какую строку оставить: first (первую, по умолчанию) или last (последнюю)'],
        'ignore_case' => ['type' => 'bool', 'default' => true, 'desc' => 'Считать «Товар» и «товар» одним ключом'],
        'trim' => ['type' => 'bool', 'default' => true, 'desc' => 'Не считать разницей пробелы по краям значения'],
        'skip_empty' => ['type' => 'bool', 'default' => true, 'desc' => 'Строки с пустым ключом оставить как есть, а не склеивать их в одну'],
        'count_field' => ['type' => 'string', 'desc' => 'Поле, в которое записать число строк с этим ключом'],
        'sort' => ['type' => 'bool', 'default' => false, 'desc' => 'Отсортировать результат по ключу'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $by = is_array($p['by'] ?? null)
            ? array_values(array_map('strval', $p['by']))
            : [(string) $p['by']];
        $keep = (string) ($p['keep'] ?? 'first') === 'last' ? 'last' : 'first';
        $ignoreCase = (bool) ($p['ignore_case'] ?? true);
        $trim = (bool) ($p['trim'] ?? true);
        $skipEmpty = (bool) ($p['skip_empty'] ?? true);
        $countField = trim((string) ($p['count_field'] ?? ''));
        $sort = (bool) ($p['sort'] ?? false);

        $total = count($ctx->rows);
        $result = [];
        $keys = [];
        $index = [];
        $counts = [];
        $emptyKeys = 0;

        foreach ($ctx->rows as $row) {
            $parts = [];
            $empty = false;
            foreach ($by as $field) {
                $value = $row[$field] ?? '';
                if (is_array($value)) {
                    $value = implode(';', array_map(
                        static fn ($item) => is_array($item) ? '' : (string) $item,
                        $value
                    ));
                }

                $text = (string) $value;
                if ($trim) {
                    $text = trim($text);
                }
                if ($ignoreCase) {
                    $text = mb_strtolower($text);
                }
                if ($text === '') {
                    $empty = true;
                }
                $parts[] = $text;
            }

            $key = implode("\x1f", $parts);

            // Пустой ключ — это не «один и тот же товар»: строки без ключа не склеиваем
            if ($empty && $skipEmpty) {
                $emptyKeys++;
                if ($countField !== '') {
                    $row[$countField] = 1;
                }
                $result[] = $row;
                $keys[] = $key;
                continue;
            }

            if (!isset($index[$key])) {
                $index[$key] = count($result);
                $counts[$key] = 0;
                $result[] = $row;
                $keys[] = $key;
            }

            $counts[$key]++;

            if ($keep === 'last') {
                $result[$index[$key]] = $row;
            }
        }

        if ($countField !== '') {
            foreach ($index as $key => $position) {
                $result[$position][$countField] = $counts[$key];
            }
        }

        if ($sort) {
            // usort в PHP стабильна: строки с одинаковым ключом сохраняют исходный порядок
            $order = array_keys($result);
            usort($order, static fn (int $a, int $b): int => $keys[$a] <=> $keys[$b]);
            $sorted = [];
            foreach ($order as $position) {
                $sorted[] = $result[$position];
            }
            $result = $sorted;
        }

        $removed = $total - count($result);
        $ctx->rows = array_values($result);

        $ctx->increment('убрано дубликатов', $removed);
        $ctx->increment('строк после удаления дубликатов', count($ctx->rows));

        if ($removed > 0) {
            $ctx->info("Убрано дубликатов: {$removed} — строк было {$total}, стало " . count($ctx->rows));
        } else {
            $ctx->info('Дубликатов не найдено: строк — ' . count($ctx->rows));
        }

        if ($emptyKeys > 0) {
            $ctx->warn("Строк с пустым ключом сохранено без объединения: {$emptyKeys}");
        }
    },
];
