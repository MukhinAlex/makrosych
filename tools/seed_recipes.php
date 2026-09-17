<?php

declare(strict_types=1);

/**
 * Установка готовых сценариев в библиотеку приложения.
 *
 * Позволяет начать работу сразу, без настройки нейросети: сценарии уже собраны,
 * проверены на образцах и готовы к запуску на реальных файлах.
 *
 * Запуск: php tools/seed_recipes.php
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/fixtures.php';

use App\Engine\Excel;
use App\Lib\Paths;
use App\Lib\RecipeValidator;
use App\Lib\Runner;
use App\Lib\Store;

$paths = fixture_paths();

/**
 * Образец для сценария гиперссылок: A — код товара, B — наименование.
 * Формируется один раз, чтобы сценарий можно было проверить сразу после установки.
 */
function seed_hyperlink_sample(): string
{
    $path = Paths::path('samples', 'образец_коды_товаров.xlsx');
    if (is_file($path)) {
        return $path;
    }

    Paths::ensure(dirname($path));

    $book = Excel::newSpreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->setTitle('Товары');
    $sheet->setCellValue('A1', 'Прайс');
    $sheet->setCellValue('A2', 'Код');
    $sheet->setCellValue('B2', 'Номенклатура');

    $rows = [
        ['1800839', 'Мягкая игрушка «Кот» 30 см'],
        ['1410010', 'Кукла «Алиса»'],
        ['1710036', 'Конструктор «Город», 240 деталей'],
        ['1800840', 'Мягкая игрушка «Пёс» 45 см'],
    ];

    $number = 3;
    foreach ($rows as [$code, $name]) {
        $sheet->setCellValueExplicit('A' . $number, $code, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('B' . $number, $name);
        $number++;
    }

    Excel::save($book, $path);
    $book->disconnectWorksheets();

    return $path;
}

/**
 * Образец для сценария с фото: A — код, B — ссылка на фото, C — фото (пусто),
 * D — цена, E — количество, F — сумма (формула).
 * Картинка встроена в файл как data-ссылка, поэтому образец работает без интернета.
 */
function seed_image_sample(): string
{
    $path = Paths::path('samples', 'образец_карточки_товаров.xlsx');
    if (is_file($path)) {
        return $path;
    }

    Paths::ensure(dirname($path));

    $canvas = imagecreatetruecolor(60, 60);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 70, 130, 180));
    ob_start();
    imagepng($canvas);
    $binary = (string) ob_get_clean();
    imagedestroy($canvas);
    $photo = 'data:image/png;base64,' . base64_encode($binary);

    $book = Excel::newSpreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->setTitle('Товары');

    $titles = ['A1' => 'Код', 'B1' => 'Ссылка на фото', 'C1' => 'Фото', 'D1' => 'Цена',
        'E1' => 'Количество', 'F1' => 'Сумма'];
    foreach ($titles as $cell => $title) {
        $sheet->setCellValue($cell, $title);
    }

    $rows = [
        ['1800839', 1500, 3],
        ['1410010', 240, 10],
        ['1710036', 99, 7],
    ];

    $number = 2;
    foreach ($rows as [$code, $price, $qty]) {
        $sheet->setCellValueExplicit('A' . $number, $code, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('B' . $number, $photo);
        $sheet->setCellValue('D' . $number, $price);
        $sheet->setCellValue('E' . $number, $qty);
        $number++;
    }

    Excel::save($book, $path);
    $book->disconnectWorksheets();

    return $path;
}

/**
 * Образцы для сценария с ВПР: таблица товаров и прайс с теми же кодами.
 * В товарах есть код, которого нет в прайсе, — видно, как работает значение по умолчанию.
 *
 * @return array{goods: string, price: string}
 */
