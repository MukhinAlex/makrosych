<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Excel;

return [
    'op' => 'carry_value',
    'title' => 'Перенос значения вниз по строкам',
    'description' => 'Для файлов, где товар занимает строку-заголовок, а под ней идут строки грузовых мест. Строки, у которых поле-признак пустое, задают текущее значение (например, артикул товара); строки, у которых признак заполнен (штрихкод грузового места), получают это значение. Так грузовые места привязываются к своему товару.',
    'network' => false,
    'ai' => false,
    'params' => [
        'field' => ['type' => 'string', 'required' => true, 'desc' => 'Поле, значение которого переносится вниз (например, article)'],
        'marker' => ['type' => 'string', 'required' => true, 'desc' => 'Поле-признак: строки с пустым значением задают новое значение, с заполненным — получают его (например, barcode)'],
        'into' => ['type' => 'string', 'desc' => 'Куда записать результат (по умолчанию — в исходное поле)'],
        'source_into' => ['type' => 'string', 'desc' => 'Необязательное поле, куда сохранить исходное значение строки (для контроля)'],
        'overwrite' => ['type' => 'bool', 'default' => true, 'desc' => 'Перезаписывать значение в строках-получателях, даже если оно там уже есть'],
        'drop_unassigned' => ['type' => 'bool', 'default' => false, 'desc' => 'Убрать строки, для которых значение так и не было задано'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $field = (string) $p['field'];
        $marker = (string) $p['marker'];
        $into = (string) ($p['into'] ?? $field);
        $sourceInto = (string) ($p['source_into'] ?? '');
        $overwrite = (bool) ($p['overwrite'] ?? true);
        $dropUnassigned = (bool) ($p['drop_unassigned'] ?? false);

        $current = null;
        $assigned = 0;
        $result = [];

        foreach ($ctx->rows as $row) {
            $markerValue = Excel::text($row[$marker] ?? null);
            $ownValue = $row[$field] ?? null;

            if ($sourceInto !== '') {
                $row[$sourceInto] = $ownValue;
            }

            if (Excel::isEmpty($markerValue)) {
                // Строка-заголовок: задаёт текущее значение
                if (!Excel::isEmpty($ownValue)) {
                    $current = $ownValue;
                }
                if ($dropUnassigned) {
                    continue;
                }
            } else {
                // Строка-потребитель: получает значение из строки-заголовка выше
                if ($current !== null && ($overwrite || Excel::isEmpty($ownValue))) {
                    $row[$into] = $current;
                    $assigned++;
                } elseif ($current === null && $dropUnassigned) {
                    continue;
                }
            }

            $result[] = $row;
        }

        $ctx->rows = $result;
        $ctx->increment('строк с перенесённым значением', $assigned);
        $ctx->info("Перенесено значений поля «{$field}»: {$assigned}");
    },
];
