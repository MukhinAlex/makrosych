<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Excel;
use App\Engine\Transform;

return [
    'op' => 'join_values',
    'title' => 'Склейка значений группы в одну ячейку',
    'description' => 'Собирает значения из строк группы (поле _items) в одну строку через разделитель. Обычно применяется после group_by: например, все штрихкоды грузовых мест через ";". Позволяет одновременно собрать несколько полей и применить к ним преобразования (например, вес ×1000).',
    'network' => false,
    'ai' => false,
    'params' => [
        'from' => ['type' => 'string', 'default' => '_items', 'desc' => 'Поле со списком строк группы'],
        'map' => ['type' => 'object', 'required' => true, 'desc' => 'Результат => исходное поле, например {"barcodes": "barcode", "weights": "weight"}'],
        'separator' => ['type' => 'string', 'default' => ';', 'desc' => 'Разделитель (без пробелов)'],
        'skip_empty' => ['type' => 'bool', 'default' => true, 'desc' => 'Пропускать пустые значения'],
        'unique' => ['type' => 'bool', 'default' => false, 'desc' => 'Убрать повторы'],
        'transform' => ['type' => 'object', 'desc' => 'Результат => правила преобразования, например {"weights": {"multiply": 1000, "round": 0}}'],
        'sort' => ['type' => 'bool', 'default' => false, 'desc' => 'Отсортировать значения перед склейкой'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $from = (string) ($p['from'] ?? '_items');
        $map = (array) ($p['map'] ?? []);
        if ($map === []) {
            throw new \RuntimeException('Не задано соответствие полей (map)');
        }

        $separator = (string) ($p['separator'] ?? ';');
        $skipEmpty = (bool) ($p['skip_empty'] ?? true);
        $unique = (bool) ($p['unique'] ?? false);
        $transforms = (array) ($p['transform'] ?? []);
        $sort = (bool) ($p['sort'] ?? false);

        foreach ($ctx->rows as $index => $row) {
            $items = $row[$from] ?? null;
            if (!is_array($items)) {
                $items = [$row];
            }

            foreach ($map as $target => $source) {
                $values = [];
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $value = $item[(string) $source] ?? null;
                    if (isset($transforms[$target])) {
                        $value = Transform::apply($value, (array) $transforms[$target]);
                    }
                    $value = Excel::text($value);
                    if ($skipEmpty && $value === '') {
                        continue;
                    }
                    $values[] = $value;
                }

                if ($unique) {
                    $values = array_values(array_unique($values));
                }
                if ($sort) {
                    sort($values);
                }

                $ctx->rows[$index][(string) $target] = implode($separator, $values);
            }
        }

        $ctx->info('Склеено полей: ' . count($map) . ' в ' . count($ctx->rows) . ' строках');
    },
];
