<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Контекст исполнения сценария: данные, проходящие через операции, журнал и статистика.
 */
final class Context
{
    /** @var array<int, array<string, mixed>> Текущий набор строк. */
    public array $rows = [];

    /** @var array<int, array<string, mixed>> Записи о файлах (скачанных, найденных, созданных). */
    public array $files = [];

    /** @var array<int, array<string, mixed>> Журнал выполнения. */
    public array $logs = [];

    /** @var array<string, mixed> Сводная статистика. */
    public array $stats = [];

    /** @var array<int, array<string, mixed>> Что будет создано (для предпросмотра). */
    public array $planned = [];

    /** @var array<string, string> Входные файлы: псевдоним => путь. */
    public array $inputs = [];

    /** @var array<string, mixed> Значения параметров сценария. */
    public array $params = [];

    public bool $dryRun = false;
    public string $workDir = '';
    public string $outDir = '';
    public int $previewRows = 25;

    private const LOG_LIMIT = 800;

    public function __construct(array $config = [])
    {
        $this->dryRun = (bool) ($config['dry_run'] ?? false);
        $this->workDir = (string) ($config['work_dir'] ?? '');
        $this->outDir = (string) ($config['out_dir'] ?? '');
        $this->inputs = (array) ($config['inputs'] ?? []);
        $this->params = (array) ($config['params'] ?? []);
        $this->previewRows = (int) ($config['preview_rows'] ?? 25);

        if ($this->workDir !== '') {
            \App\Lib\Paths::ensure($this->workDir);
        }
        if ($this->outDir !== '') {
            \App\Lib\Paths::ensure($this->outDir);
        }
    }

    public function log(string $level, string $message, array $extra = []): void
    {
        if (count($this->logs) >= self::LOG_LIMIT) {
            return;
        }

        $this->logs[] = array_merge([
            'level' => $level,
            'message' => $message,
            'time' => date('H:i:s'),
        ], $extra);
    }

    public function info(string $message, array $extra = []): void
    {
        $this->log('info', $message, $extra);
    }

    public function warn(string $message, array $extra = []): void
    {
        $this->log('warning', $message, $extra);
    }

    public function error(string $message, array $extra = []): void
    {
        $this->log('error', $message, $extra);
    }

    /** Путь входного файла по псевдониму. */
    public function inputPath(string $alias): string
    {
        if (isset($this->inputs[$alias]) && is_file($this->inputs[$alias])) {
            return $this->inputs[$alias];
        }

        if (is_file($alias)) {
            return $alias;
        }

        $available = implode(', ', array_keys($this->inputs));
        $hint = match (mb_strtolower($alias)) {
            'lookup' => ' Приложите к запуску файл-справочник.',
            'template' => ' Приложите к запуску файл-шаблон.',
            'input' => ' Приложите к запуску файл с данными.',
            default => '',
        };

        throw new \RuntimeException(
            "Не найден входной файл «{$alias}». Доступные: " . ($available !== '' ? $available : 'нет') . '.' . $hint
        );
    }

    /** Путь результата внутри каталога вывода. */
    public function outPath(string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        $relative = ltrim($relative, '/');

        if (str_contains($relative, '..')) {
            throw new \RuntimeException("Недопустимый путь результата: {$relative}");
        }

        $path = $this->outDir . '/' . $relative;
        $dir = dirname($path);
        if ($dir !== '' && $dir !== $this->outDir) {
            \App\Lib\Paths::ensure($dir);
        }

        return $path;
    }

    public function plan(string $kind, string $path, array $extra = []): void
    {
        if (count($this->planned) >= 2000) {
            return;
        }

        $this->planned[] = array_merge(['kind' => $kind, 'path' => $path], $extra);
    }

    public function param(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->params) ? $this->params[$name] : $default;
    }

    public function addStat(string $key, mixed $value): void
    {
        $this->stats[$key] = $value;
    }

    public function increment(string $key, int $by = 1): void
    {
        $this->stats[$key] = (int) ($this->stats[$key] ?? 0) + $by;
    }

    /** Предпросмотр результата для интерфейса. */
    public function preview(): array
    {
        $rows = array_slice($this->rows, 0, $this->previewRows);
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                if (!in_array($key, $columns, true)) {
                    $columns[] = $key;
                }
            }
        }

        $normalized = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if (is_array($value)) {
                    $value = '[' . count($value) . ' эл.]';
                }
                $value = (string) $value;
                $line[$column] = mb_strlen($value) > 120 ? mb_substr($value, 0, 117) . '…' : $value;
            }
            $normalized[] = $line;
        }

        return [
            'columns' => $columns,
            'rows' => $normalized,
            'total_rows' => count($this->rows),
            'shown_rows' => count($normalized),
            'planned' => array_slice($this->planned, 0, 200),
            'planned_total' => count($this->planned),
            'files' => array_slice($this->files, 0, 200),
            'files_total' => count($this->files),
        ];
    }

    public function summary(): array
    {
        return [
            'rows' => count($this->rows),
            'files' => count($this->files),
            'planned' => count($this->planned),
            'stats' => $this->stats,
            'errors' => count(array_filter($this->logs, static fn ($l) => $l['level'] === 'error')),
            'warnings' => count(array_filter($this->logs, static fn ($l) => $l['level'] === 'warning')),
        ];
    }
}
