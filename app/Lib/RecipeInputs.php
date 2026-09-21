<?php

declare(strict_types=1);

namespace App\Lib;

/**
 * Входные файлы сценария: сколько их и как они называются в окне запуска.
 *
 * Пользователь при создании задачи сам говорит, сколько файлов ему нужно
 * («один файл», «данные и справочник», «данные и шаблон»). Из этого выбора
 * и псевдонимов в шагах собирается список полей окна запуска.
 *
 * Файл, который берётся у другого файла (`same_as`), отдельного поля не получает:
 * иначе сценарий, читающий и записывающий одну и ту же таблицу, просил бы
 * приложить её дважды.
 */
final class RecipeInputs
{
    /** Сколько файлов бывает у задачи. */
    public const MODES = ['single', 'lookup', 'template'];

    public static function modeFrom(mixed $value): string
    {
        $mode = is_string($value) ? $value : '';

        return in_array($mode, self::MODES, true) ? $mode : 'single';
    }

    /** Второй файл, который подразумевает режим: у «одного файла» его нет. */
    public static function secondAlias(string $mode): ?string
    {
        return match (self::modeFrom($mode)) {
            'lookup' => 'lookup',
            'template' => 'template',
            default => null,
        };
    }

    /**
     * Описание входных файлов сценария для окна запуска.
     *
     * @param array<string, mixed> $recipe
     * @return array<int, array{alias: string, label: string, same_as?: string}>
     */
    public static function describe(array $recipe, string $mode): array
    {
        $mode = self::modeFrom($mode);
        $second = self::secondAlias($mode);
        $labels = (array) ($recipe['files'] ?? []);
        $result = [];

        foreach (self::order(RecipeValidator::fileAliases($recipe)) as $alias) {
            $entry = [
                'alias' => $alias,
                'label' => self::label($alias, $labels),
            ];

            if ($alias !== 'input' && $alias !== $second) {
                // Файла, о котором пользователь не говорил, у него нет: считаем,
                // что это та же таблица с данными
                $entry['same_as'] = 'input';
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Файлы, которые пользователь прикладывает сам (без same_as).
     *
     * @param array<int, array<string, mixed>> $inputs
     * @return array<int, array<string, mixed>>
     */
    public static function primary(array $inputs): array
    {
        return array_values(array_filter(
            $inputs,
            static fn ($item) => is_array($item) && !isset($item['same_as'])
        ));
    }

    /**
     * Псевдоним => псевдоним, у которого берётся файл.
     *
     * @param array<int, array<string, mixed>> $inputs
     * @return array<string, string>
     */
    public static function sameAs(array $inputs): array
    {
        $map = [];
        foreach ($inputs as $item) {
            if (is_array($item) && isset($item['alias'], $item['same_as'])) {
                $map[(string) $item['alias']] = (string) $item['same_as'];
            }
        }

        return $map;
    }

    /**
     * Порядок полей в окне запуска: таблица, шаблон, справочник, остальные по алфавиту.
     *
     * @param array<int, mixed> $aliases
     * @return array<int, string>
     */
    public static function order(array $aliases): array
    {
        $priority = ['input' => 0, 'template' => 1, 'lookup' => 2];
        $list = array_values(array_unique(array_map('strval', $aliases)));

        usort($list, static function (string $a, string $b) use ($priority): int {
            $pa = $priority[$a] ?? 3;
            $pb = $priority[$b] ?? 3;

            return $pa === $pb ? strcmp($a, $b) : $pa <=> $pb;
        });

        return $list;
    }

    /**
     * Подпись поля: сначала заданная сценарием ("files"), потом обычная.
     *
     * @param array<string, mixed> $labels
     */
    public static function label(string $alias, array $labels): string
    {
        $given = $labels[$alias] ?? null;
        if (is_string($given) && trim($given) !== '') {
            return trim($given);
        }

        return match ($alias) {
            'input' => 'Ваша таблица',
            'template' => 'Шаблон',
            'lookup' => 'Файл-справочник (прайс)',
            default => 'Файл «' . $alias . '»',
        };
    }
}
