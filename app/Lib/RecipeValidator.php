<?php

declare(strict_types=1);

namespace App\Lib;

use App\Engine\Ops;

/**
 * Проверка сценария перед запуском и сохранением.
 */
final class RecipeValidator
{
    public const SCHEMA = 'excelmaster/recipe/1';

    /** Операции, создающие результат. */
    private const OUTPUT_OPS = ['write_cells', 'write_new_sheet', 'insert_images', 'zip_output', 'report_xlsx', 'download_files', 'convert_images'];

    /**
     * @return array{ok: bool, errors: string[], warnings: string[], uses_ai: bool, uses_network: bool, ops: string[]}
     */
    public static function validate(array $recipe): array
    {
        $recipe = self::normalize($recipe);

        $errors = [];
        $warnings = [];
        $usesAi = false;
        $usesNetwork = false;
        $ops = [];

        if (($recipe['schema'] ?? '') !== self::SCHEMA) {
            $warnings[] = 'Не указана схема сценария ' . self::SCHEMA;
        }

        if (trim((string) ($recipe['name'] ?? '')) === '') {
            $errors[] = 'Не задано имя сценария';
        }

        $steps = $recipe['steps'] ?? null;
        if (!is_array($steps) || $steps === []) {
            $errors[] = 'Сценарий не содержит ни одного шага';

            return [
                'ok' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'uses_ai' => false,
                'uses_network' => false,
                'ops' => [],
            ];
        }

        foreach ($steps as $index => $step) {
            $number = $index + 1;
            if (!is_array($step)) {
                $errors[] = "Шаг {$number}: должен быть объектом";
                continue;
            }

            $op = (string) ($step['op'] ?? '');
            if ($op === '') {
                $errors[] = "Шаг {$number}: не указана операция (op)";
                continue;
            }

            if (!Ops::exists($op)) {
                $errors[] = "Шаг {$number}: неизвестная операция «{$op}»";
                continue;
            }

            $ops[] = $op;
            $meta = Ops::meta($op);
            if (($meta['ai'] ?? false) === true) {
                $usesAi = true;
            }
            if (($meta['network'] ?? false) === true) {
                $usesNetwork = true;
            }

            $params = $step;
            unset($params['op']);
            foreach (Ops::validateParams($op, $params) as $error) {
                $errors[] = "Шаг {$number}: {$error}";
            }

            $known = (array) ($meta['params'] ?? []);
            foreach (array_keys($params) as $key) {
                if (!isset($known[$key])) {
                    $warnings[] = "Шаг {$number} ({$op}): неизвестный параметр «{$key}»";
                }
            }
        }

        if ($ops !== [] && array_intersect($ops, self::OUTPUT_OPS) === []) {
            $warnings[] = 'Сценарий не создаёт ни одного файла результата';
        }

        // Входные файлы: если они объявлены, шаг не может взять файл «из ниоткуда»
        $declared = self::declaredAliases($recipe);
        if ($declared !== []) {
            foreach (self::fileAliases($recipe) as $alias) {
                if (!in_array($alias, $declared, true)) {
                    $errors[] = "Сценарий использует файл «{$alias}», но он не объявлен во входах";
                }
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'uses_ai' => $usesAi,
            'uses_network' => $usesNetwork,
            'ops' => $ops,
        ];
    }

    /**
     * Псевдонимы входных файлов, которые использует сценарий.
     *
     * @param array<string, mixed> $recipe
     * @return array<int, string>
     */
    public static function fileAliases(array $recipe): array
    {
        $aliases = [];
        foreach ((array) ($recipe['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }

            foreach (['file', 'template'] as $key) {
                $value = $step[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $aliases[$value] = true;
                }
            }
        }

        return array_keys($aliases);
    }

    /**
     * Псевдонимы, объявленные во входах сценария.
     *
     * @param array<string, mixed> $recipe
     * @return array<int, string>
     */
    public static function declaredAliases(array $recipe): array
    {
        $aliases = [];
        foreach ((array) ($recipe['inputs'] ?? []) as $item) {
            if (is_array($item) && isset($item['alias'])) {
                $aliases[] = (string) $item['alias'];
            }
        }

        return $aliases;
    }

    /**
     * Приведение сценария к рабочему виду.
     *
     * Модели часто вкладывают параметры шага в объект "params" — переносим их
     * на верхний уровень, чтобы сценарий не отвергался из-за формы записи.
     *
     * @param array<string, mixed> $recipe
     * @return array<string, mixed>
     */
    public static function normalize(array $recipe): array
    {
        $steps = [];

        foreach ((array) ($recipe['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                $steps[] = $step;
                continue;
            }

            if (isset($step['params']) && is_array($step['params'])) {
                $nested = $step['params'];
                unset($step['params']);
                $step = array_merge($step, $nested);
            }

            $steps[] = $step;
        }

        $recipe['steps'] = $steps;

        return $recipe;
    }

    /** Проверка параметров сценария, задаваемых пользователем. */
    public static function resolveParams(array $recipe, array $overrides = []): array
    {
        $resolved = [];
        foreach ((array) ($recipe['params'] ?? []) as $param) {
            if (!is_array($param)) {
                continue;
            }
            $name = (string) ($param['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $value = $overrides[$name] ?? ($param['default'] ?? null);
            $resolved[$name] = self::cast($value, (string) ($param['type'] ?? 'string'));
        }

        foreach ($overrides as $name => $value) {
            if (!array_key_exists($name, $resolved)) {
                $resolved[$name] = $value;
            }
        }

        return $resolved;
    }

    private static function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'number', 'int' => is_numeric($value) ? (int) $value : $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            'bool' => is_bool($value) ? $value : in_array($value, ['1', 1, 'true', true, 'да'], true),
            default => $value,
        };
    }
}
