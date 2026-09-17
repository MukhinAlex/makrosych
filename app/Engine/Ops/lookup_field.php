<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Excel;
use App\Engine\Transform;

return [
    'op' => 'lookup_field',
    'title' => 'Подстановка данных из другого файла (ВПР)',
    'description' => 'Находит в справочном файле строку по ключу и дописывает значения из её колонок в текущие строки — аналог ВПР (VLOOKUP). Например: подтянуть цены и наименования из прайса по артикулу. Если ключ встречается в справочнике несколько раз, берётся первая строка, все значения или их склейка.',
    'network' => false,
    'ai' => false,
    'params' => [
        'file' => ['type' => 'string', 'required' => true, 'desc' => 'Псевдоним файла-справочника, например lookup'],
        'key_column' => ['type' => 'string', 'required' => true, 'desc' => 'Колонка справочника с ключом, например "A"'],
        'columns' => ['type' => 'object', 'required' => true, 'desc' => 'Что взять из справочника: поле результата => колонка, например {"price": "C", "name": "D"}'],
        'sheet' => ['type' => 'int|string', 'default' => 1, 'desc' => 'Лист справочника: номер (с 1) или имя'],
        'header_rows' => ['type' => 'int[]', 'default' => [1], 'desc' => 'Строки заголовков справочника'],
        'data_from_row' => ['type' => 'int', 'desc' => 'Первая строка данных справочника (по умолчанию — следующая после заголовка)'],
        'key_field' => ['type' => 'string', 'default' => 'code', 'desc' => 'Поле текущих строк для сопоставления (по умолчанию code)'],
        'multi' => ['type' => 'string', 'default' => 'first', 'desc' => 'Несколько строк с одним ключом: first — первая, list — список, concat — склейка через separator'],
        'separator' => ['type' => 'string', 'default' => ';', 'desc' => 'Разделитель для multi=concat'],
        'transform' => ['type' => 'object', 'desc' => 'Поле результата => правила преобразования, например {"price": {"multiply": 1, "round": 2}}'],
        'defaults' => ['type' => 'object', 'desc' => 'Что записать, если ключ не найден: {"price": "нет в прайсе"}'],
        'fill_empty_only' => ['type' => 'bool', 'default' => false, 'desc' => 'Заполнять только пустые поля текущих строк'],
        'ignore_case' => ['type' => 'bool', 'default' => true, 'desc' => 'Сравнивать ключи без учёта регистра и лишних пробелов'],
        'missing' => ['type' => 'string', 'default' => 'skip', 'desc' => 'Если ключ не найден: skip — оставить пустым, error — остановить'],
        'report_missing' => ['type' => 'bool', 'default' => true, 'desc' => 'Предупреждать в журнале о не найденных ключах'],
        'limit' => ['type' => 'int', 'desc' => 'Ограничить число строк справочника'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $path = $ctx->inputPath((string) $p['file']);
        $spreadsheet = Excel::load($path);
        $sheet = Excel::sheet($spreadsheet, $p['sheet'] ?? 1);

        $keyColumn = strtoupper(trim((string) $p['key_column']));
        if ($keyColumn === '') {
            throw new \RuntimeException('Не задана колонка справочника с ключом (key_column)');
        }

        $columns = (array) ($p['columns'] ?? []);
        if ($columns === []) {
            throw new \RuntimeException('Не задано, какие колонки справочника подставлять (columns)');
        }

        $headerRows = array_map('intval', (array) ($p['header_rows'] ?? [1]));
        $headerRow = $headerRows[0] ?? 1;
        $dataFrom = (int) ($p['data_from_row'] ?? ($headerRow + 1));
        $keyField = (string) ($p['key_field'] ?? 'code');
        $multi = strtolower((string) ($p['multi'] ?? 'first'));
        $separator = (string) ($p['separator'] ?? ';');
        $transforms = (array) ($p['transform'] ?? []);
        $defaults = (array) ($p['defaults'] ?? []);
        $fillEmptyOnly = (bool) ($p['fill_empty_only'] ?? false);
        $ignoreCase = (bool) ($p['ignore_case'] ?? true);
        $missing = (string) ($p['missing'] ?? 'skip');
        $reportMissing = (bool) ($p['report_missing'] ?? true);
        $limit = (int) ($p['limit'] ?? 0);

        if ($keyField === '') {
            throw new \RuntimeException('Не задано поле текущих строк для сопоставления (key_field)');
        }

        $lastRow = $sheet->getHighestDataRow();
        if ($lastRow < $dataFrom) {
            $ctx->warn("Лист «{$sheet->getTitle()}» справочника не содержит данных с строки {$dataFrom}");

            return;
        }

        // Индекс справочника: ключ => список записей (для multi=first хранится только первая)
        $index = [];
        $duplicates = 0;
        $read = 0;

        for ($row = $dataFrom; $row <= $lastRow; $row++) {
            if ($limit > 0 && $read >= $limit) {
                break;
            }
            $read++;

            $key = op_lookup_key($sheet->getCell($keyColumn . $row)->getValue(), $ignoreCase);
            if ($key === '') {
                continue;
            }

            $record = [];
            foreach ($columns as $field => $columnSpec) {
                $values = [];
                foreach (Excel::columns($columnSpec) as $letter) {
                    $values[] = Excel::text($sheet->getCell($letter . $row)->getValue());
                }

                $record[(string) $field] = count($values) === 1 ? $values[0] : $values;
            }

            if (isset($index[$key])) {
                $duplicates++;
                if ($multi !== 'first') {
                    $index[$key][] = $record;
                }
            } else {
                $index[$key] = [$record];
            }
        }

        $matched = 0;
        $filled = 0;
        $notFound = [];

        foreach ($ctx->rows as $position => $row) {
            $raw = $row[$keyField] ?? null;
            $key = op_lookup_key($raw, $ignoreCase);

            if ($key === '' || !isset($index[$key])) {
                if ($key !== '') {
                    $notFound[] = Excel::text($raw);
                    if ($missing === 'error') {
                        throw new \RuntimeException("В справочнике не найдена строка для ключа «" . Excel::text($raw) . '»');
                    }
                }

                foreach (array_keys($columns) as $field) {
                    $field = (string) $field;
                    if (!array_key_exists($field, $defaults)) {
                        continue;
                    }
                    if ($fillEmptyOnly && !Excel::isEmpty($ctx->rows[$position][$field] ?? null)) {
                        continue;
                    }
                    $ctx->rows[$position][$field] = $defaults[$field];
                    $filled++;
                }

                continue;
            }

            $matched++;
            foreach (array_keys($columns) as $field) {
                $field = (string) $field;
                $value = op_lookup_value($index[$key], $field, $multi, $separator);

                if (isset($transforms[$field])) {
                    $value = Transform::apply($value, (array) $transforms[$field]);
                }

                if ($fillEmptyOnly && !Excel::isEmpty($ctx->rows[$position][$field] ?? null)) {
                    continue;
                }

                $ctx->rows[$position][$field] = $value;
                $filled++;
            }
        }

        $ctx->increment('подставлено значений', $filled);
        $ctx->increment('строк найдено в справочнике', $matched);
        $ctx->increment('не найдено ключей', count($notFound));

        if ($duplicates > 0 && $multi === 'first') {
            $ctx->warn("В справочнике {$duplicates} строк с повторяющимся ключом — взято первое совпадение");
        }

        if ($reportMissing && $notFound !== []) {
            $examples = array_slice(array_values(array_unique($notFound)), 0, 5);
            $ctx->warn(
                'В справочнике нет ключей: ' . implode(', ', $examples)
                . (count($notFound) > count($examples) ? ' и другие' : '')
                . ' (всего ' . count($notFound) . ')'
            );
        }

        $ctx->info(
            'Справочник «' . basename($path) . '»: ключей — ' . count($index)
            . ', сопоставлено строк — ' . $matched
            . ($filled > 0 ? ", подставлено значений — {$filled}" : '')
            . ($notFound !== [] ? ', без пары — ' . count($notFound) : '')
        );

        $spreadsheet->disconnectWorksheets();
    },
];

/** Ключ сопоставления: без лишних пробелов и, при необходимости, без учёта регистра. */
function op_lookup_key(mixed $value, bool $ignoreCase): string
{
    $key = Excel::text($value);
    if ($key === '') {
        return '';
    }

    $key = str_replace("\xC2\xA0", ' ', $key);
    $key = (string) preg_replace('~\s+~u', ' ', $key);
    $key = trim($key);

    return $ignoreCase ? mb_strtolower($key) : $key;
}

/**
 * Значение поля из найденных записей справочника.
 *
 * @param array<int, array<string, mixed>> $records
 */
function op_lookup_value(array $records, string $field, string $multi, string $separator): mixed
{
    $values = [];
    foreach ($records as $record) {
        $value = $record[$field] ?? '';
        if (is_array($value)) {
            foreach ($value as $item) {
                $values[] = $item;
            }
            continue;
        }
        $values[] = $value;
    }

    if ($multi === 'list') {
        return count($values) === 1 ? $values[0] : $values;
    }

    if ($multi === 'concat') {
        $values = array_values(array_filter($values, static fn ($value) => Excel::text($value) !== ''));

        return implode($separator, array_map(static fn ($value) => Excel::text($value), $values));
    }

    return $values[0] ?? '';
}
