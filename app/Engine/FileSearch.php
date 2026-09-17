<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Поиск файлов в папке на диске.
 *
 * Нужен, когда имена файлов заранее неизвестны: например, в таблице есть только имя
 * фотографии, а рядом лежат «001.jpg», «001 - копия.jpg» и «001.png». Поиск идёт по маске
 * с подстановками из строки ({name}.*) и не зависит от регистра — как в Проводнике Windows.
 */
final class FileSearch
{
    /**
     * Первый файл в папке, подходящий под маску. Файлы перебираются по имени.
     *
     * @param string $folder папка (абсолютный путь, UNC или путь внутри каталога результата)
     * @param string $mask маска имени файла: "001.*", "*.jpg", "фото {code}*"
     */
    public static function find(string $folder, string $mask, bool $recursive = false, int $limit = 20000): ?string
    {
        $folder = self::resolveFolder($folder);
        if ($folder === '' || !is_dir($folder)) {
            return null;
        }

        $mask = trim($mask);
        if ($mask === '') {
            $mask = '*';
        }

        // Точное имя без масок: сначала пробуем как есть, потом — с обычными расширениями картинок
        if (!str_contains($mask, '*') && !str_contains($mask, '?')) {
            foreach (self::candidates($folder, $mask) as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        $maskLower = mb_strtolower($mask);
        $matches = [];
        $checked = 0;

        $iterator = $recursive
            ? new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            )
            : new \DirectoryIterator($folder);

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }

            $name = $item->getFilename();
            if (!fnmatch($mask, $name) && !fnmatch($maskLower, mb_strtolower($name))) {
                continue;
            }

            $matches[$name] = str_replace('\\', '/', $item->getPathname());
            if (++$checked >= $limit) {
                break;
            }
        }

        if ($matches === []) {
            return null;
        }

        // Устойчивый порядок: «001.jpg» должен побеждать «001 - копия.jpg» и «0012.jpg»
        uksort($matches, static fn (string $a, string $b) => strnatcasecmp($a, $b));

        return (string) reset($matches);
    }

    /**
     * Ссылка на файл на диске для Excel: file:///C:/папка/файл.jpg.
     *
     * Пробелы и служебные символы кодируются — иначе Excel может не открыть путь
     * с пробелом. Кириллица остаётся как есть: её Excel понимает.
     */
    public static function fileUrl(string $path, string $prefix = 'file:///'): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $path = str_replace(['%', ' ', '#'], ['%25', '%20', '%23'], $path);

        return str_ends_with($prefix, '/') ? $prefix . $path : $prefix . '/' . $path;
    }

    /**
     * Папка поиска: абсолютный путь как есть, относительный — внутри каталога данных.
     *
     * Благодаря этому сценарий переносится вместе с папкой данных: «samples/фото»
     * продолжит работать, если программу скопировали на другой компьютер или флешку.
     */
    public static function resolveFolder(string $folder): string
    {
        $folder = rtrim(str_replace('\\', '/', trim($folder)), '/');
        if ($folder === '') {
            return '';
        }

        if (is_dir($folder)) {
            return $folder;
        }

        if (preg_match('~^[a-zA-Z]:~', $folder) === 1 || str_starts_with($folder, '//')) {
            return $folder;
        }

        $insideData = rtrim(str_replace('\\', '/', \App\Lib\Paths::dataRoot()), '/') . '/' . ltrim($folder, '/');

        return is_dir($insideData) ? $insideData : $folder;
    }

    /**
     * Варианты имени файла, если расширение в таблице не указано.
     *
     * @return array<int, string>
     */
    private static function candidates(string $folder, string $name): array
    {
        $candidates = [$folder . '/' . $name];

        if (pathinfo($name, PATHINFO_EXTENSION) === '') {
            foreach (['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'JPG', 'JPEG', 'PNG'] as $extension) {
                $candidates[] = $folder . '/' . $name . '.' . $extension;
            }
        }

        return $candidates;
    }
}
