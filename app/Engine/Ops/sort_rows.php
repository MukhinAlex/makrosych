<?php

declare(strict_types=1);

use App\Engine\Context;

return [
    'op' => 'sort_rows',
    'title' => 'Сортировка строк',
    'description' => 'Сортирует строки по значению поля — по возрастанию или по убыванию. Нужна там, где важно расположить строки по величине: например, для ABC-анализа сначала сортируют артикулы по сумме от большей к меньшей. Числа и текст сравниваются по-разному, поэтому укажите "numeric": true для сумм и количеств.',
    'network' => false,
    'ai' => false,
    'params' => [
        'by' => ['type' => 'string|string[]', 'required' => true, 'desc' => 'Поле или список полей сортировки, например "total"'],
        'dir' => ['type' => 'string', 'default' => 'asc', 'desc' => 'Направление: asc (по возрастанию) или desc (по убыванию)'],
        'numeric' => ['type' => 'bool', 'default' => false, 'desc' => 'Сравнивать как числа (для сумм, количеств, цен)'],
        'ignore_case' => ['type' => 'bool', 'default' => true, 'desc' => 'Не различать регистр при сортировке текста'],
        'empty_last' => ['type' => 'bool', 'default' => true, 'desc' => 'Пустые значения ставить в конец, а не в начало'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $by = is_array($p['by'] ?? null)
            ? array_values(array_map('strval', $p['by']))
            : [(string) $p['by']];
        $desc = strtolower((string) ($p['dir'] ?? 'asc')) === 'desc';
        $numeric = (bool) ($p['numeric'] ?? false);
        $ignoreCase = (bool) ($p['ignore_case'] ?? true);
        $emptyLast = (bool) ($p['empty_last'] ?? true);

        $sign = $desc ? -1 : 1;
        $rows = array_values($ctx->rows);

        usort($rows, static function (array $a, array $b) use ($by, $numeric, $ignoreCase, $emptyLast, $sign): int {
            foreach ($by as $field) {
                $left = $a[$field] ?? '';
                $right = $b[$field] ?? '';

                if (is_array($left)) {
                    $left = implode(';', array_map(static fn ($item) => is_array($item) ? '' : (string) $item, $left));
                }
                if (is_array($right)) {
                    $right = implode(';', array_map(static fn ($item) => is_array($item) ? '' : (string) $item, $right));
                }

                $left = trim((string) $left);
                $right = trim((string) $right);

                if ($left === '' || $right === '') {
                    if ($left === $right) {
                        continue;
                    }
                    // Пустые значения — отдельно от чисел: иначе строка без данных
                    // встанет первой при сортировке по возрастанию
                    $emptyOrder = $emptyLast ? 1 : -1;

                    return $left === '' ? $emptyOrder : -$emptyOrder;
                }

                if ($numeric) {
                    $leftNumber = is_numeric($left) ? (float) $left : null;
                    $rightNumber = is_numeric($right) ? (float) $right : null;

                    if ($leftNumber === null || $rightNumber === null) {
                        if ($leftNumber === $rightNumber) {
                            continue;
                        }

                        return $leftNumber === null ? 1 : -1;
                    }

                    if ($leftNumber !== $rightNumber) {
                        return $leftNumber < $rightNumber ? -$sign : $sign;
                    }

                    continue;
                }

                $leftText = $ignoreCase ? mb_strtolower($left) : $left;
                $rightText = $ignoreCase ? mb_strtolower($right) : $right;

                if ($leftText !== $rightText) {
                    return $leftText < $rightText ? -$sign : $sign;
                }
            }

            return 0;
        });

        $ctx->rows = $rows;
        $ctx->increment('отсортировано строк', count($rows));
        $ctx->info(
            'Отсортировано строк: ' . count($rows)
            . ' — по полю «' . implode(', ', $by) . '»'
            . ($desc ? ' по убыванию' : ' по возрастанию')
            . ($numeric ? ', как числа' : '')
        );
    },
];
