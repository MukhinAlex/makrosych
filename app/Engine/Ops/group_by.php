<?php

declare(strict_types=1);

use App\Engine\Context;

return [
    'op' => 'group_by',
    'title' => 'Группировка по полю',
    'description' => 'Собирает строки в группы по значению одного или нескольких полей. После этой операции каждая строка соответствует одной группе (например, одному артикулу), а исходные строки доступны в поле _items — их используют операции join_values (склейка значений) и aggregate (итоги: сумма, среднее, количество).',
    'network' => false,
    'ai' => false,
    'params' => [
        'by' => ['type' => 'string|string[]', 'required' => true, 'desc' => 'Поле или список полей группировки, например "article"'],
        'into' => ['type' => 'string', 'default' => '_items', 'desc' => 'Имя поля со списком строк группы'],
        'keep' => ['type' => 'string[]', 'desc' => 'Дополнительные поля, копируемые из первой строки группы'],
        'sort' => ['type' => 'bool', 'default' => false, 'desc' => 'Отсортировать группы по ключу'],
        'min_count' => ['type' => 'int', 'default' => 1, 'desc' => 'Пропускать группы меньше указанного размера'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $by = is_array($p['by'] ?? null) ? array_values(array_map('strval', $p['by'])) : [(string) $p['by']];
        $into = (string) ($p['into'] ?? '_items');
        $keep = array_map('strval', (array) ($p['keep'] ?? []));
        $sort = (bool) ($p['sort'] ?? false);
        $minCount = (int) ($p['min_count'] ?? 1);

        $groups = [];
        $order = [];

        foreach ($ctx->rows as $row) {
            $parts = [];
            foreach ($by as $field) {
                $value = $row[$field] ?? '';
                if (is_array($value)) {
                    $value = implode(';', array_map('strval', $value));
                }
                $parts[] = (string) $value;
            }

            $key = implode("\x1f", $parts);
            if (!isset($groups[$key])) {
                $groups[$key] = ['fields' => array_combine($by, $parts), 'first' => $row, 'items' => []];
                $order[] = $key;
            }

            $groups[$key]['items'][] = $row;
        }

        if ($sort) {
            sort($order);
        }

        $result = [];
        foreach ($order as $key) {
            $group = $groups[$key];
            if (count($group['items']) < $minCount) {
                continue;
            }

            $record = $group['fields'];
            foreach ($keep as $field) {
                $record[$field] = $group['first'][$field] ?? null;
            }
            $record[$into] = $group['items'];
            $record['_count'] = count($group['items']);

            $result[] = $record;
        }

        $ctx->rows = $result;
        $ctx->increment('групп', count($result));
        $ctx->info('Сформировано групп: ' . count($result));
    },
];
