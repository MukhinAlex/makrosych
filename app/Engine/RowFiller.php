<?php

declare(strict_types=1);

namespace App\Engine;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Заполнение строки шаблона значениями, формулами и гиперссылками.
 *
 * Общая часть операций write_cells и insert_images: сопоставление полей с колонками,
 * перенос текста, формулы Excel с подстановкой {row} и {col:поле}, активные ссылки.
 */
final class RowFiller
{
    /**
     * Буквы колонок по именам полей — нужны для формул вида {col:поле}{row}.
     *
     * @param array<string, mixed> $cells колонка => поле
     * @return array<string, string>
     */
    public static function fieldColumns(array $cells): array
    {
        $map = [];
        foreach ($cells as $column => $field) {
            $map[(string) $field] = strtoupper((string) $column);
        }

        return $map;
    }

    /**
     * Запись значений, формул и ссылок в одну строку листа.
     *
     * Значения, формулы и текст ссылки пишутся до ссылок: установка значения
     * в ячейку снимает уже поставленную ссылку.
     *
     * @param array<string, mixed> $cells колонка => поле
     * @param array<string, mixed> $formulas колонка => формула или {"formula": "..."}
     * @param array<string, mixed> $hyperlinks колонка => {"url_field": "..."}, {"template": "..."} или {"folder": "...", "pattern": "..."}
     * @param array<string, mixed> $record строка данных
     * @param string[] $wrapColumns колонки, где включается перенос текста
     * @return array{formulas: int, links: int} Сколько формул и ссылок записано
     */
    public static function apply(
        Context $ctx,
        Worksheet $sheet,
        int $row,
        array $cells,
        array $formulas,
        array $hyperlinks,
        array $record,
        array $wrapColumns = [],
        string $verticalAlign = ''
    ): array {
        $fieldColumns = self::fieldColumns($cells);

        foreach ($cells as $column => $field) {
            $letter = strtoupper((string) $column);
            $value = $record[(string) $field] ?? null;

            if (is_array($value)) {
                $value = implode(';', array_map(
                    static fn ($item) => is_scalar($item) ? (string) $item : '',
                    $value
                ));
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            $sheet->setCellValue($letter . $row, $value);

            if (in_array($letter, $wrapColumns, true)) {
                $sheet->getStyle($letter . $row)->getAlignment()->setWrapText(true);
                if ($verticalAlign !== '') {
                    $sheet->getStyle($letter . $row)->getAlignment()->setVertical($verticalAlign);
                }
            }
        }

        $writtenFormulas = 0;
        foreach ($formulas as $column => $spec) {
            $letter = strtoupper((string) $column);
            $formula = is_array($spec) ? (string) ($spec['formula'] ?? '') : (string) $spec;
            if (trim($formula) === '') {
                continue;
            }

            $rendered = Excel::renderFormula($formula, ['row' => $row], $fieldColumns, $record);
            if (str_contains($rendered, '{')) {
                $ctx->warn("Формула для {$letter}{$row} содержит неподставленные значения и пропущена: {$rendered}");
                continue;
            }

            $sheet->setCellValue($letter . $row, $rendered);
            $writtenFormulas++;
        }

        $writtenLinks = 0;
        foreach ($hyperlinks as $column => $spec) {
            $letter = strtoupper((string) $column);
            $spec = is_array($spec) ? $spec : ['url_field' => (string) $spec];

            $url = '';
            if (isset($spec['url_field'])) {
                $url = Excel::text($record[(string) $spec['url_field']] ?? '');
            }

            if ($url === '' && isset($spec['template'])) {
                $url = Excel::renderTemplate((string) $spec['template'], $record);
            }

            // Ссылка на файл в папке: ищем тот же файл, что и миниатюра, — расширение
            // и лишние слова в имени значения не имеют
            if ($url === '' && isset($spec['folder'])) {
                $folder = Excel::renderTemplate((string) $spec['folder'], $record);
                $mask = Excel::renderTemplate((string) ($spec['pattern'] ?? '*'), $record);
                $found = FileSearch::find($folder, $mask, (bool) ($spec['recursive'] ?? false));

                if ($found === null) {
                    $ctx->warn(
                        is_dir($folder)
                            ? "Ссылка в {$letter}{$row}: в папке {$folder} нет файла по маске «{$mask}»"
                            : "Ссылка в {$letter}{$row}: папка не найдена — {$folder}"
                    );
                    continue;
                }

                $url = FileSearch::fileUrl($found, (string) ($spec['prefix'] ?? 'file:///'));
            }

            $url = trim($url);
            if ($url === '') {
                continue;
            }

            $cell = $letter . $row;

            // Текст ссылки пишется до самой ссылки: установка значения в ячейку снимает
            // уже поставленную ссылку (так же ведёт себя Excel), поэтому порядок важен.
            if ((bool) ($spec['keep_text'] ?? true) === false) {
                $text = isset($spec['text_field'])
                    ? Excel::text($record[(string) $spec['text_field']] ?? '')
                    : (string) ($spec['text'] ?? $url);
                $sheet->setCellValue($cell, $text);
            }

            try {
                $sheet->getCell($cell)->getHyperlink()->setUrl($url);
            } catch (\Throwable $e) {
                $ctx->warn("Не удалось поставить ссылку в {$cell}: " . $e->getMessage());
                continue;
            }

            // Ссылка на файл на диске: если файла нет, ссылка останется, но причину
            // нужно показать в журнале — иначе битая ссылка в отчёте останется незамеченной.
            if (stripos($url, 'file://') === 0) {
                $local = rawurldecode((string) preg_replace('~^file://~i', '', $url));
                $local = (string) preg_replace('~^/(?=[a-zA-Z]:)~', '', $local);
                if ($local !== '' && !is_file($local)) {
                    $ctx->warn("Файл для ссылки в {$cell} не найден: {$local}");
                }
            }

            if ((bool) ($spec['style'] ?? true)) {
                $sheet->getStyle($cell)->getFont()->setUnderline(true);
                $sheet->getStyle($cell)->getFont()->getColor()->setARGB('FF0563C1');
            }

            $writtenLinks++;
        }

        return ['formulas' => $writtenFormulas, 'links' => $writtenLinks];
    }
}
