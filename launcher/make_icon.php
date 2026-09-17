<?php

declare(strict_types=1);

/**
 * Значок программы: зелёный квадрат со скруглёнными углами и белая буква «М».
 *
 * Запуск из каталога program:
 *   runtime\php\php.exe launcher\make_icon.php
 *
 * Результат — launcher\makrosych.ico с размерами 16, 32, 48, 64 и 256.
 * Буква нарисована контуром, а не шрифтом: значок не зависит от того, какие
 * шрифты установлены на машине сборки. Цвет — акцентный цвет интерфейса.
 */

const ACCENT = [0x2F, 0x6F, 0x4F];
const SIZES = [16, 32, 48, 64, 256];

/** Контур буквы «М» в поле 100×100. */
const LETTER = [
    [8, 88], [8, 12], [30, 12], [50, 52], [70, 12], [92, 12], [92, 88],
    [74, 88], [74, 36], [54, 76], [46, 76], [26, 36], [26, 88],
];

/** Рисует значок нужного размера на прозрачном фоне. */
function draw_icon(int $size): GdImage
{
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, false);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagealphablending($image, true);
    imagesavealpha($image, true);

    $green = imagecolorallocate($image, ACCENT[0], ACCENT[1], ACCENT[2]);
    $white = imagecolorallocate($image, 255, 255, 255);

    // Скруглённый квадрат: прямоугольник плюс четыре круга по углам
    $inset = (int) round($size * 0.04);
    $radius = (int) round($size * 0.18);
    $diameter = $radius * 2;
    $right = $size - 1 - $inset;
    $bottom = $size - 1 - $inset;

    imagefilledrectangle($image, $inset + $radius, $inset, $right - $radius, $bottom, $green);
    imagefilledrectangle($image, $inset, $inset + $radius, $right, $bottom - $radius, $green);
    foreach ([[$inset, $inset], [$right - $diameter, $inset], [$inset, $bottom - $diameter], [$right - $diameter, $bottom - $diameter]] as [$x, $y]) {
        imagefilledellipse($image, $x + $radius, $y + $radius, $diameter, $diameter, $green);
    }

    // Буква: контур вписывается в квадрат со всех сторон
    $box = $size * 0.72;
    $offset = ($size - $box) / 2;
    $points = [];
    foreach (LETTER as [$x, $y]) {
        $points[] = (int) round($offset + $x * $box / 100);
        $points[] = (int) round($offset + $y * $box / 100);
    }
    imagefilledpolygon($image, $points, $white);

    return $image;
}

/** Кадр в формате BMP (BITMAPINFOHEADER, 32 бита, снизу вверх) с маской прозрачности. */
function bmp_frame(GdImage $image, int $size): string
{
    $header = pack('VVVvvVVVVVV', 40, $size, $size * 2, 1, 32, 0, 0, 0, 0, 0, 0);

    $pixels = '';
    for ($y = $size - 1; $y >= 0; $y--) {
        for ($x = 0; $x < $size; $x++) {
            $rgba = imagecolorat($image, $x, $y);
            $alpha = 255 - (int) round((($rgba >> 24) & 0x7F) * 255 / 127);
            $pixels .= chr($rgba & 0xFF) . chr(($rgba >> 8) & 0xFF) . chr(($rgba >> 16) & 0xFF) . chr($alpha);
        }
    }

    // Маска: единица в бите — пиксель прозрачный (нужна старым оболочкам Windows)
    $rowBytes = (int) (ceil($size / 32) * 4);
    $mask = '';
    for ($y = $size - 1; $y >= 0; $y--) {
        $row = str_repeat("\x00", $rowBytes);
        for ($x = 0; $x < $size; $x++) {
            if ((imagecolorat($image, $x, $y) >> 24 & 0x7F) > 100) {
                $row[intdiv($x, 8)] = chr(ord($row[intdiv($x, 8)]) | (0x80 >> ($x % 8)));
            }
        }
        $mask .= $row;
    }

    return $header . $pixels . $mask;
}

/** Кадр в формате PNG — так 256×256 занимает килобайты вместо сотен. */
function png_frame(GdImage $image): string
{
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** Собирает файл .ico из кадров. */
function build_ico(array $frames): string
{
    $entries = '';
    $data = '';
    $offset = 6 + 16 * count($frames);

    foreach ($frames as $size => $blob) {
        $entries .= pack('CCCCvvVV', $size % 256, $size % 256, 0, 0, 1, 32, strlen($blob), $offset);
        $data .= $blob;
        $offset += strlen($blob);
    }

    return pack('vvv', 0, 1, count($frames)) . $entries . $data;
}

/** Текстовый предпросмотр: # — буква, + — фон, . — прозрачность. */
function preview(GdImage $image, int $size): string
{
    $step = $size / 16;
    $lines = [];

    for ($row = 0; $row < 16; $row++) {
        $line = '';
        for ($col = 0; $col < 16; $col++) {
            $rgba = imagecolorat($image, (int) (($col + 0.5) * $step), (int) (($row + 0.5) * $step));
            if ((($rgba >> 24) & 0x7F) > 100) {
                $line .= '.';
                continue;
            }
            $brightness = (($rgba >> 16 & 0xFF) + ($rgba >> 8 & 0xFF) + ($rgba & 0xFF)) / 3;
            $line .= $brightness > 140 ? '#' : '+';
        }
        $lines[] = $line;
    }

    return implode("\n", $lines);
}

$target = __DIR__ . '/makrosych.ico';
$frames = [];

foreach (SIZES as $size) {
    $image = draw_icon($size);
    $frames[$size] = $size >= 256 ? png_frame($image) : bmp_frame($image, $size);

    if ($size === 32) {
        echo "Предпросмотр 32×32 (буква — #, фон — +):\n\n" . preview($image, $size) . "\n\n";
    }

    imagedestroy($image);
}

file_put_contents($target, build_ico($frames));

$sizes = [];
foreach ($frames as $size => $blob) {
    $sizes[] = $size . 'px (' . number_format(strlen($blob), 0, ',', ' ') . ' Б)';
}

echo 'Значок собран: ' . $target . "\n";
echo 'Размер файла: ' . number_format((float) filesize($target), 0, ',', ' ') . " Б\n";
echo 'Кадры: ' . implode(', ', $sizes) . "\n";