function seed_lookup_samples(): array
{
    $goods = Paths::path('samples', 'образец_товары_для_прайса.xlsx');
    $price = Paths::path('samples', 'образец_прайс.xlsx');
    Paths::ensure(dirname($goods));

    if (!is_file($price)) {
        $book = Excel::newSpreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Прайс');
        $sheet->setCellValue('A1', 'Код');
        $sheet->setCellValue('B1', 'Наименование');
        $sheet->setCellValue('C1', 'Цена');

        $rows = [
            ['1800839', 'Мягкая игрушка «Кот» 30 см', 1500],
            ['1410010', 'Кукла «Алиса»', 240],
            ['1710036', 'Конструктор «Город», 240 деталей', 99],
        ];

        $number = 2;
        foreach ($rows as [$code, $name, $cost]) {
            $sheet->setCellValueExplicit('A' . $number, $code, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('B' . $number, $name);
            $sheet->setCellValue('C' . $number, $cost);
            $number++;
        }

        Excel::save($book, $price);
        $book->disconnectWorksheets();
    }

    if (!is_file($goods)) {
        $book = Excel::newSpreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Товары');
        $sheet->setCellValue('A1', 'Код');
        $sheet->setCellValue('B1', 'Наименование');
        $sheet->setCellValue('C1', 'Цена');
        $sheet->setCellValue('D1', 'Количество');

        $rows = [
            ['1800839', 3],
            ['1410010', 10],
            ['1710036', 7],
            ['9999999', 1],
        ];

        $number = 2;
        foreach ($rows as [$code, $qty]) {
            $sheet->setCellValueExplicit('A' . $number, $code, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('D' . $number, $qty);
            $number++;
        }

        Excel::save($book, $goods);
        $book->disconnectWorksheets();
    }

    return ['goods' => $goods, 'price' => $price];
}

/**
 * Папка с фотографиями для сценария: имя файла — код товара, расширения разные,
 * чтобы поиск по маске «код.*» было на чём проверить.
 *
 * @param string[] $codes
 */
function seed_photo_folder(array $codes): void
{
    $folder = Paths::path('samples', 'фото');
    Paths::ensure($folder);

    $extensions = ['jpg', 'png', 'jpeg', 'JPG'];
    $colors = [[70, 130, 180], [180, 110, 70], [90, 160, 100], [150, 100, 170]];

    foreach (array_values($codes) as $index => $code) {
        $extension = $extensions[$index % count($extensions)];
        $path = $folder . '/' . $code . '.' . $extension;
        if (is_file($path)) {
            continue;
        }

        [$red, $green, $blue] = $colors[$index % count($colors)];
        $canvas = imagecreatetruecolor(400, 300);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, $red, $green, $blue));
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagestring($canvas, 5, 20, 130, 'Photo ' . $code, $white);

        if (strtolower($extension) === 'png') {
            imagepng($canvas, $path);
        } else {
            imagejpeg($canvas, $path, 88);
        }
        imagedestroy($canvas);
    }
}

/** @var array<int, array{recipe: array<string, mixed>, sample: string, tags: string[], inputs?: array<string, string>}> $definitions */
$definitions = [
    [
        'recipe' => fixture_links_recipe(),
        'sample' => $paths['links_original'],
        'tags' => ['ссылки', 'офлайн'],
    ],
    [
        'recipe' => fixture_hyperlink_recipe(),
        'sample' => seed_hyperlink_sample(),
        'tags' => ['гиперссылки', 'шаблон', 'офлайн'],
    ],
    [
        'recipe' => fixture_transfer_recipe(),
        'sample' => $paths['source'],
        'tags' => ['грузовые места', 'шаблон', 'офлайн'],
    ],
    [
        'recipe' => fixture_download_recipe(),
        'sample' => $paths['links'],
        'tags' => ['изображения', 'требует интернет'],
    ],
    [
        'recipe' => fixture_image_recipe(),
        'sample' => seed_image_sample(),
        'tags' => ['изображения', 'формулы', 'шаблон', 'офлайн'],
    ],
    [
        'recipe' => fixture_product_cards_recipe(),
        'sample' => seed_hyperlink_sample(),
        'tags' => ['изображения', 'гиперссылки', 'требует интернет'],
    ],
    [
        'recipe' => fixture_lookup_recipe(),
        'sample' => seed_lookup_samples()['goods'],
        'tags' => ['прайс', 'впр', 'два файла', 'офлайн'],
    ],
    [
        'recipe' => fixture_photo_folder_recipe(),
        'sample' => seed_hyperlink_sample(),
        'tags' => ['изображения', 'локальные файлы', 'гиперссылки', 'офлайн'],
    ],
];

