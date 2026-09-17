<?php

declare(strict_types=1);

/**
 * Просмотр профиля таблицы без запуска приложения.
 *
 * Запуск: php tools/profile.php <файл> [число_строк_примеров] [--brief]
 *         php tools/profile.php <файл> --columns=AD,Y,Z,AA,AC
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Lib\Profiler;

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Укажите существующий файл: php tools/profile.php <файл>\n");
    exit(1);
}

$brief = in_array('--brief', $argv, true);
$sampleRows = 3;
$only = [];

foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--columns=')) {
        $only = array_map('strtoupper', explode(',', substr($argument, 10)));
        continue;
    }
    if (ctype_digit($argument)) {
        $sampleRows = (int) $argument;
    }
}

$profile = Profiler::profile($path, ['sample_rows' => $sampleRows]);

if ($brief || $only !== []) {
    printf(
        "Файл: %s | листов: %d | основной: %s | заголовок: %d | данные с: %d\n",
        $profile['file']['name'],
        count($profile['sheets']),
        $profile['main_sheet'],
        $profile['header_row'],
        $profile['data_from_row']
    );
    echo "Колонка | Заголовок | Тип | Заполнено | Уникальных | Разделители | Примеры\n";
    foreach ($profile['columns'] as $column) {
        if ($only !== [] && !in_array($column['letter'], $only, true)) {
            continue;
        }

        $separators = [];
        foreach (($column['separators'] ?? []) as $label => $count) {
            $separators[] = $label . ' (' . $count . ')';
        }

        printf(
            "%-7s | %-40s | %-10s | %5d | %5d | %-22s | %s\n",
            $column['letter'],
            mb_substr((string) $column['header'], 0, 40),
            $column['type'],
            $column['filled'],
            $column['distinct'],
            implode(', ', $separators),
            implode(' / ', array_map(static fn ($v) => mb_substr((string) $v, 0, 40), $column['sample']))
        );
    }
    exit(0);
}

echo Profiler::toPromptText($profile), "\n";
