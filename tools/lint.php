<?php

declare(strict_types=1);

/**
 * Проверка синтаксиса всех PHP-файлов приложения.
 *
 * Запуск: php tools/lint.php
 *
 * Разбор идёт в самом PHP (token_get_all с TOKEN_PARSE), без запуска дочернего процесса:
 * при вызове «php -l» кириллица в пути программы (например папка «Макросыч-1.0») доходила
 * до дочернего процесса в неверной кодировке, и проверка сообщала об ошибках на всех файлах.
 */

$root = dirname(__DIR__);
$directories = [$root . '/app', $root . '/tools'];
$errors = 0;
$checked = 0;

$iterator = new AppendIterator();
foreach ($directories as $directory) {
    if (!is_dir($directory)) {
        continue;
    }
    $iterator->append(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    ));
}

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $checked++;
    $source = (string) file_get_contents($file->getPathname());

    try {
        token_get_all($source, TOKEN_PARSE);
    } catch (ParseError $error) {
        $errors++;
        echo 'ОШИБКА: ' . str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)) . "\n";
        echo '        ' . $error->getMessage() . ' (строка ' . $error->getLine() . ")\n";
    }
}

echo "\n";
echo "Проверено файлов: {$checked}\n";
echo $errors === 0 ? "Синтаксических ошибок нет\n" : "Найдено ошибок: {$errors}\n";

exit($errors === 0 ? 0 : 1);