// Для сценария с папкой фотографий готовим сами файлы: он ищет их по маске «код.*»
$photoCodes = ['1800839', '1410010', '1710036', '1800840'];
seed_photo_folder($photoCodes);

// Папка нужна проверке на образце, поэтому подставляем её в запуск
foreach ($definitions as $index => $definition) {
    if (($definition['recipe']['name'] ?? '') === 'Миниатюры и ссылки на фото из папки') {
        $definitions[$index]['inputs'] = ['input' => $definition['sample'], 'template' => $definition['sample']];
    }
    if (($definition['recipe']['name'] ?? '') === 'Наименования и цены из прайса (ВПР)') {
        $samples = seed_lookup_samples();
        $definitions[$index]['inputs'] = [
            'input' => $samples['goods'],
            'template' => $samples['goods'],
            'lookup' => $samples['price'],
        ];
    }
}

$installed = 0;

foreach ($definitions as $definition) {
    $recipe = $definition['recipe'];
    $name = (string) $recipe['name'];

    $validation = RecipeValidator::validate($recipe);
    if (!$validation['ok']) {
        echo "ПРОПУЩЕН «{$name}»: " . implode('; ', $validation['errors']) . "\n";
        continue;
    }

    $sample = $definition['sample'];
    if (!is_file($sample)) {
        echo "ПРОПУЩЕН «{$name}»: не найден образец {$sample}\n";
        continue;
    }

    // Проверка на образце до сохранения
    try {
        $check = Runner::execute($recipe, [
            'dry_run' => true,
            'inputs' => $definition['inputs'] ?? ['input' => $sample, 'template' => $sample],
        ]);
    } catch (Throwable $e) {
        echo "ПРОПУЩЕН «{$name}»: ошибка проверки — {$e->getMessage()}\n";
        continue;
    }

    if (!$check['ok']) {
        echo "ПРОПУЩЕН «{$name}»: " . (string) $check['error'] . "\n";
        continue;
    }

    // Обновляем существующий сценарий с тем же именем, иначе создаём новый
    $existingId = '';
    foreach (Store::list() as $entry) {
        if (($entry['name'] ?? '') === $name) {
            $existingId = (string) $entry['id'];
            break;
        }
    }

    // Дополнительные входные файлы (файл-справочник) сохраняются вместе со сценарием
    $extraSamples = [];
    foreach (($definition['inputs'] ?? []) as $alias => $path) {
        if (!in_array($alias, ['input', 'template'], true)) {
            $extraSamples[$alias] = $path;
        }
    }

    $saved = Store::save([
        'id' => $existingId,
        'name' => $name,
        'description' => (string) ($recipe['description'] ?? ''),
        'tags' => $definition['tags'],
        'recipe' => $recipe,
        'created_by_ai' => false,
        'provider' => '',
    ], $sample, [
        'summary' => $check['summary'],
        'preview_rows' => $check['preview']['rows'],
        'checked_at' => date('c'),
    ], $extraSamples);

    $mode = $existingId === '' ? 'добавлен' : 'обновлён';
    $summary = $check['summary'];
    echo "{$mode}: «{$name}» ({$saved['id']}) — строк: {$summary['rows']}, файлов: {$summary['files']}\n";
    $installed++;
}

echo "\nВсего сценариев в библиотеке: " . count(Store::list()) . "\n";
echo "Установлено/обновлено сейчас: {$installed}\n";
