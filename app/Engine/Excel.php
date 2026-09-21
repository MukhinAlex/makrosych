<?php

declare(strict_types=1);

namespace App\Engine;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Работа с электронными таблицами: загрузка, выбор листа, чтение диапазонов.
 */
final class Excel
{
    /** Загружает файл с минимальным потреблением памяти. */
    public static function load(string $path, bool $dataOnly = true): Spreadsheet
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Файл не найден: {$path}");
        }

        $reader = IOFactory::createReaderForFile($path);
        if ($reader instanceof IReader && $dataOnly) {
            $reader->setReadDataOnly(true);
        }

        return $reader->load($path);
    }

    public static function identify(string $path): string
    {
        try {
            return IOFactory::identify($path);
        } catch (\Throwable) {
            return '';
        }
    }

    /** Сохранение книги; формат определяется расширением файла. */
    public static function save(Spreadsheet $spreadsheet, string $path): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $writerType = match ($extension) {
            'xls' => 'Xls',
            'csv' => 'Csv',
            default => 'Xlsx',
        };

        $writer = IOFactory::createWriter($spreadsheet, $writerType);

        // Диаграммы попадают в файл только при явном включении: иначе Excel
        // открывает книгу без них
        if (method_exists($writer, 'setIncludeCharts')) {
            $writer->setIncludeCharts(true);
        }

        $writer->save($path);
    }

    public static function newSpreadsheet(): Spreadsheet
    {
        return new Spreadsheet();
    }

    /** Буква колонки для заголовка по её порядковому номеру. */
    public static function columnLetter(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    /** Выбор листа: номер (с 1) или имя. */
    public static function sheet(Spreadsheet $spreadsheet, mixed $which): Worksheet
    {
        if (is_string($which) && !is_numeric($which)) {
            $sheet = $spreadsheet->getSheetByName($which);
            if ($sheet === null) {
                throw new \RuntimeException("Лист «{$which}» не найден");
            }

            return $sheet;
        }

        $index = max(1, (int) $which) - 1;
        $count = $spreadsheet->getSheetCount();
        if ($index >= $count) {
            $index = $count - 1;
        }

        return $spreadsheet->getSheet($index);
    }

    /**
     * Чтение диапазона.
     *
     * @return array<int, array<string, mixed>> [номер строки => [буква колонки => значение]]
     */
    public static function readRange(Worksheet $sheet, int $fromRow, ?int $toRow = null, ?string $lastColumn = null): array
    {
        $lastColumn ??= $sheet->getHighestDataColumn();
        $toRow ??= $sheet->getHighestDataRow();

        if ($toRow < $fromRow) {
            return [];
        }

        $range = 'A' . $fromRow . ':' . $lastColumn . $toRow;
        $grid = $sheet->rangeToArray($range, null, true, false, true);

        $result = [];
        foreach ($grid as $rowNumber => $cells) {
            $result[(int) $rowNumber] = $cells;
        }

        return $result;
    }

    /**
     * Заполнение объединённых ячеек значением из левой верхней ячейки диапазона.
     *
     * @param array<int, array<string, mixed>> $grid
     */
    public static function fillMerged(Worksheet $sheet, array &$grid): void
    {
        foreach ($sheet->getMergeCells() as $range) {
            [$start, $end] = array_pad(explode(':', $range, 2), 2, null);
            $startCell = Coordinate::coordinateFromString($start);
            $value = null;

            if (isset($grid[(int) $startCell[1]][$startCell[0]])) {
                $value = $grid[(int) $startCell[1]][$startCell[0]];
            }

            if ($value === null || $value === '') {
                continue;
            }

            $endCell = $end !== null ? Coordinate::coordinateFromString($end) : $startCell;
            $startCol = Coordinate::columnIndexFromString($startCell[0]);
            $endCol = Coordinate::columnIndexFromString($endCell[0]);

            for ($row = (int) $startCell[1]; $row <= (int) $endCell[1]; $row++) {
                for ($col = $startCol; $col <= $endCol; $col++) {
                    $letter = Coordinate::stringFromColumnIndex($col);
                    if (($grid[$row][$letter] ?? null) === null || ($grid[$row][$letter] ?? '') === '') {
                        $grid[$row][$letter] = $value;
                    }
                }
            }
        }
    }

    /** Буква, диапазон ("G:J") или список ("G,H,I") → список букв. */
    public static function columns(mixed $spec): array
    {
        if (is_array($spec)) {
            $columns = [];
            foreach ($spec as $item) {
                $columns = array_merge($columns, self::columns($item));
            }

            return $columns;
        }

        $spec = strtoupper(trim((string) $spec));
        if ($spec === '') {
            return [];
        }

        if (str_contains($spec, ',')) {
            $columns = [];
            foreach (explode(',', $spec) as $part) {
                $columns = array_merge($columns, self::columns($part));
            }

            return $columns;
        }

        if (str_contains($spec, ':')) {
            [$from, $to] = explode(':', $spec, 2);
            $start = Coordinate::columnIndexFromString($from);
            $end = Coordinate::columnIndexFromString($to);
            if ($end < $start) {
                [$start, $end] = [$end, $start];
            }

            $columns = [];
            for ($i = $start; $i <= $end; $i++) {
                $columns[] = Coordinate::stringFromColumnIndex($i);
            }

            return $columns;
        }

        return [$spec];
    }

    public static function cell(array $grid, int $row, string $column): mixed
    {
        return $grid[$row][$column] ?? null;
    }

    /**
     * Индекс «значение колонки => номер строки» — поиск строк шаблона по ключу.
     *
     * @return array<string, int>
     */
    public static function rowIndex(Worksheet $sheet, string $column, int $fromRow): array
    {
        $column = strtoupper($column);
        $lastRow = max($sheet->getHighestDataRow(), $fromRow);

        $index = [];
        for ($row = $fromRow; $row <= $lastRow; $row++) {
            $key = self::text($sheet->getCell($column . $row)->getValue());
            if ($key !== '' && !isset($index[$key])) {
                $index[$key] = $row;
            }
        }

        return $index;
    }

    /**
     * Подстановка служебных значений в формулу Excel.
     *
     * Доступны {row} и {last} из $vars, {col:поле} — буква колонки, куда записано поле,
     * и {поле} — значение поля строки. Неподставленные подстановки остаются как есть,
     * чтобы вызывающий код мог их заметить и предупредить пользователя.
     *
     * @param array<string, int|string> $vars
     * @param array<string, string> $fieldColumns поле => буква колонки
     * @param array<string, mixed> $record
     */
    public static function renderFormula(string $formula, array $vars, array $fieldColumns = [], array $record = []): string
    {
        return (string) preg_replace_callback(
            '~\{([a-zA-Z0-9_:]+)\}~',
            static function (array $matches) use ($vars, $fieldColumns, $record): string {
                $key = $matches[1];

                if (array_key_exists($key, $vars)) {
                    return (string) $vars[$key];
                }

                if (str_starts_with($key, 'col:')) {
                    return (string) ($fieldColumns[substr($key, 4)] ?? '');
                }

                if (array_key_exists($key, $record)) {
                    $value = $record[$key];
                    if (is_array($value)) {
                        $value = implode(';', array_map(
                            static fn ($item) => is_scalar($item) ? (string) $item : '',
                            $value
                        ));
                    }

                    return self::text($value);
                }

                return $matches[0];
            },
            $formula
        );
    }

    /**
     * Подстановка значений полей строки в шаблон адреса: {поле}.
     *
     * @param array<string, mixed> $record
     */
    public static function renderTemplate(string $template, array $record): string
    {
        return (string) preg_replace_callback(
            '~\{([a-zA-Z0-9_]+)\}~',
            static fn (array $matches) => self::text($record[$matches[1]] ?? ''),
            $template
        );
    }

    /**
     * Вставка пустых колонок со сдвигом остальных вправо.
     *
     * Ключ — буква колонки в ИТОГОВОМ файле (заголовок пишется в строку $headerRow).
     * Вставка идёт слева направо, поэтому буквы не «съезжают»: вставив колонку B,
     * следующую можно указывать как C. Оформление новой колонки берётся у соседней слева.
     *
     * @param array<string, mixed> $columns буква => заголовок
     * @return int Число вставленных колонок
     */
    public static function insertColumns(Worksheet $sheet, array $columns, int $headerRow = 1): int
    {
        $specs = [];
        foreach ($columns as $column => $title) {
            $letter = strtoupper((string) $column);
            if ($letter === '') {
                continue;
            }

            $specs[$letter] = is_array($title) ? (string) ($title['title'] ?? '') : (string) $title;
        }

        if ($specs === []) {
            return 0;
        }

        uksort($specs, static fn (string $a, string $b): int => Coordinate::columnIndexFromString($a) <=> Coordinate::columnIndexFromString($b));

        $inserted = 0;
        foreach ($specs as $letter => $title) {
            $sheet->insertNewColumnBefore($letter, 1);
            $inserted++;

            if ($title !== '' && $headerRow > 0) {
                $sheet->setCellValue($letter . $headerRow, $title);
            }
        }

        return $inserted;
    }

    /** Приведение значения ячейки к строке (без ведущих и хвостовых пробелов). */
    public static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return trim((string) $value);
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
