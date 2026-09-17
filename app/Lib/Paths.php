<?php

declare(strict_types=1);

namespace App\Lib;

use RuntimeException;

/**
 * Пути приложения.
 *
 * Портативный режим: все данные в program/data/ — ничего не пишется за пределами
 * каталога программы. Если каталог недоступен для записи (например, программа
 * лежит в Program Files), используется резервный каталог в профиле пользователя.
 */
final class Paths
{
    public const SUBDIRS = ['recipes', 'jobs', 'samples', 'uploads', 'output', 'logs', 'tmp'];

    private static ?string $dataRoot = null;
    private static string $mode = 'portable';
    private static bool $resolved = false;

    public static function root(): string
    {
        return APP_ROOT;
    }

    /** Каталог данных пользователя. */
    public static function dataRoot(): string
    {
        self::resolve();

        return (string) self::$dataRoot;
    }

    /** 'portable' — внутри программы, 'fallback' — в профиле пользователя. */
    public static function mode(): string
    {
        self::resolve();

        return self::$mode;
    }

    public static function isPortable(): bool
    {
        return self::mode() === 'portable';
    }

    public static function path(string $subdir, string $relative = ''): string
    {
        $base = self::dataRoot() . '/' . $subdir;
        if ($relative === '') {
            return $base;
        }

        return $base . '/' . ltrim(str_replace('\\', '/', $relative), '/');
    }

    public static function recipesDir(): string
    {
        return self::path('recipes');
    }

    public static function jobsDir(): string
    {
        return self::path('jobs');
    }

    public static function uploadsDir(): string
    {
        return self::path('uploads');
    }

    public static function outputDir(): string
    {
        return self::path('output');
    }

    public static function tmpDir(string $name = ''): string
    {
        return self::path('tmp', $name);
    }

    public static function settingsFile(): string
    {
        return self::dataRoot() . '/settings.json';
    }

    public static function tokenFile(): string
    {
        return self::dataRoot() . '/.token';
    }

    /** Создаёт каталог (включая родителей) и возвращает путь. */
    public static function ensure(string $dir): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Не удалось создать каталог: {$dir}");
        }

        return $dir;
    }

    public static function normalize(string $path): string
    {
        $real = realpath($path);
        $path = $real !== false ? $real : $path;

        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /** Защита от обхода пути: путь должен лежать внутри каталога данных. */
    public static function isInsideData(string $path): bool
    {
        $target = self::normalize($path);
        $root = self::normalize(self::dataRoot());

        return $target === $root || str_starts_with($target, $root . '/');
    }

    /** Преобразует абсолютный путь внутри данных в относительный (для ссылок в интерфейсе). */
    public static function relativeToData(string $path): string
    {
        $target = self::normalize($path);
        $root = self::normalize(self::dataRoot());
        if (!str_starts_with($target, $root . '/')) {
            return $target;
        }

        return substr($target, strlen($root) + 1);
    }

    public static function humanSize(int $bytes): string
    {
        $units = ['Б', 'КБ', 'МБ', 'ГБ'];
        $value = (float) $bytes;
        $index = 0;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return $index === 0
            ? $bytes . ' ' . $units[0]
            : number_format($value, 1, ',', ' ') . ' ' . $units[$index];
    }

    /** Рекурсивное удаление каталога. */
    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    /** Имя файла, безопасное для файловой системы Windows (кириллица сохраняется). */
    public static function sanitizeFilename(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'file';
        }

        $result = preg_replace('~[\\\\/:*?"<>|\x00-\x1F]~u', '_', $name);
        if ($result === null) {
            $result = preg_replace('~[\\\\/:*?"<>|\x00-\x1F]~', '_', $name) ?? 'file';
        }

        // Windows не допускает завершающие точки и пробелы
        $result = rtrim($result, " .\t");
        if (mb_strlen($result) > 150) {
            $result = mb_substr($result, 0, 150);
        }

        return $result !== '' ? $result : 'file';
    }

    private static function resolve(): void
    {
        if (self::$resolved) {
            return;
        }
        self::$resolved = true;

        $portable = APP_ROOT . '/data';
        if (self::prepare($portable)) {
            self::$dataRoot = $portable;
            self::$mode = 'portable';

            return;
        }

        $localAppData = getenv('LOCALAPPDATA');
        $base = ($localAppData !== false && $localAppData !== '') ? $localAppData : sys_get_temp_dir();
        $fallback = str_replace('\\', '/', $base) . '/ExcelMaster/data';

        if (self::prepare($fallback)) {
            self::$dataRoot = $fallback;
            self::$mode = 'fallback';

            return;
        }

        throw new RuntimeException(
            'Не найден каталог для данных. Проверьте права на запись: ' . $portable
        );
    }

    private static function prepare(string $dir): bool
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }

        if (!is_writable($dir)) {
            return false;
        }

        foreach (self::SUBDIRS as $sub) {
            $path = $dir . '/' . $sub;
            if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
                return false;
            }
        }

        return true;
    }
}
