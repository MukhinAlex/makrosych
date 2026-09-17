<?php

declare(strict_types=1);

/**
 * Просмотр сырых строк таблицы — для отладки сценариев.
 *
 * Запуск: php tools/rows.php <файл> <колонки> [с_строки] [по_строку] [лист] [--width=N] [--raw]
 * Пример: php tools/rows.php ..\0104\исходник.xlsx B,AD 2 20
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Engine\Excel;

$width = 28;
$raw = false;
$positional = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--width=')) {
        $width = max(10, (int) substr($argument, 8));
        continue;
    }
    if ($argument === '--raw') {
        $raw = true;
        continue;
    }
    $positional[] = $argument;
}

$path = $positional[0] ?? '';
$columns = $positional[1] ?? '';
if ($path === '' || !is_file($path) || $columns === '') {
    fwrite(STDERR, "Запуск: php tools/rows.php <файл> <колонки> [с_строки] [по_строку] [лист] [--width=N] [--raw]\n");
    exit(1);
}

$from = (int) ($positional[2] ?? 1);
$to = (int) ($positional[3] ?? $from + 20);
$sheetRef = $positional[4] ?? 1;

$letters = Excel::columns($columns);
$spreadsheet = Excel::load($path);
$sheet = Excel::sheet($spreadsheet, is_numeric($sheetRef) ? (int) $sheetRef : $sheetRef);

printf("Лист «%s», строки %d–%d\n", $sheet->getTitle(), $from, $to);

for ($row = $from; $row <= $to; $row++) {
    $cells = [];
    foreach ($letters as $letter) {
        $value = Excel::text($sheet->getCell($letter . $row)->getValue());
        if (!$raw && mb_strlen($value) > $width) {
            $value = mb_substr($value, 0, $width - 1) . '…';
        }
        $cells[] = $letter . '=' . $value;
    }
    echo str_pad((string) $row, 6) . implode(' | ', $cells) . "\n";
}

$spreadsheet->disconnectWorksheets();
