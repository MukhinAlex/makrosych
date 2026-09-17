<?php

declare(strict_types=1);

use App\Engine\Context;

return [
    'op' => 'split_multi',
    'title' => 'Разбор значений с разделителем',
    'description' => 'Разбивает значение поля по разделителю. В режиме expand=true одна строка превращается в несколько — по одной на каждое значение (например, ссылки из ячейки «ссылка1;ссылка2»). В режиме expand=false значение становится списком внутри одной строки.',
    'network' => false,
    'ai' => false,
    'params' => [
        'field' => ['type' => 'string', 'required' => true, 'desc' => 'Поле, значение которого нужно разбить'],
        'separator' => ['type' => 'string', 'default' => ';', 'desc' => 'Разделитель. Значения newline и \\n означают перенос строки'],
        'into' => ['type' => 'string', 'desc' => 'Имя поля для результата (по умолчанию совпадает с исходным)'],
        'expand' => ['type' => 'bool', 'default' => true, 'desc' => 'Размножить строку на каждое значение'],
        'trim' => ['type' => 'bool', 'default' => true, 'desc' => 'Убирать пробелы по краям'],
        'strip_chars' => ['type' => 'string', 'default' => "\"'", 'desc' => 'Символы, убираемые по краям каждого значения'],
        'drop_empty' => ['type' => 'bool', 'default' => true, 'desc' => 'Пропускать пустые значения'],
        'unique' => ['type' => 'bool', 'default' => false, 'desc' => 'Убрать повторы внутри строки'],
        'max' => ['type' => 'int', 'default' => 0, 'desc' => 'Ограничение числа значений на строку (0 — без ограничения)'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $field = (string) $p['field'];
        $into = (string) ($p['into'] ?? $field);
        $separator = (string) ($p['separator'] ?? ';');
        if ($separator === 'newline' || $separator === '\\n') {
            $separator = "\n";
        }

        $expand = (bool) ($p['expand'] ?? true);
        $trim = (bool) ($p['trim'] ?? true);
        $stripChars = (string) ($p['strip_chars'] ?? "\"'");
        $dropEmpty = (bool) ($p['drop_empty'] ?? true);
        $unique = (bool) ($p['unique'] ?? false);
        $max = (int) ($p['max'] ?? 0);

        $result = [];
        $produced = 0;

        foreach ($ctx->rows as $row) {
            $value = $row[$field] ?? '';

            if (is_array($value)) {
                $parts = $value;
            } else {
                $parts = explode($separator, (string) $value);
            }

            $clean = [];
            foreach ($parts as $part) {
                $part = (string) $part;
                if ($trim) {
                    $part = trim($part);
                }
                if ($stripChars !== '') {
                    $part = trim($part, $stripChars);
                }
                if ($dropEmpty && $part === '') {
                    continue;
                }
                $clean[] = $part;
            }

            if ($unique) {
                $clean = array_values(array_unique($clean));
            }

            if ($max > 0) {
                $clean = array_slice($clean, 0, $max);
            }

            if (!$expand) {
                $row[$into] = $clean;
                $result[] = $row;
                $produced += count($clean);
                continue;
            }

            foreach ($clean as $part) {
                $copy = $row;
                $copy[$into] = $part;
                $result[] = $copy;
                $produced++;
            }
        }

        $ctx->rows = $result;
        $ctx->increment('значений после разбора', $produced);
        $ctx->info("Разобрано значений: {$produced}");
    },
];
