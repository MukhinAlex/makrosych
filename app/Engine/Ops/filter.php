<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Excel;
use App\Engine\Transform;

return [
    'op' => 'filter',
    'title' => 'Отбор строк',
    'description' => 'Оставляет только строки, удовлетворяющие условиям. Условия задаются данными (без исполнения кода): поле, операция сравнения и значение. Режим all — должны выполняться все условия, any — достаточно одного.',
    'network' => false,
    'ai' => false,
    'params' => [
        'conditions' => ['type' => 'object[]', 'required' => true, 'desc' => 'Список условий: [{"field": "article", "op": "not_empty"}, {"field": "link", "op": "contains", "value": "http"}]'],
        'mode' => ['type' => 'string', 'default' => 'all', 'desc' => 'all — все условия, any — любое из них'],
        'negate' => ['type' => 'bool', 'default' => false, 'desc' => 'Инвертировать результат отбора'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $conditions = (array) ($p['conditions'] ?? []);
        if ($conditions === []) {
            throw new \RuntimeException('Не задано ни одного условия отбора');
        }

        $mode = (string) ($p['mode'] ?? 'all');
        $negate = (bool) ($p['negate'] ?? false);

        $kept = [];
        $removed = 0;

        foreach ($ctx->rows as $row) {
            $matches = [];
            foreach ($conditions as $condition) {
                $matches[] = op_filter_matches($row, (array) $condition);
            }

            $pass = $mode === 'any' ? in_array(true, $matches, true) : !in_array(false, $matches, true);
            if ($negate) {
                $pass = !$pass;
            }

            if ($pass) {
                $kept[] = $row;
            } else {
                $removed++;
            }
        }

        $ctx->rows = $kept;
        $ctx->increment('отобрано строк', count($kept));
        $ctx->info('После отбора строк: ' . count($kept) . ($removed > 0 ? ", отброшено: {$removed}" : ''));
    },
];

function op_filter_matches(array $row, array $condition): bool
{
    $field = (string) ($condition['field'] ?? '');
    $op = (string) ($condition['op'] ?? 'not_empty');
    $value = $condition['value'] ?? null;

    if (array_key_exists('field2', $condition)) {
        $value = $row[(string) $condition['field2']] ?? null;
    }

    $actual = $row[$field] ?? null;

    if (is_array($actual)) {
        $actual = implode(';', array_map('strval', $actual));
    }

    $actualText = Excel::text($actual);
    $expectedText = Excel::text($value);

    return match ($op) {
        'empty' => Excel::isEmpty($actual),
        'not_empty' => !Excel::isEmpty($actual),
        '==' , 'equals' => op_filter_compare($actual, $value) === 0,
        '!=' , 'not_equals' => op_filter_compare($actual, $value) !== 0,
        '>' => op_filter_compare($actual, $value) > 0,
        '<' => op_filter_compare($actual, $value) < 0,
        '>=' => op_filter_compare($actual, $value) >= 0,
        '<=' => op_filter_compare($actual, $value) <= 0,
        'contains' => $expectedText !== '' && mb_stripos($actualText, $expectedText) !== false,
        'not_contains' => $expectedText === '' || mb_stripos($actualText, $expectedText) === false,
        'starts_with' => $expectedText !== '' && str_starts_with(mb_strtolower($actualText), mb_strtolower($expectedText)),
        'ends_with' => $expectedText !== '' && str_ends_with(mb_strtolower($actualText), mb_strtolower($expectedText)),
        'in' => in_array($actualText, array_map(static fn ($v) => Excel::text($v), (array) $value), true),
        'not_in' => !in_array($actualText, array_map(static fn ($v) => Excel::text($v), (array) $value), true),
        'regex' => op_filter_regex($actualText, $expectedText),
        'not_regex' => !op_filter_regex($actualText, $expectedText),
        default => true,
    };
}

function op_filter_compare(mixed $left, mixed $right): int
{
    if (Transform::isNumeric($left) && Transform::isNumeric($right)) {
        return Transform::numeric($left) <=> Transform::numeric($right);
    }

    return strcmp(mb_strtolower(Excel::text($left)), mb_strtolower(Excel::text($right)));
}

function op_filter_regex(string $subject, string $pattern): bool
{
    if ($pattern === '') {
        return false;
    }

    return (bool) @preg_match($pattern, $subject);
}
