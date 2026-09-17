<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Преобразования значений: единицы измерения, округление, очистка текста.
 *
 * Правила задаются данными, а не кодом, поэтому сценарий остаётся безопасным
 * и полностью детерминированным.
 */
final class Transform
{
    /**
     * Применяет набор правил к значению.
     *
     * @param array<string, mixed> $rules
     */
    public static function apply(mixed $value, array $rules): mixed
    {
        if ($rules === []) {
            return $value;
        }

        if (isset($rules['default']) && Excel::isEmpty($value)) {
            $value = $rules['default'];
        }

        foreach ($rules as $rule => $argument) {
            $value = match ($rule) {
                'default' => $value,
                'multiply' => self::numeric($value) * (float) $argument,
                'divide' => (float) $argument === 0.0 ? $value : self::numeric($value) / (float) $argument,
                'add' => self::numeric($value) + (float) $argument,
                'subtract' => self::numeric($value) - (float) $argument,
                'round' => round(self::numeric($value), (int) $argument),
                'floor' => floor(self::numeric($value)),
                'ceil' => ceil(self::numeric($value)),
                'to_int' => (int) round(self::numeric($value)),
                'to_float' => self::numeric($value),
                'trim' => trim((string) $value),
                'upper' => mb_strtoupper((string) $value),
                'lower' => mb_strtolower((string) $value),
                'ucfirst' => mb_strtoupper(mb_substr((string) $value, 0, 1)) . mb_substr((string) $value, 1),
                'strip_chars' => trim((string) $value, (string) $argument),
                'prefix' => (string) $argument . $value,
                'suffix' => $value . (string) $argument,
                'replace' => self::replace($value, $argument),
                'regex_replace' => self::regexReplace($value, $argument),
                'number_format' => self::numberFormat($value, $argument),
                'pad_left' => str_pad((string) $value, (int) $argument, '0', STR_PAD_LEFT),
                default => $value,
            };
        }

        return $value;
    }

    public static function numeric(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = str_replace(["\xC2\xA0", ' '], '', (string) $value);
        $text = str_replace(',', '.', $text);

        return is_numeric($text) ? (float) $text : 0.0;
    }

    public static function isNumeric(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        $text = str_replace(["\xC2\xA0", ' '], '', (string) $value);

        return $text !== '' && is_numeric(str_replace(',', '.', $text));
    }

    /**
     * @param mixed $argument [['from' => 'м', 'to' => '']] или ['from' => 'to']
     */
    private static function replace(mixed $value, mixed $argument): string
    {
        $text = (string) $value;
        if (!is_array($argument)) {
            return $text;
        }

        if (isset($argument['from'])) {
            return str_replace(
                self::unescape((string) $argument['from']),
                self::unescape((string) ($argument['to'] ?? '')),
                $text
            );
        }

        foreach ($argument as $from => $to) {
            if (is_array($to) && isset($to['from'])) {
                $text = str_replace(
                    self::unescape((string) $to['from']),
                    self::unescape((string) ($to['to'] ?? '')),
                    $text
                );
                continue;
            }
            $text = str_replace(self::unescape((string) $from), self::unescape((string) $to), $text);
        }

        return $text;
    }

    private static function regexReplace(mixed $value, mixed $argument): string
    {
        $pattern = is_array($argument) ? (string) ($argument['pattern'] ?? '') : (string) $argument;
        $replacement = is_array($argument) ? self::unescape((string) ($argument['replacement'] ?? '')) : '';

        if ($pattern === '') {
            return (string) $value;
        }

        $result = @preg_replace($pattern, $replacement, (string) $value);

        return $result ?? (string) $value;
    }

    /**
     * Превращает записанные в виде текста управляющие последовательности
     * в настоящие символы.
     *
     * Модели и пользователи часто указывают перенос строки как два символа
     * «обратный слэш + n» вместо настоящего перевода строки. В таблицах такие
     * сочетания смысла не несут, поэтому заменяем их на реальные символы.
     */
    public static function unescape(string $value): string
    {
        if (!str_contains($value, '\\')) {
            return $value;
        }

        return str_replace(
            ['\\r\\n', '\\n', '\\r', '\\t'],
            ["\r\n", "\n", "\r", "\t"],
            $value
        );
    }

    private static function numberFormat(mixed $value, mixed $argument): string
    {
        $decimals = 0;
        $decimalPoint = ',';
        $thousands = ' ';

        if (is_array($argument)) {
            $decimals = (int) ($argument['decimals'] ?? 0);
            $decimalPoint = (string) ($argument['decimal_point'] ?? ',');
            $thousands = (string) ($argument['thousands_sep'] ?? ' ');
        } else {
            $decimals = (int) $argument;
        }

        return number_format(self::numeric($value), $decimals, $decimalPoint, $thousands);
    }

    /** Список поддерживаемых правил — используется в подсказке для нейросети. */
    public static function catalog(): array
    {
        return [
            'multiply' => 'умножить на число (например, 1000 для кг → г)',
            'divide' => 'разделить на число',
            'add' => 'прибавить число',
            'subtract' => 'вычесть число',
            'round' => 'округлить до N знаков',
            'floor' => 'округлить вниз',
            'ceil' => 'округлить вверх',
            'to_int' => 'преобразовать в целое',
            'to_float' => 'преобразовать в число',
            'trim' => 'убрать пробелы по краям',
            'upper' => 'в верхний регистр',
            'lower' => 'в нижний регистр',
            'ucfirst' => 'первая буква заглавная',
            'strip_chars' => 'убрать указанные символы по краям',
            'prefix' => 'добавить префикс',
            'suffix' => 'добавить суффикс',
            'replace' => 'заменить подстроку: {"from": "…", "to": "…"}',
            'regex_replace' => 'замена по регулярному выражению: {"pattern": "…", "replacement": "…"}',
            'number_format' => 'формат числа: {"decimals": 0, "decimal_point": ",", "thousands_sep": " "}',
            'pad_left' => 'дополнить нулями слева до N символов',
            'default' => 'значение, если исходное пустое',
        ];
    }
}
