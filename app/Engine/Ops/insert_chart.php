<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Excel;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

return [
    'op' => 'insert_chart',
    'title' => 'Диаграмма',
    'description' => 'Строит диаграмму по колонкам файла: столбчатую, линейную, круговую или площадную. Обычно ставится после write_new_sheet или write_cells — диаграмма строится по готовому результату: подписи берутся из колонки категорий, значения — из колонок серий. Диаграмма вставляется в файл рядом с данными, файл сохраняется под именем "output". Файл указывается параметром "source" (не "file"): это либо псевдоним входного файла, либо имя файла, созданного предыдущим шагом.',
    'network' => false,
    'ai' => false,
    'params' => [
        'source' => ['type' => 'string', 'required' => true, 'desc' => 'Файл с данными: псевдоним входного файла (input, template) или имя файла, созданного предыдущим шагом, например "результат.xlsx"'],
        'output' => ['type' => 'string', 'required' => true, 'desc' => 'Имя файла результата с диаграммой'],
        'sheet' => ['type' => 'int', 'desc' => 'Номер листа с данными (по умолчанию первый)'],
        'type' => ['type' => 'string', 'default' => 'bar', 'desc' => 'Вид диаграммы: bar (столбчатая), line (линейная), pie (круговая), area (площадная)'],
        'title' => ['type' => 'string', 'desc' => 'Заголовок диаграммы'],
        'categories' => ['type' => 'string', 'desc' => 'Колонка с подписями (категориями), например A'],
        'series' => ['type' => 'object', 'required' => true, 'desc' => 'Колонки со значениями: {"B": "Сумма", "C": "Количество"} — буква колонки => подпись серии'],
        'data_from_row' => ['type' => 'int', 'default' => 2, 'desc' => 'Первая строка данных (заголовки не берутся)'],
        'last_row' => ['type' => 'int', 'desc' => 'Последняя строка данных (по умолчанию — последняя заполненная)'],
        'position' => ['type' => 'string', 'desc' => 'Ячейка, в которую поставить диаграмму (по умолчанию — справа от данных)'],
        'width' => ['type' => 'int', 'default' => 480, 'desc' => 'Ширина диаграммы в пикселях'],
        'height' => ['type' => 'int', 'default' => 300, 'desc' => 'Высота диаграммы в пикселях'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $source = (string) $p['source'];
        $output = (string) $p['output'];
        $fromRow = max(1, (int) ($p['data_from_row'] ?? 2));
        $sheetIndex = max(1, (int) ($p['sheet'] ?? 1)) - 1;

        $series = [];
        foreach ((array) $p['series'] as $column => $label) {
            $letter = strtoupper(trim((string) $column));
            if ($letter !== '') {
                $series[$letter] = (string) $label;
            }
        }
        if ($series === []) {
            throw new \RuntimeException('Не указаны колонки со значениями (параметр series)');
        }

        $categories = strtoupper(trim((string) ($p['categories'] ?? '')));
        $type = strtolower(trim((string) ($p['type'] ?? 'bar')));
        $chartTitle = trim((string) ($p['title'] ?? ''));

        [$plotType, $grouping] = match ($type) {
            'line' => [DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD],
            'pie' => [DataSeries::TYPE_PIECHART, DataSeries::GROUPING_STANDARD],
            'area' => [DataSeries::TYPE_AREACHART, DataSeries::GROUPING_STANDARD],
            default => [DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED],
        };

        $path = null;
        if (isset($ctx->inputs[$source]) && is_file($ctx->inputs[$source])) {
            $path = $ctx->inputs[$source];
        } else {
            try {
                $candidate = $ctx->outPath($source);
                if (is_file($candidate)) {
                    $path = $candidate;
                }
            } catch (\Throwable) {
                $path = null;
            }
        }

        if ($path === null && $ctx->dryRun) {
            // В режиме проверки файлы предыдущих шагов не создаются: если такой файл
            // объявлен в плане, проверяем диаграмму без чтения книги
            foreach ($ctx->planned as $item) {
                if (is_array($item) && (string) ($item['path'] ?? '') === $source) {
                    $rows = (int) ($item['rows'] ?? 0);
                    $ctx->plan('chart', $output, ['type' => $type, 'rows' => $rows]);
                    $ctx->increment('диаграмм', 1);
                    $ctx->info(
                        'Проверка: диаграмма «' . ($chartTitle !== '' ? $chartTitle : $type)
                        . "» будет построена по файлу «{$source}»"
                        . ($rows > 0 ? " (строк данных: {$rows})" : '')
                    );

                    return;
                }
            }
        }

        if ($path === null) {
            throw new \RuntimeException(
                "Не найден файл для диаграммы «{$source}». Доступные входные файлы: "
                . (implode(', ', array_keys($ctx->inputs)) ?: 'нет')
                . '. Если диаграмма строится по результату, укажите имя файла, созданного предыдущим шагом.'
            );
        }

        $spreadsheet = Excel::load($path, false);
        $sheets = $spreadsheet->getAllSheets();
        if (!isset($sheets[$sheetIndex])) {
            throw new \RuntimeException('В файле «' . $source . '» нет листа номер ' . ($sheetIndex + 1));
        }

        $sheet = $sheets[$sheetIndex];
        $sheetName = $sheet->getTitle();
        $lastRow = (int) ($p['last_row'] ?? 0);
        if ($lastRow <= 0) {
            $lastRow = $sheet->getHighestDataRow();
        }

        if ($lastRow < $fromRow) {
            throw new \RuntimeException(
                "Для диаграммы нет данных: строки с {$fromRow} по {$lastRow} в файле «{$source}»"
            );
        }

        $pointCount = $lastRow - $fromRow + 1;

        $values = [];
        foreach ($series as $letter => $label) {
            $range = sprintf("'%s'!\$%s\$%d:\$%s\$%d", $sheetName, $letter, $fromRow, $letter, $lastRow);
            $values[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $range, null, $pointCount);
        }

        $categoriesRef = [];
        if ($categories !== '') {
            $range = sprintf("'%s'!\$%s\$%d:\$%s\$%d", $sheetName, $categories, $fromRow, $categories, $lastRow);
            $categoriesRef[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $range, null, $pointCount);
        }

        $labels = [];
        foreach ($series as $label) {
            $labels[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, [$label]);
        }

        // Круговая диаграмма строится по одной серии: лишние колонки отбрасываем
        if ($type === 'pie' && count($values) > 1) {
            $ctx->warn('У круговой диаграммы взята только первая колонка значений: круговая диаграмма строится по одной серии');
            $values = [$values[0]];
            $labels = [$labels[0]];
        }

        $dataSeries = new DataSeries(
            $plotType,
            $grouping,
            range(0, count($values) - 1),
            $labels,
            $categoriesRef,
            $values
        );

        $chart = new Chart(
            'chart_' . substr(md5($output . microtime(true)), 0, 8),
            new Title((string) ($p['title'] ?? '')),
            new Legend(Legend::POSITION_RIGHT, null, false),
            new PlotArea(null, [$dataSeries])
        );

        // Место диаграммы: справа от данных, если не указано своё
        $position = trim((string) ($p['position'] ?? ''));
        if ($position === '') {
            $usedColumns = [$categories === '' ? 1 : Coordinate::columnIndexFromString($categories)];
            foreach (array_keys($series) as $letter) {
                $usedColumns[] = Coordinate::columnIndexFromString($letter);
            }
            $position = Coordinate::stringFromColumnIndex(max($usedColumns) + 2) . max(1, $fromRow);
        }

        $width = max(120, (int) ($p['width'] ?? 480));
        $height = max(120, (int) ($p['height'] ?? 300));

        $chart->setTopLeftPosition($position);
        $chart->setBottomRightPosition($position, $width, $height);
        // Диаграмма попадает в книгу только после addChart: setWorksheet в этой
        // версии PhpSpreadsheet её не регистрирует
        $sheet->addChart($chart);
        $chart->setWorksheet($sheet);

        $describe = "Диаграмма «" . ($chartTitle !== '' ? $chartTitle : $type) . "» по колонкам "
            . implode(', ', array_keys($series))
            . ($categories !== '' ? " (подписи из " . $categories . ')' : '')
            . ": строк {$fromRow}–{$lastRow}";

        if ($ctx->dryRun) {
            $ctx->plan('chart', $output, ['type' => $type, 'rows' => $pointCount]);
            $ctx->increment('диаграмм', 1);
            $ctx->info('Проверка: ' . $describe . ' — будет добавлена в файл «' . $output . '»');

            $spreadsheet->disconnectWorksheets();

            return;
        }

        $outPath = $ctx->outPath($output);
        Excel::save($spreadsheet, $outPath);
        $spreadsheet->disconnectWorksheets();

        $ctx->increment('диаграмм', 1);
        $ctx->files[] = [
            'kind' => 'chart',
            'status' => 'ok',
            'path' => $outPath,
            'type' => $type,
            'rows' => $pointCount,
        ];
        $ctx->plan('chart', $output, ['type' => $type, 'rows' => $pointCount]);
        $ctx->info($describe . ' — файл «' . $output . '»');
    },
];
