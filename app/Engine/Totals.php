<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Подсчёт итогов по набору строк: сумма, среднее, минимум, максимум, количество,
 * число различных значений, первое/последнее значение и склейка через разделитель.
 *
 * Логика вынесена отдельно, потому что её используют две операции: aggregate
 * (одна строка на группу) и subtotals (строки данных плюс строка «Итог» после группы).
 */
final class Totals
{
    public const AGGREGATES = ['sum', 'avg', 'min', 'max', 'count', 'unique_count', 'first', 'last', 'join'];

    /** Русские и привычные названия видов итогов. */
    private const ALIASES = [
        'сумма' => 'sum', 'total' => 'sum', 'итого' => 'sum',
        'среднее' => 'avg', 'average' => 'avg', 'mean' => 'avg',
        'минимум' => 'min', 'максимум' => 'max',
        'количество' => 'count', 'кол-во' => 'count',
        'различных' => 'unique_count', 'count_distinct' => 'unique_count',
        'первое' => 'first', 'последнее' => 'last',
        'склейка' => 'join', 'список' => 'join', 'list' => 'join',
    ];

    /**
     * Разбор правила итога.
     *
     * Правило — имя поля (тогда считается сумма) или объект
     * {"field": "storage_sum", "agg": "sum", "round": 2}.
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>} поле, вид итога, правило
     */
    public static function rule(mixed $spec): array
    {
        if (is_string($spec)) {
            return [$spec, 'sum', []];
        }

        if (!is_array($spec)) {
            throw new \RuntimeException('Правило итога задаётся именем поля или объектом с полями field и agg');
        }

        $field = (string) ($spec['field'] ?? '');
        $agg = mb_strtolower(trim((string) ($spec['agg'] ?? 'sum')));
        $agg = self::ALIASES[$agg] ?? $agg;

        if (!in_array($agg, self::AGGREGATES, true)) {
            throw new \RuntimeException(
                "Неизвестный вид итога: {$agg}. Допустимые: " . implode(', ', self::AGGREGATES)
            );
        }

        return [$field, $agg, $spec];
    }

    /**
     * Итоги по строкам группы.
     *
     * @param array<int, mixed> $items строки группы
     * @param array<string, mixed> $map результат => правило
     * @param array<string, mixed> $options separator, skip_empty, sort, round
     * @return array{values: array<string, mixed>, computed: int, skipped: int, sample: string}
     */
    public static function compute(array $items, array $map, array $options = []): array
    {
        $separator = (string) ($options['separator'] ?? ';');
        $skipEmpty = (bool) ($options['skip_empty'] ?? true);
        $defaultSort = (bool) ($options['sort'] ?? false);
        $defaultRound = isset($options['round']) && $options['round'] !== null && $options['round'] !== ''
            ? (int) $options['round']
            : null;

        $values = [];
        $computed = 0;
        $skipped = 0;
        $sample = '';

        foreach ($map as $target => $spec) {
            [$field, $agg, $rule] = self::rule($spec);

            // Поле в правиле можно не указывать: тогда берётся поле с именем результата.
            // Так записывают подытоги, где значение остаётся в своей же колонке:
            // {"storage_sum": {"agg": "sum"}} — сумма колонки storage_sum.
            // Для count и unique_count пустое поле означает «считать строки».
            if ($field === '' && in_array($agg, ['sum', 'avg', 'min', 'max', 'first', 'last', 'join'], true)) {
                $field = (string) $target;
            }

            $collected = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                if ($field === '') {
                    $collected[] = $item;
                    continue;
                }

                $value = $item[$field] ?? null;
                if (isset($rule['transform'])) {
                    $value = Transform::apply($value, (array) $rule['transform']);
                }
                if ($skipEmpty && Excel::isEmpty($value)) {
                    continue;
                }

                $collected[] = $value;
            }

            if (in_array($agg, ['sum', 'avg', 'min', 'max'], true)) {
                $numbers = [];
                foreach ($collected as $value) {
                    if (Transform::isNumeric($value)) {
                        $numbers[] = Transform::numeric($value);
                        continue;
                    }

                    $skipped++;
                    if ($sample === '') {
                        $sample = Excel::text($value);
                    }
                }

                if ($numbers === []) {
                    $value = $rule['empty'] ?? '';
                } else {
                    $value = match ($agg) {
                        'sum' => array_sum($numbers),
                        'avg' => array_sum($numbers) / count($numbers),
                        'min' => min($numbers),
                        'max' => max($numbers),
                    };

                    $round = $rule['round'] ?? $defaultRound;
                    if ($round !== null) {
                        $value = round((float) $value, (int) $round);
                    }
                }
            } elseif ($agg === 'count') {
                $value = count($collected);
            } elseif ($agg === 'unique_count') {
                $unique = [];
                foreach ($collected as $item) {
                    $text = Excel::text($item);
                    if ($text !== '') {
                        $unique[$text] = true;
                    }
                }
                $value = count($unique);
            } elseif ($agg === 'first' || $agg === 'last') {
                $item = $agg === 'first' ? ($collected[0] ?? null) : ($collected[count($collected) - 1] ?? null);
                $value = $item === null ? '' : Excel::text($item);
            } else {
                $texts = array_map(static fn ($item): string => Excel::text($item), $collected);
                if ((bool) ($rule['unique'] ?? false)) {
                    $texts = array_values(array_unique($texts));
                }
                if ((bool) ($rule['sort'] ?? $defaultSort)) {
                    sort($texts, SORT_NATURAL | SORT_FLAG_CASE);
                }
                $value = implode((string) ($rule['separator'] ?? $separator), $texts);
            }

            if ($value !== '' && $value !== []) {
                $computed++;
            }

            $values[(string) $target] = $value;
        }

        return ['values' => $values, 'computed' => $computed, 'skipped' => $skipped, 'sample' => $sample];
    }
}
