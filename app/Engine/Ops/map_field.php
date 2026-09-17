<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Transform;

return [
    'op' => 'map_field',
    'title' => 'Преобразование поля',
    'description' => 'Применяет правила преобразования к значению поля и записывает результат — в то же поле или в новое. Например, перевод килограммов в граммы или удаление лишних символов.',
    'network' => false,
    'ai' => false,
    'params' => [
        'field' => ['type' => 'string', 'required' => true, 'desc' => 'Исходное поле'],
        'into' => ['type' => 'string', 'desc' => 'Куда записать результат (по умолчанию — в исходное поле)'],
        'transform' => ['type' => 'object', 'required' => true, 'desc' => 'Правила преобразования, например {"multiply": 1000, "round": 0}'],
        'when' => ['type' => 'object', 'desc' => 'Применять только к строкам, удовлетворяющим условию: {"op": "not_empty"}'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $field = (string) $p['field'];
        $into = (string) ($p['into'] ?? $field);
        $rules = (array) ($p['transform'] ?? []);

        foreach ($ctx->rows as $index => $row) {
            $ctx->rows[$index][$into] = Transform::apply($row[$field] ?? null, $rules);
        }

        $ctx->info("Преобразовано поле «{$field}» → «{$into}»");
    },
];
