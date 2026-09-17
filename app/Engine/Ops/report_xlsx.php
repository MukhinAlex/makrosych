<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Excel;
use App\Lib\Paths;

return [
    'op' => 'report_xlsx',
    'title' => 'Отчёт о выполнении',
    'description' => 'Формирует отчёт Excel: сводка, успешно обработанные файлы, ошибки и предупреждения. Полезен для контроля больших выгрузок.',
    'network' => false,
    'ai' => false,
    'params' => [
        'output' => ['type' => 'string', 'default' => 'отчёт.xlsx', 'desc' => 'Имя файла отчёта'],
        'summary_sheet' => ['type' => 'string', 'default' => 'Сводка', 'desc' => 'Название листа со сводкой'],
        'ok_sheet' => ['type' => 'string', 'default' => 'Успешные', 'desc' => 'Название листа с успешными записями'],
        'error_sheet' => ['type' => 'string', 'default' => 'Ошибки', 'desc' => 'Название листа с ошибками'],
    ],
    'handler' => static function (Context $ctx, array $p): void {
        $output = (string) ($p['output'] ?? 'отчёт.xlsx');
        $summaryName = (string) ($p['summary_sheet'] ?? 'Сводка');
        $okName = (string) ($p['ok_sheet'] ?? 'Успешные');
        $errorName = (string) ($p['error_sheet'] ?? 'Ошибки');

        $spreadsheet = Excel::newSpreadsheet();

        // --- Сводка ---
        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle(mb_substr($summaryName, 0, 31));
        $summary->setCellValue('A1', 'Показатель');
        $summary->setCellValue('B1', 'Значение');
        $summary->getStyle('A1:B1')->getFont()->setBold(true);

        $stats = $ctx->stats;
        $row = 2;
        foreach ($stats as $name => $value) {
            $summary->setCellValue('A' . $row, (string) $name);
            $summary->setCellValue('B' . $row, is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE));
            $row++;
        }

        $summary->setCellValue('A' . $row, 'Строк в результате');
        $summary->setCellValue('B' . $row, (string) count($ctx->rows));
        $row++;
        $summary->setCellValue('A' . $row, 'Записей о файлах');
        $summary->setCellValue('B' . $row, (string) count($ctx->files));
        $row += 2;

        $summary->setCellValue('A' . $row, 'Предупреждения и ошибки');
        $summary->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;
        foreach ($ctx->logs as $entry) {
            if ($entry['level'] === 'info') {
                continue;
            }
            $summary->setCellValue('A' . $row, $entry['level'] === 'error' ? 'Ошибка' : 'Предупреждение');
            $summary->setCellValue('B' . $row, (string) $entry['message']);
            $row++;
        }

        $summary->getColumnDimension('A')->setAutoSize(true);
        $summary->getColumnDimension('B')->setWidth(90);

        // --- Успешные ---
        $ok = $spreadsheet->createSheet();
        $ok->setTitle(mb_substr($okName, 0, 31));
        foreach (['Тип', 'Группа', 'Файл', 'Размер', 'Ссылка'] as $index => $title) {
            $ok->setCellValue(Excel::columnLetter($index + 1) . '1', $title);
        }
        $ok->getStyle('A1:E1')->getFont()->setBold(true);

        // --- Ошибки ---
        $errors = $spreadsheet->createSheet();
        $errors->setTitle(mb_substr($errorName, 0, 31));
        foreach (['Тип', 'Группа', 'Объект', 'Ошибка'] as $index => $title) {
            $errors->setCellValue(Excel::columnLetter($index + 1) . '1', $title);
        }
        $errors->getStyle('A1:D1')->getFont()->setBold(true);

        $okRow = 2;
        $errorRow = 2;

        foreach ($ctx->files as $file) {
            $status = (string) ($file['status'] ?? 'ok');
            $path = (string) ($file['path'] ?? '');
            $relative = $path !== '' && Paths::isInsideData($path) ? Paths::relativeToData($path) : $path;

            if ($status === 'error') {
                $errors->setCellValue('A' . $errorRow, (string) ($file['kind'] ?? ''));
                $errors->setCellValue('B' . $errorRow, (string) ($file['group'] ?? ''));
                $errors->setCellValue('C' . $errorRow, (string) ($file['url'] ?? $relative));
                $errors->setCellValue('D' . $errorRow, (string) ($file['error'] ?? ''));
                $errorRow++;
                continue;
            }

            if ($status === 'skipped') {
                continue;
            }

            $ok->setCellValue('A' . $okRow, (string) ($file['kind'] ?? ''));
            $ok->setCellValue('B' . $okRow, (string) ($file['group'] ?? ''));
            $ok->setCellValue('C' . $okRow, $relative);
            $ok->setCellValue('D' . $okRow, isset($file['size']) ? (string) $file['size'] : '');
            $ok->setCellValue('E' . $okRow, (string) ($file['url'] ?? ''));
            $okRow++;
        }

        foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
            $ok->getColumnDimension($letter)->setAutoSize(true);
            $errors->getColumnDimension($letter)->setAutoSize(true);
        }

        $okCount = $okRow - 2;
        $errorCount = $errorRow - 2;

        if ($ctx->dryRun) {
            $ctx->plan('excel', $output, ['ok' => $okCount, 'errors' => $errorCount]);
            $ctx->info("Проверка отчёта: успешных — {$okCount}, ошибок — {$errorCount}");

            return;
        }

        $path = $ctx->outPath($output);
        Excel::save($spreadsheet, $path);
        $spreadsheet->disconnectWorksheets();

        $ctx->files[] = ['kind' => 'report', 'status' => 'ok', 'path' => $path, 'ok' => $okCount, 'errors' => $errorCount];
        $ctx->plan('excel', $output, ['ok' => $okCount, 'errors' => $errorCount]);
        $ctx->info("Создан отчёт «{$output}»: успешных — {$okCount}, ошибок — {$errorCount}");
    },
];
