<?php

declare(strict_types=1);

namespace App\Lib;

use App\Engine\Excel;
use App\Engine\Transform;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Анализ структуры документа без обращения к нейросети.
 *
 * Профиль описывает файл компактно: листы, строку заголовков, начало данных,
 * колонки, типы значений, примеры. Именно профиль отправляется модели — целиком
 * документ не передаётся.
 */
final class Profiler
{
    private const HEADER_SCAN_ROWS = 10;
    private const MAX_COLUMNS = 120;
    private const SAMPLE_ROWS = 200;

    /**
     * @return array<string, mixed>
     */
    public static function profile(string $path, array $options = []): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Файл не найден: {$path}");
        }

        $sampleRows = max(0, (int) ($options['sample_rows'] ?? 3));
        $mask = (bool) ($options['mask'] ?? false);
        $maxSheets = max(1, (int) ($options['max_sheets'] ?? 5));

        $spreadsheet = Excel::load($path);
        $sheetsInfo = [];
        $mainSheet = null;
        $mainTitle = '';

        for ($index = 0; $index < min($spreadsheet->getSheetCount(), $maxSheets); $index++) {
            $sheet = $spreadsheet->getSheet($index);
            $rows = $sheet->getHighestDataRow();
            $columns = $sheet->getHighestDataColumn();

            $sheetsInfo[] = [
                'index' => $index + 1,
                'title' => $sheet->getTitle(),
                'rows' => $rows,
                'columns' => $columns,
                'empty' => $rows === 1 && $columns === 'A' && Excel::isEmpty($sheet->getCell('A1')->getValue()),
            ];

            if ($mainSheet === null && $rows > 1) {
                $mainSheet = $sheet;
                $mainTitle = $sheet->getTitle();
            }
        }

        if ($mainSheet === null) {
            $mainSheet = $spreadsheet->getSheet(0);
            $mainTitle = $mainSheet->getTitle();
        }

        $profile = [
            'file' => [
                'name' => basename($path),
                'format' => Excel::identify($path),
                'size' => (int) filesize($path),
                'size_human' => Paths::humanSize((int) filesize($path)),
            ],
            'sheets' => $sheetsInfo,
            'main_sheet' => $mainTitle,
            'header_row' => 1,
            'header_candidates' => [],
            'data_from_row' => 2,
            'columns' => [],
            'sample_rows' => [],
            'notes' => [],
        ];

        $profile = array_merge($profile, self::analyzeSheet($mainSheet, $sampleRows, $mask));

        $spreadsheet->disconnectWorksheets();

        return $profile;
    }

    /** @return array<string, mixed> */
    private static function analyzeSheet(Worksheet $sheet, int $sampleRows, bool $mask): array
    {
        $lastRow = $sheet->getHighestDataRow();
        $lastColumn = $sheet->getHighestDataColumn();
        $scanRows = min($lastRow, max(self::SAMPLE_ROWS, self::HEADER_SCAN_ROWS + 1));

        $grid = Excel::readRange($sheet, 1, $scanRows, $lastColumn);
        $mergeCount = 0;
        try {
            $mergeCount = count($sheet->getMergeCells());
            if ($mergeCount > 0) {
                Excel::fillMerged($sheet, $grid);
            }
        } catch (\Throwable) {
            $mergeCount = 0;
        }

        $letters = [];
        $columnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($lastColumn);
        for ($index = 1; $index <= min($columnIndex, self::MAX_COLUMNS); $index++) {
            $letters[] = Excel::columnLetter($index);
        }

        $headerRow = self::detectHeaderRow($grid, $letters, $scanRows);
        $dataFrom = self::detectDataStart($grid, $letters, $headerRow, $scanRows);

        $columns = [];
        foreach ($letters as $letter) {
            $header = Excel::text($grid[$headerRow][$letter] ?? null);
            $values = [];
            for ($row = $dataFrom; $row <= $scanRows; $row++) {
                $value = Excel::text($grid[$row][$letter] ?? null);
                if ($value !== '') {
                    $values[] = $value;
                }
            }

            if ($header === '' && $values === []) {
                continue;
            }

            $columns[] = self::describeColumn($letter, $header, $values, $mask);
        }

        $notes = [];
        if ($mergeCount > 0) {
            $notes[] = "В файле {$mergeCount} объединённых диапазонов ячеек — пустые значения заполнены из левой верхней ячейки.";
        }
        $notes[] = "Проанализировано строк: " . ($scanRows - $dataFrom + 1) . ' из ' . $lastRow . '.';

        $sample = [];
        for ($row = $dataFrom; $row <= min($dataFrom + $sampleRows - 1, $scanRows); $row++) {
            $line = [];
            foreach ($letters as $letter) {
                $value = Excel::text($grid[$row][$letter] ?? null);
                if ($value === '') {
                    continue;
                }
                $line[$letter] = $mask ? self::maskValue($value) : self::shorten($value);
            }
            if ($line !== []) {
                $sample[] = $line;
            }
        }

        return [
            'header_row' => $headerRow,
            'header_candidates' => self::headerCandidates($grid, $letters, $scanRows),
            'data_from_row' => $dataFrom,
            'columns' => $columns,
            'sample_rows' => $sample,
            'notes' => $notes,
        ];
    }

    /** @param array<int, array<string, mixed>> $grid */
    private static function detectHeaderRow(array $grid, array $letters, int $scanRows): int
    {
        $scores = [];
        for ($row = 1; $row <= min($scanRows, self::HEADER_SCAN_ROWS); $row++) {
            $score = 0;
            foreach ($letters as $letter) {
                $value = Excel::text($grid[$row][$letter] ?? null);
                if ($value === '' || Transform::isNumeric($value) || mb_strlen($value) > 80) {
                    continue;
                }
                $score += 2;
                if (preg_match('~\p{L}~u', $value) === 1) {
                    $score++;
                }
                if (str_contains($value, "\n")) {
                    $score--;
                }
            }
            $scores[$row] = $score;
        }

        $best = 1;
        $bestScore = -1;
        foreach ($scores as $row => $score) {
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        return $best;
    }

    /** @param array<int, array<string, mixed>> $grid */
    private static function headerCandidates(array $grid, array $letters, int $scanRows): array
    {
        $candidates = [];
        for ($row = 1; $row <= min($scanRows, self::HEADER_SCAN_ROWS); $row++) {
            $filled = 0;
            foreach ($letters as $letter) {
                if (!Excel::isEmpty($grid[$row][$letter] ?? null)) {
                    $filled++;
                }
            }
            if ($filled > 0) {
                $candidates[] = $row;
            }
        }

        return $candidates;
    }

    /** @param array<int, array<string, mixed>> $grid */
    private static function detectDataStart(array $grid, array $letters, int $headerRow, int $scanRows): int
    {
        $total = max(1, count($letters));
        $limit = min($scanRows, $headerRow + 30);

        for ($row = $headerRow + 1; $row <= $limit; $row++) {
            $stats = self::rowStats($grid, $letters, $row);

            // Строки-легенды («мультивыбор», длинные перечисления значений) данными не являются
            if ($stats['legend'] || $stats['filled'] < 2) {
                continue;
            }

            if ($stats['filled'] / $total < 0.15) {
                continue;
            }

            return $row;
        }

        return $headerRow + 1;
    }

    /**
     * Признаки строки: заполненность, средняя длина значений, наличие перечислений.
     *
     * @param array<int, array<string, mixed>> $grid
     * @return array{filled: int, avg_length: float, max_parts: int, enumerations: int, legend: bool}
     */
    private static function rowStats(array $grid, array $letters, int $row): array
    {
        $filled = 0;
        $lengthSum = 0;
        $maxParts = 0;
        $enumerations = 0;
        $distinct = [];

        foreach ($letters as $letter) {
            $value = Excel::text($grid[$row][$letter] ?? null);
            if ($value === '') {
                continue;
            }

            $filled++;
            $lengthSum += mb_strlen($value);
            $distinct[$value] = true;

            if (str_contains($value, ';')) {
                $parts = count(explode(';', rtrim($value, '; ')));
                $maxParts = max($maxParts, $parts);
                if ($parts >= 3) {
                    $enumerations++;
                }
            }
        }

        $average = $filled > 0 ? $lengthSum / $filled : 0.0;
        $distinctRatio = $filled > 0 ? count($distinct) / $filled : 0.0;

        return [
            'filled' => $filled,
            'avg_length' => $average,
            'max_parts' => $maxParts,
            'enumerations' => $enumerations,
            'distinct_ratio' => $distinctRatio,
            // Легенда — либо длинные перечисления значений, либо многократный повтор
            // одного и того же маркера («мультивыбор») в разных колонках
            'legend' => ($maxParts >= 4 && $enumerations >= 2)
                || $average > 120
                || ($filled >= 5 && $distinctRatio < 0.35),
        ];
    }

    /** @return array<string, mixed> */
    private static function describeColumn(string $letter, string $header, array $values, bool $mask): array
    {
        $total = count($values);
        $numeric = 0;
        $urls = 0;
        $separators = [];
        $maxLength = 0;
        $distinct = [];

        foreach ($values as $value) {
            if (Transform::isNumeric($value)) {
                $numeric++;
            }
            if (preg_match('~^https?://~i', $value) === 1) {
                $urls++;
            }
            foreach ([';' => ';', "\n" => 'перенос строки', ',' => ','] as $needle => $label) {
                if (str_contains($value, $needle)) {
                    $separators[$label] = ($separators[$label] ?? 0) + 1;
                }
            }
            $maxLength = max($maxLength, mb_strlen($value));
            if (count($distinct) < 500) {
                $distinct[$value] = true;
            }
        }

        $type = 'text';
        if ($total > 0 && $numeric === $total) {
            $type = 'number';
        } elseif ($total > 0 && $urls === $total) {
            $type = 'url';
        } elseif ($total > 0 && $urls > 0) {
            $type = 'text+url';
        } elseif ($total > 0 && $numeric > $total / 2) {
            $type = 'number+text';
        }

        $samples = array_slice($values, 0, 3);

        return [
            'letter' => $letter,
            'header' => $header,
            'type' => $type,
            'filled' => $total,
            'distinct' => count($distinct),
            'unique_ratio' => $total > 0 ? round(count($distinct) / $total, 2) : 0,
            'urls' => $urls,
            'separators' => $separators,
            'max_length' => $maxLength,
            'sample' => array_map(
                static fn (string $value) => $mask ? self::maskValue($value) : self::shorten($value),
                $samples
            ),
        ];
    }

    private static function shorten(string $value): string
    {
        $value = preg_replace('~\s+~u', ' ', $value) ?? $value;

        return mb_strlen($value) > 90 ? mb_substr($value, 0, 87) . '…' : $value;
    }

    private static function maskValue(string $value): string
    {
        $length = mb_strlen($value);
        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return mb_substr($value, 0, 2) . str_repeat('•', min(10, $length - 4)) . mb_substr($value, -2)
            . " (длина {$length})";
    }

    /**
     * Компактное текстовое описание профиля для модели.
     *
     * Размер ограничен: локальные модели плохо переносят длинные запросы.
     * Сначала колонки описываются подробно; когда бюджет исчерпан, переходим
     * к краткому виду, затем — к простому перечислению.
     */
    public static function toPromptText(array $profile, int $maxChars = 14000): string
    {
        $lines = [];
        $lines[] = 'Файл: ' . ($profile['file']['name'] ?? '') . ' (' . ($profile['file']['format'] ?? '') . ', ' . ($profile['file']['size_human'] ?? '') . ')';

        $sheets = [];
        foreach ((array) ($profile['sheets'] ?? []) as $sheet) {
            $sheets[] = "{$sheet['title']} (строк: {$sheet['rows']}, колонок: {$sheet['columns']})";
        }
        $lines[] = 'Листы: ' . implode('; ', $sheets);
        $lines[] = 'Основной лист: ' . ($profile['main_sheet'] ?? '');
        $lines[] = 'Строка заголовков: ' . ($profile['header_row'] ?? 1);
        $lines[] = 'Первая строка данных: ' . ($profile['data_from_row'] ?? 2);
        $lines[] = '';
        $lines[] = 'Колонки (буква | заголовок | тип | заполнено/уникальных | признаки | примеры):';

        $columns = (array) ($profile['columns'] ?? []);
        $reserve = 900; // запас на примеры строк и примечания
        $used = mb_strlen(implode("\n", $lines)) + $reserve;
        $brief = [];
        $skipped = [];

        foreach ($columns as $column) {
            $base = $column['letter'] . ' | ' . mb_substr((string) $column['header'], 0, 60)
                . ' | ' . $column['type'] . ' | ' . $column['filled'] . '/' . $column['distinct'];

            $signs = [];
            if (($column['urls'] ?? 0) > 0) {
                $signs[] = 'ссылок: ' . $column['urls'];
            }
            foreach (($column['separators'] ?? []) as $label => $count) {
                $signs[] = 'разделитель ' . $label . ' (' . $count . ')';
            }
            if ($signs !== []) {
                $base .= ' | ' . implode(', ', $signs);
            }

            $samples = array_map(
                static fn ($value) => mb_substr((string) $value, 0, 50),
                (array) ($column['sample'] ?? [])
            );

            $full = '  ' . $base;
            if ($samples !== []) {
                $full .= ' | примеры: ' . implode(' / ', array_map(static fn ($v) => '"' . $v . '"', $samples));
            }

            // Подробная строка помещается — берём её
            if ($used + mb_strlen($full) <= $maxChars) {
                $lines[] = $full;
                $used += mb_strlen($full) + 1;
                continue;
            }

            // Иначе — краткая строка без примеров
            $short = '  ' . $base;
            if ($used + mb_strlen($short) <= $maxChars) {
                $brief[] = $short;
                $used += mb_strlen($short) + 1;
                continue;
            }

            $skipped[] = $column['letter'];
        }

        if ($brief !== []) {
            $lines[] = '';
            $lines[] = 'Остальные колонки (кратко):';
            foreach ($brief as $line) {
                $lines[] = $line;
            }
        }

        if ($skipped !== []) {
            $lines[] = '';
            $lines[] = 'Колонки без описания (только буквы): ' . implode(', ', $skipped);
        }

        if (($profile['sample_rows'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = 'Примеры строк (буква колонки: значение):';
            foreach ($profile['sample_rows'] as $index => $row) {
                // Ограничиваем: в широких таблицах строка целиком не нужна
                $cells = [];
                foreach (array_slice($row, 0, 25, true) as $letter => $value) {
                    $cells[] = "{$letter}=\"" . mb_substr((string) $value, 0, 40) . '"';
                }
                $lines[] = '  ' . ($index + 1) . ') ' . implode(', ', $cells);
            }
        }

        if (($profile['notes'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = 'Примечания: ' . implode(' ', $profile['notes']);
        }

        return implode("\n", $lines);
    }
}
