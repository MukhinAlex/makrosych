<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Реестр операций.
 *
 * Каждая операция — отдельный файл в Engine/Ops/, возвращающий массив с описанием
 * и обработчиком. Новые операции подхватываются автоматически: достаточно положить файл.
 */
final class Ops
{
    private static ?array $registry = null;

    /** @return array<string, array<string, mixed>> */
    public static function registry(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        $registry = [];
        foreach (glob(__DIR__ . '/Ops/*.php') ?: [] as $file) {
            $entry = require $file;
            if (is_array($entry) && isset($entry['op'], $entry['handler']) && is_callable($entry['handler'])) {
                $registry[$entry['op']] = $entry;
            }
        }

        ksort($registry);
        self::$registry = $registry;

        return $registry;
    }

    public static function exists(string $op): bool
    {
        return isset(self::registry()[$op]);
    }

    public static function meta(string $op): array
    {
        return self::registry()[$op] ?? [];
    }

    public static function run(string $op, Context $context, array $params): void
    {
        $entry = self::registry()[$op] ?? null;
        if ($entry === null) {
            throw new \RuntimeException("Неизвестная операция: {$op}");
        }

        $handler = $entry['handler'];
        $handler($context, $params);
    }

    /** Список операций для интерфейса и подсказки нейросети. */
    public static function catalog(): array
    {
        $catalog = [];
        foreach (self::registry() as $name => $entry) {
            $params = [];
            foreach (($entry['params'] ?? []) as $param => $spec) {
                $params[$param] = [
                    'type' => $spec['type'] ?? 'mixed',
                    'required' => (bool) ($spec['required'] ?? false),
                    'default' => $spec['default'] ?? null,
                    'desc' => $spec['desc'] ?? '',
                ];
            }

            $catalog[$name] = [
                'op' => $name,
                'title' => $entry['title'] ?? $name,
                'description' => $entry['description'] ?? '',
                'network' => (bool) ($entry['network'] ?? false),
                'ai' => (bool) ($entry['ai'] ?? false),
                'params' => $params,
            ];
        }

        return $catalog;
    }

    /**
     * Проверка параметров операции без выполнения.
     *
     * @return string[] Список проблем.
     */
    public static function validateParams(string $op, array $params): array
    {
        $entry = self::registry()[$op] ?? null;
        if ($entry === null) {
            return ["Неизвестная операция: {$op}"];
        }

        $errors = [];
        foreach (($entry['params'] ?? []) as $name => $spec) {
            $required = (bool) ($spec['required'] ?? false);
            if (!$required) {
                continue;
            }

            $missing = !array_key_exists($name, $params)
                || $params[$name] === null
                || $params[$name] === ''
                || $params[$name] === [];

            if ($missing) {
                $errors[] = "Операция {$op}: не задан обязательный параметр «{$name}»";
            }
        }

        return $errors;
    }

    /**
     * Текстовая подсказка по операциям для нейросети.
     *
     * Формат намеренно сжатый: локальные модели плохо переносят длинные запросы.
     */
    public static function describeForPrompt(): string
    {
        $lines = [];

        foreach (self::catalog() as $name => $meta) {
            $marks = [];
            if ($meta['network']) {
                $marks[] = 'сеть';
            }
            if ($meta['ai']) {
                $marks[] = 'нейросеть';
            }
            $marksText = $marks === [] ? '' : ' [' . implode(', ', $marks) . ']';

            $lines[] = "### {$name}{$marksText} — {$meta['title']}";
            $lines[] = mb_substr((string) $meta['description'], 0, 220);

            $params = [];
            foreach ($meta['params'] as $param => $spec) {
                $signature = $param . ($spec['required'] ? '*' : '') . ' (' . $spec['type'];
                if ($spec['default'] !== null) {
                    $signature .= '=' . json_encode($spec['default'], JSON_UNESCAPED_UNICODE);
                }
                $signature .= ')';
                $params[] = $signature;
            }
            $lines[] = 'параметры: ' . implode(', ', $params);

            // Описания только для обязательных параметров — остальные понятны по имени
            foreach ($meta['params'] as $param => $spec) {
                if ($spec['required'] && $spec['desc'] !== '') {
                    $lines[] = "  {$param}* — " . mb_substr((string) $spec['desc'], 0, 140);
                }
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
