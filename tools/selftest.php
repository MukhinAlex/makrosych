<?php

declare(strict_types=1);

/**
 * Сквозная проверка движка на реальных файлах проекта.
 *
 * Запуск: php tools/selftest.php
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/fixtures.php';

use App\Engine\Excel;
use App\Engine\FileSearch;
use App\Engine\Ops;
use App\Lib\Http;
use App\Lib\Paths;
use App\Lib\Profiler;
use App\Lib\RecipeValidator;
use App\Lib\Runner;
use App\Llm\Client;
use App\Llm\Prompts;

$paths = fixture_paths();
$parent = dirname(Paths::root());

$passed = 0;
$failed = 0;

function check(string $title, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [ок] {$title}\n";
    } else {
        $failed++;
        echo "  [ОШИБКА] {$title}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n== {$title} ==\n";
}

// ------------------------------------------------------------------ 1. Профиль

section('1. Профилировщик документа');

$sample = $paths['links'];
check('файл-образец найден', is_file($sample), $sample);

$profile = Profiler::profile($sample, ['sample_rows' => 2]);
check('строка заголовков определена', $profile['header_row'] === 1, 'получено: ' . $profile['header_row']);
check('начало данных определено (строка 6)', $profile['data_from_row'] === 6, 'получено: ' . $profile['data_from_row']);

$byLetter = [];
foreach ($profile['columns'] as $column) {
    $byLetter[$column['letter']] = $column;
}

check('колонка B распознана как артикул', isset($byLetter['B']) && $byLetter['B']['type'] === 'text');
check('колонка G распознана как ссылки', isset($byLetter['G']) && ($byLetter['G']['urls'] ?? 0) > 0,
    'ссылок: ' . ($byLetter['G']['urls'] ?? 0));
check('в колонке G найден разделитель-перенос строки',
    isset($byLetter['G']['separators']['перенос строки']));
check('число товаров в колонке B равно 33', ($byLetter['B']['filled'] ?? 0) === 33,
    'получено: ' . ($byLetter['B']['filled'] ?? 0));

// ------------------------------------------------------------------ 2. Проверка сценария

section('2. Валидатор сценария');

$downloadRecipe = fixture_download_recipe();

$validation = RecipeValidator::validate($downloadRecipe);
check('сценарий прошёл проверку', $validation['ok'], implode('; ', $validation['errors']));
check('распознано обращение к сети', $validation['uses_network'] === true);
check('нейросеть не используется', $validation['uses_ai'] === false);

$broken = $downloadRecipe;
$broken['steps'][] = ['op' => 'неизвестная_операция'];
check('неизвестная операция отклонена', RecipeValidator::validate($broken)['ok'] === false);

$incomplete = $downloadRecipe;
unset($incomplete['steps'][0]['columns']);
check('отсутствие обязательного параметра отклонено', RecipeValidator::validate($incomplete)['ok'] === false);

// ------------------------------------------------------------------ 3. Проверка на образце

section('3. Проверка сценария на образце (файлы не создаются)');

$dryRun = Runner::execute($downloadRecipe, [
    'dry_run' => true,
    'inputs' => ['input' => $sample, 'template' => $sample],
]);

check('проверка прошла без ошибок', $dryRun['ok'], (string) $dryRun['error']);
check('найдено больше 33 ссылок', $dryRun['preview']['planned_total'] > 33,
    'запланировано: ' . $dryRun['preview']['planned_total']);
check('папки сформированы по артикулам',
    str_contains($dryRun['preview']['planned'][0]['path'] ?? '', '/'),
    $dryRun['preview']['planned'][0]['path'] ?? 'нет данных');

$plannedPaths = array_column($dryRun['preview']['planned'], 'path');
check('имена файлов уникальны', count($plannedPaths) === count(array_unique($plannedPaths)));

$createdInDryRun = glob($dryRun['out_dir'] . '/*');
check('в режиме проверки файлы не создаются', $createdInDryRun === []);

// ------------------------------------------------------------------ 4. Реальная обработка

section('4. Заполнение шаблона (аналог transfer_data_v5.php)');

$source = $paths['source'];
$template = $paths['template'];

check('исходник найден', is_file($source), $source);
check('шаблон найден', is_file($template), $template);

$transferRecipe = fixture_transfer_recipe();

$validation = RecipeValidator::validate($transferRecipe);
check('сценарий переноса прошёл проверку', $validation['ok'], implode('; ', $validation['errors']));

$result = Runner::execute($transferRecipe, [
    'inputs' => ['input' => $source, 'template' => $template],
    'params' => ['start_row' => 2],
]);

check('обработка выполнена', $result['ok'], (string) $result['error']);
check('подставлен параметр start_row', ($result['params']['start_row'] ?? null) === 2);

$output = $result['out_dir'] . '/заполненный.xlsx';
check('файл результата создан', is_file($output), $output);

if (is_file($output)) {
    $produced = Excel::load($output, true);
    $sheet = $produced->getSheet(0);

    $filled = 0;
    $sampleRow = null;
    for ($row = 5; $row <= $sheet->getHighestDataRow(); $row++) {
        $value = Excel::text($sheet->getCell('F' . $row)->getValue());
        if ($value !== '') {
            $filled++;
            $sampleRow ??= $row;
        }
    }

    check('в шаблоне заполнены строки', $filled > 0, 'заполнено: ' . $filled);

    if ($sampleRow !== null) {
        echo '       пример строки ' . $sampleRow . ': артикул=' . Excel::text($sheet->getCell('B' . $sampleRow)->getValue())
            . ', длина=' . Excel::text($sheet->getCell('I' . $sampleRow)->getValue())
            . ', вес=' . Excel::text($sheet->getCell('K' . $sampleRow)->getValue()) . "\n";
    }

    $produced->disconnectWorksheets();
}

// Сверка с результатом существующего скрипта
$reference = $paths['reference'];
if (is_file($reference) && is_file($output)) {
    $expected = Excel::load($reference, true)->getSheet(0);
    $actual = Excel::load($output, true)->getSheet(0);

    $differences = [];
    for ($row = 5; $row <= max($expected->getHighestDataRow(), $actual->getHighestDataRow()); $row++) {
        foreach (['F', 'H', 'I', 'J', 'K'] as $letter) {
            $left = Excel::text($expected->getCell($letter . $row)->getValue());
            $right = Excel::text($actual->getCell($letter . $row)->getValue());
            if ($left !== $right) {
                $differences[] = "{$letter}{$row}: было «{$left}», стало «{$right}»";
            }
        }
    }

    check('результат совпадает с результатом существующего скрипта', $differences === [],
        count($differences) . ' расхождений, например: ' . implode(' | ', array_slice($differences, 0, 3)));
}

// ------------------------------------------------------------------ 5. Ссылки через перенос строки

section('5. Ссылки в одной ячейке через перенос строки (files/6.xlsx)');

$linksOriginal = $paths['links_original'];
$linksExpected = $paths['links_newline'];

check('исходный файл сохранён', is_file($linksOriginal), $linksOriginal);
check('файл, обработанный обычным скриптом, есть', is_file($linksExpected), $linksExpected);

if (is_file($linksOriginal)) {
    $linksRecipe = fixture_links_recipe();
    $validation = RecipeValidator::validate($linksRecipe);
    check('сценарий ссылок прошёл проверку', $validation['ok'], implode('; ', $validation['errors']));
    check('сценарий ссылок не требует сети', $validation['uses_network'] === false);

    $result = Runner::execute($linksRecipe, [
        'inputs' => ['input' => $linksOriginal, 'template' => $linksOriginal],
    ]);
    check('обработка выполнена', $result['ok'], (string) $result['error']);

    $linksOutput = $result['out_dir'] . '/6_результат.xlsx';
    check('файл результата создан', is_file($linksOutput), $linksOutput);

    if (is_file($linksOutput)) {
        $produced = Excel::load($linksOutput, false);
        $sheet = $produced->getSheet(0);

        $cellsWithNewline = 0;
        $linksTotal = 0;
        $wrapOk = 0;

        for ($row = 6; $row <= $sheet->getHighestDataRow(); $row++) {
            $value = Excel::text($sheet->getCell('F' . $row)->getValue());
            if ($value === '') {
                continue;
            }

            if (str_contains($value, "\n")) {
                $cellsWithNewline++;
                $linksTotal += count(explode("\n", $value));
            }

            if ($sheet->getStyle('F' . $row)->getAlignment()->getWrapText()) {
                $wrapOk++;
            }
        }

        check('в ячейках появился перенос строки', $cellsWithNewline === 53, 'ячеек: ' . $cellsWithNewline);
        check('ссылок собрано 181', $linksTotal === 181, 'ссылок: ' . $linksTotal);
        check('включён перенос текста во всех ячейках', $wrapOk === 53, 'ячеек: ' . $wrapOk);
        check('в ячейке F6 четыре ссылки',
            count(explode("\n", Excel::text($sheet->getCell('F6')->getValue()))) === 4);

        $produced->disconnectWorksheets();

        // Сверка с результатом обычного скрипта (файл обработан на месте)
        if (is_file($linksExpected)) {
            $expectedSheet = Excel::load($linksExpected, false)->getSheet(0);
            $actualSheet = Excel::load($linksOutput, false)->getSheet(0);

            $differences = [];
            for ($row = 2; $row <= max($expectedSheet->getHighestDataRow(), $actualSheet->getHighestDataRow()); $row++) {
                $left = Excel::text($expectedSheet->getCell('F' . $row)->getValue());
                $right = Excel::text($actualSheet->getCell('F' . $row)->getValue());
                if ($left !== $right) {
                    $differences[] = "F{$row}";
                }
            }

            check('результат совпадает с обычным скриптом', $differences === [],
                count($differences) . ' расхождений: ' . implode(', ', array_slice($differences, 0, 5)));
        }
    }
}

// ------------------------------------------------------------------ 5б. Особенности ответов модели

section('5б. Приведение ответов модели к рабочему виду');

// Модели часто вкладывают параметры шага в объект "params"
$nestedRecipe = fixture_links_recipe();
$nestedRecipe['steps'] = [
    ['op' => 'read_rows', 'params' => [
        'file' => 'input', 'data_from_row' => 6,
        'columns' => ['key' => 'B', 'links' => 'F'], 'multi' => 'first',
    ]],
    ['op' => 'write_cells', 'params' => [
        'template' => 'template', 'data_from_row' => 6,
        'match' => ['column' => 'B', 'field' => 'key'], 'cells' => ['F' => 'links'],
        'output' => 'вложенный.xlsx',
    ]],
];

$validation = RecipeValidator::validate($nestedRecipe);
check('вложенный объект "params" принимается', $validation['ok'], implode('; ', $validation['errors']));
check('после приведения параметры видны на верхнем уровне',
    isset(RecipeValidator::normalize($nestedRecipe)['steps'][0]['file']));

// Модель записала перенос строки как два символа «обратный слэш + n».
// Проверяем оба способа замены: replace и regex_replace.
if (is_file($linksOriginal)) {
    $variants = [
        'replace' => ['trim' => true, 'replace' => ['from' => ';', 'to' => '\\n']],
        'regex_replace' => ['trim' => true, 'regex_replace' => ['pattern' => '~;~', 'replacement' => '\\n']],
    ];

    foreach ($variants as $name => $transform) {
        $literalRecipe = fixture_links_recipe();
        $literalRecipe['name'] = 'Ссылки (' . $name . ' с литеральным \n)';
        $literalRecipe['steps'][1]['transform'] = $transform;
        $literalRecipe['steps'][2]['output'] = '6_результат_' . $name . '.xlsx';

        $result = Runner::execute($literalRecipe, [
            'inputs' => ['input' => $linksOriginal, 'template' => $linksOriginal],
        ]);

        check("сценарий с «\\n» через {$name} выполнен", $result['ok'], (string) $result['error']);

        $literalOutput = $result['out_dir'] . '/6_результат_' . $name . '.xlsx';
        if (!is_file($literalOutput)) {
            continue;
        }

        $sheet = Excel::load($literalOutput, false)->getSheet(0);
        $value = Excel::text($sheet->getCell('F6')->getValue());

        check("{$name}: «\\n» превратился в настоящий перенос строки",
            str_contains($value, "\n") && !str_contains($value, '\\n'),
            'значение: ' . mb_substr(str_replace("\n", '⏎', $value), 0, 70));
        check("{$name}: в ячейке F6 четыре ссылки", count(explode("\n", $value)) === 4,
            'ссылок: ' . count(explode("\n", $value)));
    }
}

// ------------------------------------------------------------------ 6. Гиперссылки

section('6. Активные гиперссылки в шаблоне');

// Готовим файл с той же структурой, что у пользователя: A — код, B — наименование
$hyperlinkSample = Paths::tmpDir('hyperlinks') . '/коды.xlsx';
Paths::ensure(dirname($hyperlinkSample));

$book = Excel::newSpreadsheet();
$bookSheet = $book->getActiveSheet();
$bookSheet->setTitle('Sheet1');
$bookSheet->setCellValue('A1', 'Прайс');
$bookSheet->setCellValue('A2', 'Код');
$bookSheet->setCellValue('B2', 'Номенклатура');

$sampleData = [
    ['1800839', 'Мягкая игрушка «Кот» 30 см'],
    ['1410010', 'Кукла «Алиса»'],
    ['1710036', 'Конструктор «Город», 240 деталей'],
    ['1800840', 'Мягкая игрушка «Пёс» 45 см'],
];

$sampleRow = 3;
foreach ($sampleData as [$code, $name]) {
    $bookSheet->setCellValueExplicit('A' . $sampleRow, $code, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $bookSheet->setCellValue('B' . $sampleRow, $name);
    $sampleRow++;
}

Excel::save($book, $hyperlinkSample);
$book->disconnectWorksheets();

check('тестовый файл подготовлен', is_file($hyperlinkSample));

$hyperlinkRecipe = fixture_hyperlink_recipe();
$validation = RecipeValidator::validate($hyperlinkRecipe);
check('сценарий гиперссылок прошёл проверку', $validation['ok'], implode('; ', $validation['errors']));
check('сценарий гиперссылок не требует сети', $validation['uses_network'] === false);

$result = Runner::execute($hyperlinkRecipe, [
    'inputs' => ['input' => $hyperlinkSample, 'template' => $hyperlinkSample],
]);
check('обработка выполнена', $result['ok'], (string) $result['error']);

$hyperlinkOutput = $result['out_dir'] . '/ссылки.xlsx';
check('файл результата создан', is_file($hyperlinkOutput), $hyperlinkOutput);

if (is_file($hyperlinkOutput)) {
    $sheet = Excel::load($hyperlinkOutput, false)->getSheet(0);

    $link = $sheet->getCell('B3')->getHyperlink()->getUrl();
    $text = Excel::text($sheet->getCell('B3')->getValue());

    check('ссылка в B3 ведёт на карточку товара',
        $link === 'https://игрушкиоптом.рф/catalog/1800839', "получено: {$link}");
    check('текст ячейки B3 не изменён',
        $text === 'Мягкая игрушка «Кот» 30 см', "получено: {$text}");

    $linksCount = 0;
    for ($row = 3; $row <= 6; $row++) {
        if ($sheet->getCell('B' . $row)->getHyperlink()->getUrl() !== '') {
            $linksCount++;
        }
    }

    check('ссылки поставлены во все четыре строки', $linksCount === 4, "ссылок: {$linksCount}");
    check('в статистике отражено число ссылок',
        (int) ($result['summary']['stats']['ссылок поставлено'] ?? 0) === 4,
        'получено: ' . ($result['summary']['stats']['ссылок поставлено'] ?? 0));
}

// Ссылка с видимым текстом и ссылка на файл на диске: текст пишется до ссылки,
// иначе установка значения снимает только что поставленную ссылку (так же ведёт себя Excel).
$linkTextRecipe = [
    'schema' => RecipeValidator::SCHEMA,
    'name' => 'Ссылки с текстом и на файлы',
    'steps' => [
        ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 3,
            'columns' => ['code' => 'A'], 'multi' => 'first', 'skip_if_empty' => ['code']],
        ['op' => 'write_cells', 'template' => 'template', 'sheet' => 1, 'data_from_row' => 3,
            'match' => ['column' => 'A', 'field' => 'code'],
            'hyperlinks' => [
                'C' => ['template' => 'https://игрушкиоптом.рф/catalog/{code}', 'keep_text' => false, 'text' => 'открыть'],
                'D' => ['template' => 'file:///C:/нет-такой-папки/{code}.jpg', 'keep_text' => false, 'text' => 'фото'],
            ],
            'output' => 'ссылки-с-текстом.xlsx'],
    ],
];

$linkTextResult = Runner::execute($linkTextRecipe, [
    'inputs' => ['input' => $hyperlinkSample, 'template' => $hyperlinkSample],
]);
check('сценарий ссылок с видимым текстом выполнен', $linkTextResult['ok'], (string) $linkTextResult['error']);

$linkTextOutput = $linkTextResult['out_dir'] . '/ссылки-с-текстом.xlsx';
check('файл ссылок с текстом создан', is_file($linkTextOutput), $linkTextOutput);

if (is_file($linkTextOutput)) {
    $sheet = Excel::load($linkTextOutput, false)->getSheet(0);
    $textLink = (string) $sheet->getCell('C3')->getHyperlink()->getUrl();

    check('текст не стирает поставленную ссылку (keep_text: false)',
        $textLink === 'https://игрушкиоптом.рф/catalog/1800839', "получено: {$textLink}");
    check('видимый текст ссылки записан в ячейку',
        Excel::text($sheet->getCell('C3')->getValue()) === 'открыть',
        Excel::text($sheet->getCell('C3')->getValue()));
    check('ссылка на файл на диске поставлена',
        str_starts_with((string) $sheet->getCell('D3')->getHyperlink()->getUrl(), 'file:///'),
        (string) $sheet->getCell('D3')->getHyperlink()->getUrl());
}

check('отсутствующий файл ссылки виден в журнале',
    str_contains(implode(' | ', array_column($linkTextResult['logs'], 'message')), 'Файл для ссылки в D3 не найден'),
    implode(' | ', array_column($linkTextResult['logs'], 'message')));

// ------------------------------------------------------------------ 7. Формулы и изображения

section('7. Формулы Excel и изображения');

// Книга: A — код, B — цена, C — количество, D и E — под формулы
$formulaSample = Paths::tmpDir('formulas') . '/товары.xlsx';
Paths::ensure(dirname($formulaSample));

$book = Excel::newSpreadsheet();
$bookSheet = $book->getActiveSheet();
foreach (['A1' => 'Код', 'B1' => 'Цена', 'C1' => 'Количество', 'D1' => 'Сумма', 'E1' => 'Сложение'] as $cell => $title) {
    $bookSheet->setCellValue($cell, $title);
}

$formulaRows = [
    ['1001', 1500, 3],
    ['1002', 240, 10],
    ['1003', 99, 7],
];

$formulaRow = 2;
foreach ($formulaRows as [$code, $price, $qty]) {
    $bookSheet->setCellValueExplicit('A' . $formulaRow, $code, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $bookSheet->setCellValue('B' . $formulaRow, $price);
    $bookSheet->setCellValue('C' . $formulaRow, $qty);
    $formulaRow++;
}

Excel::save($book, $formulaSample);
$book->disconnectWorksheets();

check('книга для проверки формул подготовлена', is_file($formulaSample));

$formulaRecipe = fixture_formula_recipe();
$validation = RecipeValidator::validate($formulaRecipe);
check('сценарий с формулами прошёл проверку', $validation['ok'], implode('; ', $validation['errors']));
check('сценарий с формулами не требует сети', $validation['uses_network'] === false);

$result = Runner::execute($formulaRecipe, [
    'inputs' => ['input' => $formulaSample, 'template' => $formulaSample],
]);
check('сценарий с формулами выполнен', $result['ok'], (string) $result['error']);

$formulaOutput = $result['out_dir'] . '/формулы.xlsx';
check('файл с формулами создан', is_file($formulaOutput), $formulaOutput);

if (is_file($formulaOutput)) {
    $sheet = Excel::load($formulaOutput, false)->getSheet(0);

    check('формула записана в строку своего товара',
        Excel::text($sheet->getCell('D2')->getValue()) === '=B2*C2',
        'получено: ' . Excel::text($sheet->getCell('D2')->getValue()));
    check('формула смещается вместе со строкой шаблона',
        Excel::text($sheet->getCell('D4')->getValue()) === '=B4*C4',
        'получено: ' . Excel::text($sheet->getCell('D4')->getValue()));
    check('Excel считает значение по формуле',
        (float) $sheet->getCell('D2')->getCalculatedValue() === 4500.0,
        'получено: ' . $sheet->getCell('D2')->getCalculatedValue());
    check('подстановка {col:поле} дала буквы колонок',
        Excel::text($sheet->getCell('E3')->getValue()) === '=B3+C3',
        'получено: ' . Excel::text($sheet->getCell('E3')->getValue()));
    check('в статистике отражено число формул',
        (int) ($result['summary']['stats']['формул записано'] ?? 0) === 6,
        'получено: ' . ($result['summary']['stats']['формул записано'] ?? 0));
}

// Новый файл: формула в колонке и итоговая строка
$totalsRecipe = [
    'schema' => RecipeValidator::SCHEMA,
    'name' => 'Результат с формулами и итогом',
    'description' => 'Создаёт новый файл с суммой по строке и итоговой строкой',
    'input' => ['type' => 'xlsx', 'sheet' => 1, 'header_rows' => [1], 'data_from_row' => 2],
    'steps' => [
        ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
            'columns' => ['code' => 'A', 'price' => 'B', 'qty' => 'C'], 'multi' => 'first'],
        ['op' => 'write_new_sheet', 'output' => 'итог.xlsx', 'columns' => [
            ['column' => 'A', 'title' => 'Код', 'field' => 'code'],
            ['column' => 'B', 'title' => 'Цена', 'field' => 'price'],
            ['column' => 'C', 'title' => 'Количество', 'field' => 'qty'],
            ['column' => 'D', 'title' => 'Сумма', 'formula' => '=B{row}*C{row}'],
        ], 'totals' => [
            ['column' => 'C', 'label' => 'Итого'],
            ['column' => 'D', 'formula' => '=SUM(D2:D{last})'],
        ]],
    ],
];

$result = Runner::execute($totalsRecipe, ['inputs' => ['input' => $formulaSample]]);
check('сценарий с итоговой строкой выполнен', $result['ok'], (string) $result['error']);

$totalsOutput = $result['out_dir'] . '/итог.xlsx';
check('файл с итогом создан', is_file($totalsOutput), $totalsOutput);

if (is_file($totalsOutput)) {
    $sheet = Excel::load($totalsOutput, false)->getSheet(0);

    check('в новом файле формула по строке',
        Excel::text($sheet->getCell('D2')->getValue()) === '=B2*C2',
        'получено: ' . Excel::text($sheet->getCell('D2')->getValue()));
    check('итоговая строка под данными', Excel::text($sheet->getCell('C5')->getValue()) === 'Итого',
        'получено: ' . Excel::text($sheet->getCell('C5')->getValue()));
    check('формула итога охватывает все строки данных',
        Excel::text($sheet->getCell('D5')->getValue()) === '=SUM(D2:D4)',
        'получено: ' . Excel::text($sheet->getCell('D5')->getValue()));
    check('Excel считает итог', (float) $sheet->getCell('D5')->getCalculatedValue() === 7593.0,
        'получено: ' . $sheet->getCell('D5')->getCalculatedValue());
    check('в статистике учтены формулы строк и итога',
        (int) ($result['summary']['stats']['формул записано'] ?? 0) === 4,
        'получено: ' . ($result['summary']['stats']['формул записано'] ?? 0));
}

// Вставка изображений: готовим картинку и книгу со ссылками на неё
$imageDir = Paths::tmpDir('images');
Paths::ensure($imageDir);
$imagePath = $imageDir . '/фото.png';

$canvas = imagecreatetruecolor(40, 20);
imagefill($canvas, 0, 0, imagecolorallocate($canvas, 200, 30, 30));
imagepng($canvas, $imagePath);
imagedestroy($canvas);

check('тестовое изображение подготовлено', is_file($imagePath));

$imageSample = $imageDir . '/товары_с_фото.xlsx';
$book = Excel::newSpreadsheet();
$bookSheet = $book->getActiveSheet();
$bookSheet->setCellValue('A1', 'Код');
$bookSheet->setCellValue('B1', 'Ссылка на фото');
$bookSheet->setCellValue('C1', 'Фото');
$bookSheet->setCellValue('D1', 'Цена');
$bookSheet->setCellValue('E1', 'Количество');
$bookSheet->setCellValue('F1', 'Сумма');

$imageRow = 2;
foreach (['2001' => [1500, 2], '2002' => [240, 5]] as $code => $numbers) {
    $bookSheet->setCellValueExplicit('A' . $imageRow, (string) $code, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $bookSheet->setCellValue('B' . $imageRow, $imagePath);
    $bookSheet->setCellValue('D' . $imageRow, $numbers[0]);
    $bookSheet->setCellValue('E' . $imageRow, $numbers[1]);
    $imageRow++;
}

Excel::save($book, $imageSample);
$book->disconnectWorksheets();

$imageRecipe = fixture_image_recipe();
$validation = RecipeValidator::validate($imageRecipe);
check('сценарий с изображениями прошёл проверку', $validation['ok'], implode('; ', $validation['errors']));
check('сценарий с изображениями помечен как требующий сети', $validation['uses_network'] === true);

$dry = Runner::execute($imageRecipe, [
    'inputs' => ['input' => $imageSample, 'template' => $imageSample],
    'dry_run' => true,
]);
check('проверка сценария с изображениями проходит', $dry['ok'], (string) $dry['error']);
check('в проверке учтены оба изображения',
    (int) ($dry['summary']['stats']['изображений вставлено'] ?? 0) === 2,
    'получено: ' . ($dry['summary']['stats']['изображений вставлено'] ?? 0));

$result = Runner::execute($imageRecipe, [
    'inputs' => ['input' => $imageSample, 'template' => $imageSample],
]);
check('сценарий с изображениями выполнен', $result['ok'], (string) $result['error']);

$imageOutput = $result['out_dir'] . '/карточки.xlsx';
check('файл с изображениями создан', is_file($imageOutput), $imageOutput);

if (is_file($imageOutput)) {
    $sheet = Excel::load($imageOutput, false)->getSheet(0);
    $drawings = $sheet->getDrawingCollection();

    check('в файле две картинки', count($drawings) === 2, 'найдено: ' . count($drawings));

    $coordinates = [];
    foreach ($drawings as $drawing) {
        $coordinates[] = $drawing->getCoordinates();
    }
    sort($coordinates);

    check('картинки привязаны к строкам своих товаров', $coordinates === ['C2', 'C3'], implode(', ', $coordinates));
    check('вместе с картинками записаны значения',
        Excel::text($sheet->getCell('D2')->getValue()) === '1500' && Excel::text($sheet->getCell('E3')->getValue()) === '5',
        'D2: ' . Excel::text($sheet->getCell('D2')->getValue()) . ', E3: ' . Excel::text($sheet->getCell('E3')->getValue()));
    check('формула рядом с картинкой',
        Excel::text($sheet->getCell('F2')->getValue()) === '=D2*E2',
        'получено: ' . Excel::text($sheet->getCell('F2')->getValue()));
    check('Excel считает сумму в файле с картинками',
        (float) $sheet->getCell('F2')->getCalculatedValue() === 3000.0,
        'получено: ' . $sheet->getCell('F2')->getCalculatedValue());
    check('в статистике отражено число изображений',
        (int) ($result['summary']['stats']['изображений вставлено'] ?? 0) === 2,
        'получено: ' . ($result['summary']['stats']['изображений вставлено'] ?? 0));
    check('в статистике отражены формулы файла с картинками',
        (int) ($result['summary']['stats']['формул записано'] ?? 0) === 2,
        'получено: ' . ($result['summary']['stats']['формул записано'] ?? 0));
}

// Вписывание по размеру ячейки: картинка уменьшается, а не выходит за границы строки
$fitRecipe = fixture_image_recipe();
$fitRecipe['name'] = 'Карточки с вписыванием по ячейке';
$fitRecipe['steps'][1]['images'] = ['C' => ['url_field' => 'photo', 'height' => 200]];
$fitRecipe['steps'][1]['fit'] = 'cell';
$fitRecipe['steps'][1]['output'] = 'карточки_по_ячейке.xlsx';

$result = Runner::execute($fitRecipe, [
    'inputs' => ['input' => $imageSample, 'template' => $imageSample],
]);
check('сценарий с вписыванием по ячейке выполнен', $result['ok'], (string) $result['error']);

$fitOutput = $result['out_dir'] . '/карточки_по_ячейке.xlsx';
if (is_file($fitOutput)) {
    $fitSheet = Excel::load($fitOutput, false)->getSheet(0);
    $heights = [];
    $widths = [];
    foreach ($fitSheet->getDrawingCollection() as $drawing) {
        $heights[] = $drawing->getHeight();
        $widths[] = $drawing->getWidth();
    }

    // Строка по умолчанию 15 пунктов = 20 пикселей, минус отступы по 2 — остаётся 16
    check('картинки вписаны в высоту ячейки', $heights === [16, 16], implode(', ', $heights));
    check('картинки уменьшены по пропорции, а не растянуты',
        $widths !== [] && max($widths) < 40, implode(', ', $widths));
}

// Вставка новой колонки между кодом и номенклатурой: фото в новую колонку, ссылка на номенклатуру
$shiftSample = $imageDir . '/товары_с_номенклатурой.xlsx';
$book = Excel::newSpreadsheet();
$bookSheet = $book->getActiveSheet();
$bookSheet->setCellValue('A1', 'Прайс');
$bookSheet->setCellValue('A2', 'Код');
$bookSheet->setCellValue('B2', 'Номенклатура');
$bookSheet->setCellValue('C2', 'Остаток');
$bookSheet->getStyle('A2:C2')->getFont()->setBold(true);
$bookSheet->getStyle('A2:C2')->getFill()
    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFDDEBF7');

$shiftRows = [
    ['1800839', 'Мягкая игрушка «Кот» 30 см', 89],
    ['1410010', 'Кукла «Алиса»', 72],
];

$shiftRow = 3;
foreach ($shiftRows as [$code, $name, $rest]) {
    // Картинки для проверки шаблона адреса: имя файла — код товара
    $canvas = imagecreatetruecolor(30, 30);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 120, 160, 90));
    imagepng($canvas, $imageDir . '/' . $code . '.png');
    imagedestroy($canvas);

    $bookSheet->setCellValueExplicit('A' . $shiftRow, $code, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $bookSheet->setCellValue('B' . $shiftRow, $name);
    $bookSheet->setCellValue('C' . $shiftRow, $rest);
    $shiftRow++;
}

Excel::save($book, $shiftSample);
$book->disconnectWorksheets();

$shiftRecipe = [
    'schema' => RecipeValidator::SCHEMA,
    'name' => 'Фото и ссылка в новой колонке',
    'description' => 'Вставляет колонку «Изображение» между кодом и номенклатурой, ставит фото и гиперссылку',
    'input' => ['type' => 'xlsx', 'sheet' => 1, 'header_rows' => [2], 'data_from_row' => 3],
    'steps' => [
        ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 3,
            'columns' => ['code' => 'A', 'name' => 'B', 'rest' => 'C'], 'multi' => 'first',
            'skip_if_empty' => ['code']],
        ['op' => 'insert_images', 'template' => 'template', 'sheet' => 1, 'data_from_row' => 3,
            'match' => ['column' => 'A', 'field' => 'code'],
            'insert_columns' => ['B' => 'Изображение'], 'header_row' => 2,
            'images' => ['B' => ['template' => $imageDir . '/{code}.png', 'height' => 30]],
            'hyperlinks' => ['C' => ['template' => 'https://игрушкиоптом.рф/catalog/{code}', 'keep_text' => true]],
            'output' => 'карточки_со_ссылкой.xlsx'],
    ],
];

$result = Runner::execute($shiftRecipe, [
    'inputs' => ['input' => $shiftSample, 'template' => $shiftSample],
]);
check('сценарий со вставкой колонки выполнен', $result['ok'], (string) $result['error']);

$shiftOutput = $result['out_dir'] . '/карточки_со_ссылкой.xlsx';
check('файл со вставленной колонкой создан', is_file($shiftOutput), $shiftOutput);

if (is_file($shiftOutput)) {
    $sheet = Excel::load($shiftOutput, false)->getSheet(0);

    check('новая колонка появилась между кодом и номенклатурой',
        Excel::text($sheet->getCell('B2')->getValue()) === 'Изображение'
        && Excel::text($sheet->getCell('C2')->getValue()) === 'Номенклатура'
        && Excel::text($sheet->getCell('D2')->getValue()) === 'Остаток',
        'заголовки: ' . Excel::text($sheet->getCell('B2')->getValue()) . ' / '
        . Excel::text($sheet->getCell('C2')->getValue()) . ' / '
        . Excel::text($sheet->getCell('D2')->getValue()));

    check('оформление заголовков перенесено в новую колонку',
        $sheet->getStyle('B2')->getFont()->getBold() === true
        && $sheet->getStyle('B2')->getFill()->getStartColor()->getARGB() === 'FFDDEBF7');

    check('текст номенклатуры сохранился после сдвига',
        Excel::text($sheet->getCell('C3')->getValue()) === 'Мягкая игрушка «Кот» 30 см',
        'получено: ' . Excel::text($sheet->getCell('C3')->getValue()));

    check('данные соседних колонок сдвинулись вместе с заголовками',
        Excel::text($sheet->getCell('D3')->getValue()) === '89',
        'получено: ' . Excel::text($sheet->getCell('D3')->getValue()));

    $shiftLink = $sheet->getCell('C3')->getHyperlink()->getUrl();
    check('гиперссылка поставлена на сдвинутую номенклатуру',
        $shiftLink === 'https://игрушкиоптом.рф/catalog/1800839', "получено: {$shiftLink}");
    check('текст ячейки со ссылкой не изменён',
        Excel::text($sheet->getCell('C3')->getValue()) === 'Мягкая игрушка «Кот» 30 см');

    $shiftDrawings = [];
    foreach ($sheet->getDrawingCollection() as $drawing) {
        $shiftDrawings[] = $drawing->getCoordinates();
    }
    sort($shiftDrawings);
    check('фото вставлены в новую колонку', $shiftDrawings === ['B3', 'B4'], implode(', ', $shiftDrawings));

    $stats = $result['summary']['stats'];
    check('в статистике учтены вставка колонки, фото и ссылки',
        (int) ($stats['колонок вставлено'] ?? 0) === 1
        && (int) ($stats['изображений вставлено'] ?? 0) === 2
        && (int) ($stats['ссылок поставлено'] ?? 0) === 2,
        json_encode($stats, JSON_UNESCAPED_UNICODE));
}

// Разные пропорции: строка растёт под фото, колонка расширяется по ширине фото
$ratioDir = $imageDir . '/пропорции';
Paths::ensure($ratioDir);

$ratioSample = $ratioDir . '/товары.xlsx';
$book = Excel::newSpreadsheet();
$bookSheet = $book->getActiveSheet();
$bookSheet->setCellValue('A1', 'Код');
$bookSheet->setCellValue('B1', 'Фото 1');
$bookSheet->setCellValue('C1', 'Номенклатура');
$bookSheet->setCellValue('D1', 'Фото 2');

// Колонка B уже широкая, колонка D — обычная
$bookSheet->getColumnDimension('B')->setWidth(50);
// Строка 2 уже высокая — уменьшать её нельзя
$bookSheet->getRowDimension(2)->setRowHeight(300);

$ratioImages = [
    ['3:4', 300, 400],
    ['16:9', 320, 180],
];

$ratioRow = 2;
foreach ($ratioImages as [$ratio, $width, $height]) {
    $file = $ratioDir . '/' . str_replace(':', '-', $ratio) . '.png';
    $canvas = imagecreatetruecolor($width, $height);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 90, 120, 200));
    imagepng($canvas, $file);
    imagedestroy($canvas);

    $bookSheet->setCellValueExplicit('A' . $ratioRow, 'код-' . $ratioRow, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $bookSheet->setCellValue('B' . $ratioRow, $file);
    $bookSheet->setCellValue('C' . $ratioRow, 'Товар ' . $ratioRow);
    $bookSheet->setCellValue('D' . $ratioRow, $file);
    $ratioRow++;
}

Excel::save($book, $ratioSample);
$book->disconnectWorksheets();

$ratioRecipe = [
    'schema' => RecipeValidator::SCHEMA,
    'name' => 'Фото разной пропорции',
    'description' => 'Вставляет фото 3:4 и 16:9 одной высоты, расширяя строку и колонку',
    'input' => ['type' => 'xlsx', 'sheet' => 1, 'header_rows' => [1], 'data_from_row' => 2],
    'steps' => [
        ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
            'columns' => ['code' => 'A', 'photo' => 'B'], 'multi' => 'first', 'skip_if_empty' => ['code']],
        ['op' => 'insert_images', 'template' => 'template', 'sheet' => 1, 'data_from_row' => 2,
            'match' => ['column' => 'A', 'field' => 'code'],
            'images' => ['B' => ['url_field' => 'photo'], 'D' => ['url_field' => 'photo']],
            'height' => 100, 'output' => 'фото_пропорции.xlsx'],
    ],
];

$result = Runner::execute($ratioRecipe, [
    'inputs' => ['input' => $ratioSample, 'template' => $ratioSample],
]);
check('сценарий с фото разной пропорции выполнен', $result['ok'], (string) $result['error']);

$ratioOutput = $result['out_dir'] . '/фото_пропорции.xlsx';
if (is_file($ratioOutput)) {
    $sheet = Excel::load($ratioOutput, false)->getSheet(0);

    $sizes = [];
    foreach ($sheet->getDrawingCollection() as $drawing) {
        $sizes[] = $drawing->getWidth() . '×' . $drawing->getHeight();
    }
    sort($sizes);

    check('фото 3:4 и 16:9 приведены к одной высоте 100',
        $sizes === ['178×100', '178×100', '75×100', '75×100'], implode(', ', $sizes));

    $row2 = $sheet->getRowDimension(2)->getRowHeight();
    $row3 = $sheet->getRowDimension(3)->getRowHeight();
    check('высота строки увеличена под фото', abs($row3 - 76.5) < 0.01, 'получено: ' . $row3);
    check('высокая строка шаблона не уменьшена', abs($row2 - 300) < 0.01, 'получено: ' . $row2);

    $maxWidth = 0;
    $maxHeight = 0;
    foreach ($sheet->getDrawingCollection() as $drawing) {
        $maxWidth = max($maxWidth, (int) $drawing->getWidth());
        $maxHeight = max($maxHeight, (int) $drawing->getHeight());
    }

    $rowPixels = \PhpOffice\PhpSpreadsheet\Shared\Drawing::pointsToPixels($row3);
    $wideUnits = $sheet->getColumnDimension('B')->getWidth();
    $newPixels = \PhpOffice\PhpSpreadsheet\Shared\Drawing::cellDimensionToPixels(
        $sheet->getColumnDimension('D')->getWidth(),
        $sheet->getStyle('B1')->getFont()
    );

    check('широкая колонка шаблона не сужена', abs($wideUnits - 50) < 0.01, 'получено: ' . $wideUnits);
    check('колонка расширена до ширины картинки', $newPixels >= 178, 'получено: ' . $newPixels . ' px');
    check('строка и колонка вмещают фото целиком (не перекрывают соседние ячейки)',
        $rowPixels >= 2 + $maxHeight && $newPixels >= 2 + $maxWidth,
        'строка: ' . $rowPixels . ' px, колонка: ' . $newPixels . ' px, фото: ' . $maxWidth . '×' . $maxHeight);
}

if (function_exists('imagewebp')) {
    $webpPath = $imageDir . '/фото.webp';
    $canvas = imagecreatetruecolor(30, 15);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 20, 90, 200));
    imagewebp($canvas, $webpPath);
    imagedestroy($canvas);

    $usable = op_images_usable($webpPath, true);
    check('WebP преобразован в PNG для Excel',
        $usable['ok'] && str_ends_with($usable['path'], '.png') && is_file($usable['path']),
        $usable['error']);
    check('исходный WebP удалён после преобразования', !is_file($webpPath));
}

// ------------------------------------------------------------------ 8. Безопасность

section('8. Защита данных');

check('ссылка с кириллическим доменом распознаётся',
    Http::isUrl('https://игрушкиоптом.рф/unior/uploads/catalog/thumbs/medium/1800839.jpg'));
check('ссылка с кириллицей в пути распознаётся',
    Http::isUrl('https://site.ru/фото/1.jpg'));
check('обычные адреса и адреса внутренней сети распознаются',
    Http::isUrl('https://disk.yandex.ru/i/abc') && Http::isUrl('http://127.0.0.1:8787/x.jpg'));
check('локальный путь и текст ссылкой не считаются',
    !Http::isUrl('C:/temp/фото.png') && !Http::isUrl('фото.png') && !Http::isUrl('просто текст'));
check('неполные и неподдерживаемые адреса отклоняются',
    !Http::isUrl('https://') && !Http::isUrl('ftp://site.ru/a.jpg'));

// Публичные ссылки облаков: Яндекс Диск и Облако Mail.ru отдают страницу, а не файл
check('ссылка Облака Mail.ru распознаётся',
    Http::isMailRu('https://cloud.mail.ru/public/7B2u/d9Vu3d4TQ')
    && !Http::isMailRu('https://news.mail.ru/news/1.html')
    && !Http::isMailRu('https://disk.yandex.ru/i/abc'));
check('ссылки облаков отличаются от обычных ссылок',
    Http::isCloud('https://cloud.mail.ru/public/7B2u/d9Vu3d4TQ')
    && Http::isCloud('https://yadi.sk/i/QgQ6fFZ_d6Cp9Q')
    && !Http::isCloud('https://site.ru/фото/1.jpg'));
check('идентификатор публичной ссылки Mail.ru разобран',
    Http::mailRuWeblink('https://cloud.mail.ru/public/7B2u/d9Vu3d4TQ') === '7B2u/d9Vu3d4TQ'
    && Http::mailRuWeblink('https://cloud.mail.ru/public/7B2u/d9Vu3d4TQ/Ширма/фото.jpg') === '7B2u/d9Vu3d4TQ/Ширма/фото.jpg',
    Http::mailRuWeblink('https://cloud.mail.ru/public/7B2u/d9Vu3d4TQ'));
check('непубличная ссылка Mail.ru идентификатора не даёт',
    Http::mailRuWeblink('https://cloud.mail.ru/home') === ''
    && Http::mailRuWeblink('https://site.ru/public/a/b') === '');

$plainResolve = Http::resolve('https://site.ru/фото/1.jpg');
check('обычная ссылка разрешается в саму себя',
    $plainResolve['success'] && count($plainResolve['files']) === 1
    && $plainResolve['files'][0]['name'] === '1.jpg',
    (string) json_encode($plainResolve['files'], JSON_UNESCAPED_UNICODE));

$badMailRu = Http::resolve('https://cloud.mail.ru/home');
check('ссылка Mail.ru без публичного адреса отклонена с понятной причиной',
    $badMailRu['success'] === false && str_contains($badMailRu['error'], 'публичная ссылка'),
    $badMailRu['error']);

// Страница вместо файла: проверяется на живом сервере в apitest.php, здесь — правило расширений
check('расширения, для которых HTML — это файл, перечислены',
    (static function (): bool {
        $reflection = new ReflectionClass(Http::class);
        $extensions = $reflection->getConstant('TEXT_EXTENSIONS');

        return is_array($extensions) && in_array('html', $extensions, true) && in_array('csv', $extensions, true);
    })());

$notImage = Paths::tmpDir('notimage') . '/страница.html';
Paths::ensure(dirname($notImage));
file_put_contents($notImage, '<html><body>404</body></html>');

$notImageResult = op_images_usable($notImage, true);
check('файл-страница вместо картинки отклонён с понятной причиной',
    $notImageResult['ok'] === false && str_contains($notImageResult['error'], 'не изображение'),
    $notImageResult['error']);

check('попытка выйти за пределы каталога результатов отклонена', (static function (): bool {
    try {
        $context = new \App\Engine\Context(['out_dir' => Paths::tmpDir('check')]);
        $context->outPath('../../windows/system32/evil.txt');

        return false;
    } catch (Throwable) {
        return true;
    }
})());

check('каталог данных вне каталога программы только в резервном режиме',
    Paths::isPortable() ? Paths::isInsideData(Paths::dataRoot()) : true);

// Модель может назвать архив так же, как файл Excel, — результат должен уцелеть
$zipRecipe = fixture_links_recipe();
$zipRecipe['name'] = 'Ссылки с архивом';
$zipRecipe['steps'][2]['output'] = 'перенос.xlsx';
$zipRecipe['outputs'] = ['zip' => 'перенос.xlsx'];

$result = Runner::execute($zipRecipe, [
    'inputs' => ['input' => $paths['links_original'], 'template' => $paths['links_original']],
]);

check('сценарий с совпадающим именем архива выполнен', $result['ok'], (string) $result['error']);
check('файл Excel не затёрт архивом',
    is_file($result['out_dir'] . '/перенос.xlsx')
    && \PhpOffice\PhpSpreadsheet\IOFactory::identify($result['out_dir'] . '/перенос.xlsx') !== '',
    'файл не опознан как таблица');
check('архив сохранён под другим именем', is_file($result['out_dir'] . '/перенос_архив.zip'));

// ------------------------------------------------------------------ протокол и сертификаты

echo "\n== 9. Протокол сервиса и проверка сертификатов ==\n";

check('протокол Claude выбран по адресу Anthropic',
    Client::protocolFor('https://api.anthropic.com/v1') === 'anthropic');
check('для RouterAI выбран протокол OpenAI',
    Client::protocolFor('https://routerai.ru/api/v1') === 'openai');
check('для OpenAI выбран протокол OpenAI',
    Client::protocolFor('https://api.openai.com/v1') === 'openai');
check('для локальной модели выбран протокол OpenAI',
    Client::protocolFor('http://127.0.0.1:1234/v1') === 'openai');
check('устаревшее значение openai не мешает выбору по адресу',
    Client::protocolFor('https://api.anthropic.com/v1', 'openai') === 'anthropic');
check('явное указание anthropic работает для своего прокси',
    Client::protocolFor('https://proxy.example.com/v1', 'anthropic') === 'anthropic');

$ssl = Http::sslOptions();
check('CA-бандл программы подключён к запросам',
    isset($ssl[CURLOPT_CAINFO]) && is_file((string) $ssl[CURLOPT_CAINFO]),
    (string) ($ssl[CURLOPT_CAINFO] ?? 'не задан'));
check('запросы доверяют системному хранилищу сертификатов Windows',
    defined('CURLSSLOPT_NATIVE_CA')
    && ($ssl[CURLOPT_SSL_OPTIONS] ?? 0) === CURLSSLOPT_NATIVE_CA,
    defined('CURLSSLOPT_NATIVE_CA') ? 'константа есть' : 'сборка без поддержки');

// ------------------------------------------------------------------ ВПР между файлами

echo "\n== 10. ВПР: подстановка данных из справочного файла ==\n";

$lookupDir = Paths::tmpDir('lookup');
Paths::ensure($lookupDir);

// Справочник: A — код, B — наименование, C — группа, D — цена.
// Ключ A-2 повторяется (проверяем, что берётся первая строка), ключ a-3 записан строчными буквами.
$pricePath = $lookupDir . '/прайс.xlsx';
$book = Excel::newSpreadsheet();
$sheet = $book->getActiveSheet();
foreach (['Код', 'Наименование', 'Группа', 'Цена'] as $index => $title) {
    $sheet->setCellValue(Excel::columnLetter($index + 1) . '1', $title);
}
$priceRows = [
    ['A-1', 'Товар первый', 'Группа 1', 100.5],
    ['A-2', 'Товар второй', 'Группа 1', 250],
    ['a-3', 'Товар третий', 'Группа 2', 300],
    ['A-2', 'Товар второй (дубль)', 'Группа 9', 999],
];
foreach ($priceRows as $offset => $row) {
    foreach (array_values($row) as $index => $value) {
        $sheet->setCellValue(Excel::columnLetter($index + 1) . ($offset + 2), $value);
    }
}
Excel::save($book, $pricePath);
$book->disconnectWorksheets();

check('справочник подготовлен', is_file($pricePath));

// Таблица товаров: A — код, B — количество. Есть ключ со пробелами и ключ, которого нет в прайсе.
$goodsPath = $lookupDir . '/товары.xlsx';
$book = Excel::newSpreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setCellValue('A1', 'Код');
$sheet->setCellValue('B1', 'Количество');
$goodsRows = [['A-1', 2], ['A-2', 5], ['A-3', 1], ['A-9', 3], [' A-1 ', 1]];
foreach ($goodsRows as $offset => $row) {
    $sheet->setCellValue('A' . ($offset + 2), $row[0]);
    $sheet->setCellValue('B' . ($offset + 2), $row[1]);
}
Excel::save($book, $goodsPath);
$book->disconnectWorksheets();

check('таблица товаров подготовлена', is_file($goodsPath));

$lookupRecipe = [
    'schema' => RecipeValidator::SCHEMA,
    'name' => 'Цены и наименования из прайса',
    'files' => ['input' => 'Таблица с товарами', 'lookup' => 'Прайс'],
    'steps' => [
        ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
            'columns' => ['code' => 'A', 'qty' => 'B'], 'multi' => 'first', 'skip_if_empty' => ['code']],
        ['op' => 'lookup_field', 'file' => 'lookup', 'sheet' => 1, 'data_from_row' => 2,
            'key_column' => 'A', 'key_field' => 'code',
            'columns' => ['name' => 'B', 'price' => 'D'], 'multi' => 'first',
            'transform' => ['price' => ['multiply' => 1, 'round' => 2]],
            'defaults' => ['name' => 'нет в прайсе']],
        ['op' => 'write_new_sheet', 'output' => 'свод.xlsx', 'columns' => [
            ['column' => 'A', 'title' => 'Код', 'field' => 'code'],
            ['column' => 'B', 'title' => 'Количество', 'field' => 'qty'],
            ['column' => 'C', 'title' => 'Наименование', 'field' => 'name'],
            ['column' => 'D', 'title' => 'Цена', 'field' => 'price'],
        ]],
    ],
];

$validation = RecipeValidator::validate($lookupRecipe);
check('сценарий с ВПР прошёл проверку', $validation['ok'], implode('; ', $validation['errors']));
check('сценарий с ВПР не требует сети', $validation['uses_network'] === false);

$lookupResult = Runner::execute($lookupRecipe, [
    'inputs' => ['input' => $goodsPath, 'lookup' => $pricePath],
]);
check('обработка с ВПР выполнена', $lookupResult['ok'], (string) $lookupResult['error']);

$svodPath = $lookupResult['out_dir'] . '/свод.xlsx';
check('файл с подставленными данными создан', is_file($svodPath), $svodPath);

if (is_file($svodPath)) {
    $sheet = Excel::load($svodPath, false)->getSheet(0);
    $value = static fn (string $cell): string => Excel::text($sheet->getCell($cell)->getValue());

    check('наименование подставлено по коду',
        $value('C2') === 'Товар первый', "получено: {$value('C2')}");
    check('цена подставлена и округлена правилом transform',
        $value('D2') === '100.5', "получено: {$value('D2')}");
    check('ключ из справочника в другом регистре найден',
        $value('C4') === 'Товар третий', "получено: {$value('C4')}");
    check('ключ с лишними пробелами найден',
        $value('C6') === 'Товар первый', "получено: {$value('C6')}");
    check('при повторяющемся ключе взята первая строка справочника',
        $value('C3') === 'Товар второй' && $value('D3') === '250',
        "получено: {$value('C3')} / {$value('D3')}");
    check('для ключа без пары записано значение по умолчанию',
        $value('C5') === 'нет в прайсе', "получено: {$value('C5')}");
    check('цена для ключа без пары осталась пустой',
        $value('D5') === '', "получено: {$value('D5')}");
}

$lookupMessages = implode(' | ', array_column($lookupResult['logs'], 'message'));
check('ключ без пары виден в журнале', str_contains($lookupMessages, 'A-9'), $lookupMessages);
check('повторяющийся ключ справочника виден в журнале',
    str_contains($lookupMessages, 'повторяющимся ключом'), $lookupMessages);
check('в статистике учтено число не найденных ключей',
    (int) ($lookupResult['summary']['stats']['не найдено ключей'] ?? -1) === 1,
    'получено: ' . ($lookupResult['summary']['stats']['не найдено ключей'] ?? 'нет'));
check('в статистике учтено число сопоставленных строк',
    (int) ($lookupResult['summary']['stats']['строк найдено в справочнике'] ?? -1) === 4,
    'получено: ' . ($lookupResult['summary']['stats']['строк найдено в справочнике'] ?? 'нет'));

// Несколько строк справочника с одним ключом: склейка и список
$cellsPath = $lookupDir . '/ячейки.xlsx';
$book = Excel::newSpreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setCellValue('A1', 'Код');
$sheet->setCellValue('B1', 'Ячейка');
foreach ([['K-1', 'Стеллаж 1'], ['K-1', 'Стеллаж 2'], ['K-2', 'Стеллаж 3']] as $offset => $row) {
    $sheet->setCellValue('A' . ($offset + 2), $row[0]);
    $sheet->setCellValue('B' . ($offset + 2), $row[1]);
}
Excel::save($book, $cellsPath);
$book->disconnectWorksheets();

$goodsK = $lookupDir . '/товары-к.xlsx';
$book = Excel::newSpreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setCellValue('A1', 'Код');
$sheet->setCellValue('A2', 'K-1');
$sheet->setCellValue('A3', 'K-2');
Excel::save($book, $goodsK);
$book->disconnectWorksheets();

$multiRecipe = static fn (string $multi): array => [
    'schema' => RecipeValidator::SCHEMA,
    'name' => 'Ячейки из справочника',
    'steps' => [
        ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
            'columns' => ['code' => 'A'], 'multi' => 'first', 'skip_if_empty' => ['code']],
        ['op' => 'lookup_field', 'file' => 'lookup', 'sheet' => 1, 'data_from_row' => 2,
            'key_column' => 'A', 'key_field' => 'code', 'columns' => ['cell' => 'B'],
            'multi' => $multi, 'separator' => ';', 'report_missing' => false],
        ['op' => 'write_new_sheet', 'output' => 'ячейки-результат.xlsx', 'columns' => [
            ['column' => 'A', 'title' => 'Код', 'field' => 'code'],
            ['column' => 'B', 'title' => 'Ячейка', 'field' => 'cell'],
        ]],
    ],
];

$concatResult = Runner::execute($multiRecipe('concat'), [
    'inputs' => ['input' => $goodsK, 'lookup' => $cellsPath],
]);
check('склейка нескольких строк справочника выполнена', $concatResult['ok'], (string) $concatResult['error']);

$concatPath = $concatResult['out_dir'] . '/ячейки-результат.xlsx';
if (is_file($concatPath)) {
    $sheet = Excel::load($concatPath, false)->getSheet(0);
    check('значения нескольких строк склеены через разделитель',
        Excel::text($sheet->getCell('B2')->getValue()) === 'Стеллаж 1;Стеллаж 2',
        Excel::text($sheet->getCell('B2')->getValue()));
}

$listResult = Runner::execute($multiRecipe('list'), [
    'inputs' => ['input' => $goodsK, 'lookup' => $cellsPath],
]);
check('список нескольких строк справочника получен', $listResult['ok'], (string) $listResult['error']);
$listPreview = $listResult['preview']['rows'][0]['cell'] ?? '';
check('при multi=list поле остаётся списком', $listPreview === '[2 эл.]', "получено: {$listPreview}");

// Ключ без пары при missing=error останавливает сценарий
$strictRecipe = $lookupRecipe;
$strictRecipe['steps'][1]['missing'] = 'error';
$strictResult = Runner::execute($strictRecipe, [
    'inputs' => ['input' => $goodsPath, 'lookup' => $pricePath],
]);
check('при missing=error сценарий останавливается на ключе без пары',
    $strictResult['ok'] === false && str_contains((string) $strictResult['error'], 'A-9'),
    (string) $strictResult['error']);

// Файл-справочник не приложен: раньше исполнитель молча подставлял основной файл и «находил»
// значения в той же таблице. Теперь это понятная ошибка.
$noLookupRecipe = $lookupRecipe;
unset($noLookupRecipe['steps'][1]['missing']);
$noLookupResult = Runner::execute($noLookupRecipe, [
    'inputs' => ['input' => $goodsPath, 'template' => $goodsPath],
]);
check('без файла-справочника сценарий останавливается с понятной ошибкой',
    $noLookupResult['ok'] === false
    && str_contains((string) $noLookupResult['error'], 'lookup')
    && str_contains((string) $noLookupResult['error'], 'Приложите'),
    (string) $noLookupResult['error']);

// Имя файла вместо псевдонима (модели так делают) — подстановка основного файла сохраняется
$byFilenameRecipe = [
    'schema' => RecipeValidator::SCHEMA,
    'name' => 'Имя файла вместо псевдонима',
    'steps' => [
        ['op' => 'read_rows', 'file' => 'совсем-другое-имя.xlsx', 'sheet' => 1, 'data_from_row' => 2,
            'columns' => ['code' => 'A'], 'multi' => 'first', 'skip_if_empty' => ['code']],
        ['op' => 'write_new_sheet', 'output' => 'по-имени.xlsx', 'columns' => [
            ['column' => 'A', 'title' => 'Код', 'field' => 'code'],
        ]],
    ],
];
$byFilenameResult = Runner::execute($byFilenameRecipe, ['inputs' => ['input' => $goodsPath]]);
check('имя файла вместо псевдонима по-прежнему читает основной файл',
    $byFilenameResult['ok'] && (int) $byFilenameResult['summary']['rows'] === 5,
    (string) $byFilenameResult['error'] . ' строк: ' . $byFilenameResult['summary']['rows']);

// Подсказка для нейросети: без неё модель не выберет второй файл и не соберёт ВПР
$prompt = Prompts::system();
check('в подсказке описан шаг lookup_field', str_contains($prompt, 'lookup_field'));
check('в подсказке назван псевдоним справочника', str_contains($prompt, '— "lookup"'));
check('в подсказке есть подписи файлов', str_contains($prompt, '"files"'));
check('в подсказке есть пример сценария с ВПР',
    str_contains($prompt, 'Наименования и цены из прайса'));
check('в подсказке описана папка с картинками',
    str_contains($prompt, '"folder"') && str_contains($prompt, '"pattern"'));

// ------------------------------------------------------------------ локальные папки

echo "\n== 11. Локальные папки: поиск файлов по маске ==\n";

$photoDir = Paths::tmpDir('photos') . '/фото тест';
Paths::ensure($photoDir);

$makePhoto = static function (string $path, int $red, int $green, int $blue): void {
    $canvas = imagecreatetruecolor(240, 180);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, $red, $green, $blue));
    imagejpeg($canvas, $path, 85);
    imagedestroy($canvas);
};

// В папке нарочно лежат «похожие» имена: правильно выбранный файл — 001.jpg
$makePhoto($photoDir . '/001.jpg', 70, 130, 180);
$makePhoto($photoDir . '/001 - копия.jpg', 200, 60, 60);
$makePhoto($photoDir . '/0012.jpg', 60, 180, 90);
$makePhoto($photoDir . '/003.jpg', 150, 100, 170);
imagepng(imagecreatetruecolor(120, 90), $photoDir . '/002.png');

Paths::ensure($photoDir . '/вложенная');
$makePhoto($photoDir . '/вложенная/004.jpg', 90, 90, 90);

check('файл найден по маске с расширением',
    str_ends_with((string) FileSearch::find($photoDir, '001.*'), '001.jpg'),
    (string) FileSearch::find($photoDir, '001.*'));
check('при похожих именах выбран точный файл, а не «копия» и не 0012',
    str_ends_with((string) FileSearch::find($photoDir, '001.*'), '001.jpg'));
check('файл найден, когда расширение в таблице не указано',
    str_ends_with((string) FileSearch::find($photoDir, '002'), '002.png'),
    (string) FileSearch::find($photoDir, '002'));
check('маска без совпадений возвращает пусто', FileSearch::find($photoDir, 'нет-такого.*') === null);
check('без обхода вложенных папок файл не найден', FileSearch::find($photoDir, '004.*') === null);
check('с обходом вложенных папок файл найден',
    str_ends_with((string) FileSearch::find($photoDir, '004.*', true), 'вложенная/004.jpg'),
    (string) FileSearch::find($photoDir, '004.*', true));
check('несуществующая папка возвращает пусто', FileSearch::find($photoDir . '/нет-папки', '*') === null);

$relativeFolder = Paths::relativeToData($photoDir);
check('относительный путь к папке ищется в каталоге данных',
    str_ends_with((string) FileSearch::find($relativeFolder, '001.*'), '001.jpg'),
    "папка: {$relativeFolder}, найдено: " . (string) FileSearch::find($relativeFolder, '001.*'));

$fileUrl = FileSearch::fileUrl($photoDir . '/001.jpg');
check('ссылка на файл с пробелом в пути закодирована',
    str_starts_with($fileUrl, 'file:///') && str_contains($fileUrl, '%20') && str_contains($fileUrl, '001.jpg'),
    $fileUrl);

// Сценарий: миниатюры из папки и ссылки на найденные файлы
$folderSample = Paths::tmpDir('photos') . '/товары-папка.xlsx';
$book = Excel::newSpreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setCellValue('A1', 'Код');
$sheet->setCellValue('B1', 'Наименование');
foreach ([['001', 'Первый'], ['002', 'Второй'], ['003', 'Третий'], ['009', 'Без фото']] as $offset => $row) {
    $sheet->setCellValueExplicit('A' . ($offset + 2), $row[0], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('B' . ($offset + 2), $row[1]);
}
Excel::save($book, $folderSample);
$book->disconnectWorksheets();

$folderRecipe = [
    'schema' => RecipeValidator::SCHEMA,
    'name' => 'Миниатюры из папки',
    'params' => [['name' => 'folder', 'label' => 'Папка с фото', 'type' => 'string', 'default' => '']],
    'steps' => [
        ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
            'columns' => ['code' => 'A'], 'multi' => 'first', 'skip_if_empty' => ['code']],
        ['op' => 'insert_images', 'template' => 'input', 'sheet' => 1, 'data_from_row' => 2,
            'match' => ['column' => 'A', 'field' => 'code'],
            'insert_columns' => ['B' => 'Миниатюра', 'C' => 'Большое фото'],
            'images' => ['B' => ['folder' => '{{folder}}', 'pattern' => '{code}.*', 'height' => 50]],
            'hyperlinks' => ['C' => ['folder' => '{{folder}}', 'pattern' => '{code}.*',
                'keep_text' => false, 'text' => 'открыть']],
            'output' => 'товары-с-миниатюрами.xlsx'],
    ],
];

$folderResult = Runner::execute($folderRecipe, [
    'inputs' => ['input' => $folderSample],
    'params' => ['folder' => $photoDir],
]);
check('сценарий с папкой выполнен', $folderResult['ok'], (string) $folderResult['error']);

$folderOutput = $folderResult['out_dir'] . '/товары-с-миниатюрами.xlsx';
check('файл с миниатюрами создан', is_file($folderOutput), $folderOutput);

if (is_file($folderOutput)) {
    $spreadsheet = Excel::load($folderOutput, false);
    $sheet = $spreadsheet->getSheet(0);

    $drawings = [];
    foreach ($sheet->getDrawingCollection() as $drawing) {
        $drawings[$drawing->getCoordinates()] = $drawing;
    }

    check('вставлены миниатюры для трёх найденных файлов', count($drawings) === 3,
        'картинок: ' . count($drawings) . ' (' . implode(', ', array_keys($drawings)) . ')');
    check('новая колонка «Миниатюра» появилась с заголовком',
        Excel::text($sheet->getCell('B1')->getValue()) === 'Миниатюра',
        Excel::text($sheet->getCell('B1')->getValue()));
    check('старая колонка с наименованием сдвинулась вправо',
        Excel::text($sheet->getCell('D1')->getValue()) === 'Наименование',
        Excel::text($sheet->getCell('D1')->getValue()));

    $link = (string) $sheet->getCell('C2')->getHyperlink()->getUrl();
    check('ссылка ведёт на тот же файл, что и миниатюра',
        str_contains($link, '001.jpg') && str_starts_with($link, 'file:///'), $link);
    check('ссылка на файл другого формата найдена по маске',
        str_contains((string) $sheet->getCell('C3')->getHyperlink()->getUrl(), '002.png'),
        (string) $sheet->getCell('C3')->getHyperlink()->getUrl());
    check('текст ссылки записан в ячейку',
        Excel::text($sheet->getCell('C2')->getValue()) === 'открыть',
        Excel::text($sheet->getCell('C2')->getValue()));
    check('для строки без файла ссылки нет',
        (string) $sheet->getCell('C5')->getHyperlink()->getUrl() === '',
        (string) $sheet->getCell('C5')->getHyperlink()->getUrl());

    $spreadsheet->disconnectWorksheets();
}

$folderMessages = array_column($folderResult['logs'], 'message');
$imageWarnings = array_values(array_filter($folderMessages, static fn (string $message) => str_contains($message, 'В папке') && str_contains($message, 'нет файла по маске')));
$linkWarnings = array_values(array_filter($folderMessages, static fn (string $message) => str_contains($message, 'Ссылка в C5') && str_contains($message, 'нет файла по маске')));

check('отсутствующий файл виден в журнале', $imageWarnings !== [], implode(' | ', $folderMessages));
check('о пропущенном изображении предупреждают один раз, а не на каждой строке',
    count($imageWarnings) === 1, 'предупреждений: ' . count($imageWarnings));
check('о ссылке на отсутствующий файл тоже предупреждают один раз',
    count($linkWarnings) === 1, 'предупреждений: ' . count($linkWarnings));

// Сценарий на несуществующей папке: понятная причина вместо пустого результата
$badFolderResult = Runner::execute($folderRecipe, [
    'inputs' => ['input' => $folderSample],
    'params' => ['folder' => $photoDir . '/нет-папки'],
]);
$badMessages = implode(' | ', array_column($badFolderResult['logs'], 'message'));
check('несуществующая папка названа в журнале', str_contains($badMessages, 'Папка не найдена'), $badMessages);

// ------------------------------------------------------------------ итоги по группам

echo "\n== 12. Итоги по группе (сумма, среднее, количество) ==\n";

$totalsDir = Paths::tmpDir('totals');
Paths::ensure($totalsDir);

// Отчёт по хранению: A — дата, B — артикул, C — сумма хранения.
// У артикула A-1 три строки, у A-2 две (одна из них с текстом вместо числа), у A-3 одна.
$totalsSample = $totalsDir . '/хранение.xlsx';
$book = Excel::newSpreadsheet();
$sheet = $book->getActiveSheet();
foreach (['Дата', 'Артикул продавца', 'Сумма хранения, руб'] as $index => $title) {
    $sheet->setCellValue(Excel::columnLetter($index + 1) . '1', $title);
}

$totalsRows = [
    ['2026-02-09', 'A-1', 1.5],
    ['2026-02-10', 'A-1', 2.25],
    ['2026-02-11', 'A-1', 0.75],
    ['2026-02-09', 'A-2', 10],
    ['2026-02-10', 'A-2', 'нет данных'],
    ['2026-02-09', 'A-3', -4],
];
foreach ($totalsRows as $offset => $row) {
    $sheet->setCellValueExplicit('A' . ($offset + 2), $row[0], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('B' . ($offset + 2), $row[1], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('C' . ($offset + 2), $row[2]);
}
Excel::save($book, $totalsSample);
$book->disconnectWorksheets();

check('отчёт по хранению подготовлен', is_file($totalsSample));

$totalsRecipe = [
    'schema' => RecipeValidator::SCHEMA,
    'name' => 'Сумма хранения по артикулу',
    'description' => 'Складывает сумму хранения по каждому артикулу',
    'input' => ['type' => 'xlsx', 'sheet' => 1, 'header_rows' => [1], 'data_from_row' => 2],
    'steps' => [
        ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
            'columns' => ['date' => 'A', 'article' => 'B', 'storage_sum' => 'C'],
            'multi' => 'first', 'skip_if_empty' => ['article']],
        ['op' => 'group_by', 'by' => 'article', 'sort' => true],
        ['op' => 'aggregate', 'map' => [
            'dates' => ['field' => 'date', 'agg' => 'join', 'unique' => true, 'sort' => true],
            'rows' => ['agg' => 'count'],
            'total' => ['field' => 'storage_sum', 'agg' => 'sum', 'round' => 2],
            'average' => ['field' => 'storage_sum', 'agg' => 'avg', 'round' => 2],
        ]],
        ['op' => 'write_new_sheet', 'output' => 'итоги.xlsx', 'sheet_name' => 'Сводка по артикулам',
            'columns' => [
                ['column' => 'A', 'title' => 'Артикул продавца', 'field' => 'article'],
                ['column' => 'B', 'title' => 'Даты', 'field' => 'dates'],
                ['column' => 'C', 'title' => 'Строк', 'field' => 'rows'],
                ['column' => 'D', 'title' => 'Сумма хранения, руб', 'field' => 'total'],
                ['column' => 'E', 'title' => 'Среднее за день, руб', 'field' => 'average'],
            ],
            'totals' => [
                ['column' => 'C', 'label' => 'Итого'],
                ['column' => 'D', 'formula' => '=SUM(D2:D{last})'],
            ]],
    ],
];

check('операция итогов есть в реестре', Ops::exists('aggregate'));

$validation = RecipeValidator::validate($totalsRecipe);
check('сценарий с итогами прошёл проверку', $validation['ok'], implode('; ', $validation['errors']));
check('сценарий с итогами не требует сети', $validation['uses_network'] === false);

$totalsResult = Runner::execute($totalsRecipe, ['inputs' => ['input' => $totalsSample]]);
check('сценарий с итогами выполнен', $totalsResult['ok'], (string) $totalsResult['error']);

$totalsPath = $totalsResult['out_dir'] . '/итоги.xlsx';
check('файл с итогами создан', is_file($totalsPath), $totalsPath);

if (is_file($totalsPath)) {
    $spreadsheet = Excel::load($totalsPath, false);
    $sheet = $spreadsheet->getSheet(0);

    check('одна строка на артикул вместо перечисления дней',
        Excel::text($sheet->getCell('A2')->getValue()) === 'A-1'
        && Excel::text($sheet->getCell('A4')->getValue()) === 'A-3'
        && $sheet->getHighestDataRow() === 5,
        'последняя строка: ' . $sheet->getHighestDataRow());

    check('сумма по группе посчитана',
        (float) $sheet->getCell('D2')->getValue() === 4.5,
        'получено: ' . Excel::text($sheet->getCell('D2')->getValue()));
    check('сумма записана числом, а не текстом',
        is_numeric($sheet->getCell('D2')->getValue())
        && !is_string($sheet->getCell('D2')->getValue()),
        'тип: ' . gettype($sheet->getCell('D2')->getValue()));
    check('среднее по группе посчитано',
        (float) $sheet->getCell('E2')->getValue() === 1.5,
        'получено: ' . Excel::text($sheet->getCell('E2')->getValue()));
    check('число строк группы посчитано',
        (int) $sheet->getCell('C2')->getValue() === 3,
        'получено: ' . Excel::text($sheet->getCell('C2')->getValue()));
    check('даты склеены без повторов и по порядку',
        Excel::text($sheet->getCell('B2')->getValue()) === '2026-02-09;2026-02-10;2026-02-11',
        Excel::text($sheet->getCell('B2')->getValue()));
    check('текст вместо числа пропущен, остальные значения сложены',
        (float) $sheet->getCell('D3')->getValue() === 10.0,
        'получено: ' . Excel::text($sheet->getCell('D3')->getValue()));
    check('отрицательная сумма не потеряна',
        (float) $sheet->getCell('D4')->getValue() === -4.0,
        'получено: ' . Excel::text($sheet->getCell('D4')->getValue()));
    check('итоговая строка сложила все группы',
        Excel::text($sheet->getCell('C5')->getValue()) === 'Итого'
        && (float) $sheet->getCell('D5')->getCalculatedValue() === 10.5,
        'получено: ' . Excel::text($sheet->getCell('C5')->getValue())
            . ' / ' . $sheet->getCell('D5')->getCalculatedValue());

    $spreadsheet->disconnectWorksheets();
}

$totalsMessages = implode(' | ', array_column($totalsResult['logs'], 'message'));
check('нечисловое значение названо в журнале',
    str_contains($totalsMessages, 'не попали нечисловые значения') && str_contains($totalsMessages, 'нет данных'),
    $totalsMessages);
check('в статистике учтено число итогов',
    (int) ($totalsResult['summary']['stats']['итогов посчитано'] ?? -1) === 12,
    'получено: ' . ($totalsResult['summary']['stats']['итогов посчитано'] ?? 'нет'));

// Итог по всей таблице одной строкой — без группировки
$grandTotalRecipe = $totalsRecipe;
unset($grandTotalRecipe['steps'][1]);
$grandTotalRecipe['name'] = 'Итого по всей таблице';
$grandTotalRecipe['steps'][2]['output'] = 'всего.xlsx';
$grandTotalResult = Runner::execute($grandTotalRecipe, ['inputs' => ['input' => $totalsSample]]);
check('итог по всей таблице выполнен', $grandTotalResult['ok'], (string) $grandTotalResult['error']);

$grandTotalPath = $grandTotalResult['out_dir'] . '/всего.xlsx';
if (is_file($grandTotalPath)) {
    $sheet = Excel::load($grandTotalPath, false)->getSheet(0);
    check('без группировки остаётся одна строка с общим итогом',
        $sheet->getHighestDataRow() === 2 && (float) $sheet->getCell('D2')->getValue() === 10.5,
        'строк: ' . $sheet->getHighestDataRow() . ', сумма: ' . Excel::text($sheet->getCell('D2')->getValue()));
    check('в журнале сказано, что группировки не было',
        str_contains(implode(' | ', array_column($grandTotalResult['logs'], 'message')), 'по всей таблице'));
}

// Неизвестный вид итога — понятная ошибка, а не пустой файл
$badAggRecipe = $totalsRecipe;
$badAggRecipe['name'] = 'Неизвестный вид итога';
$badAggRecipe['steps'][2]['map'] = ['total' => ['field' => 'storage_sum', 'agg' => 'сложить']];
$badAggResult = Runner::execute($badAggRecipe, ['inputs' => ['input' => $totalsSample]]);
check('неизвестный вид итога останавливает сценарий',
    $badAggResult['ok'] === false && str_contains((string) $badAggResult['error'], 'Неизвестный вид итога'),
    (string) $badAggResult['error']);

// Минимум, максимум и число различных значений
$extremeRecipe = $totalsRecipe;
$extremeRecipe['name'] = 'Крайние значения по артикулу';
$extremeRecipe['steps'][2]['map'] = [
    'low' => ['field' => 'storage_sum', 'agg' => 'min'],
    'high' => ['field' => 'storage_sum', 'agg' => 'max'],
    'days' => ['field' => 'date', 'agg' => 'unique_count'],
    'first_day' => ['field' => 'date', 'agg' => 'first'],
    'last_day' => ['field' => 'date', 'agg' => 'last'],
];
$extremeRecipe['steps'][3]['output'] = 'крайние.xlsx';
$extremeRecipe['steps'][3]['columns'] = [
    ['column' => 'A', 'title' => 'Артикул продавца', 'field' => 'article'],
    ['column' => 'B', 'title' => 'Минимум, руб', 'field' => 'low'],
    ['column' => 'C', 'title' => 'Максимум, руб', 'field' => 'high'],
    ['column' => 'D', 'title' => 'Дней', 'field' => 'days'],
    ['column' => 'E', 'title' => 'Первая дата', 'field' => 'first_day'],
    ['column' => 'F', 'title' => 'Последняя дата', 'field' => 'last_day'],
];
$extremeRecipe['steps'][3]['totals'] = [];
$extremeResult = Runner::execute($extremeRecipe, ['inputs' => ['input' => $totalsSample]]);
check('итоги min, max, unique_count, first, last выполнены', $extremeResult['ok'], (string) $extremeResult['error']);

$extremePath = $extremeResult['out_dir'] . '/крайние.xlsx';
if (is_file($extremePath)) {
    $spreadsheet = Excel::load($extremePath, false);
    $sheet = $spreadsheet->getSheet(0);
    check('минимум и максимум группы посчитаны',
        (float) $sheet->getCell('B2')->getValue() === 0.75 && (float) $sheet->getCell('C2')->getValue() === 2.25,
        'получено: ' . Excel::text($sheet->getCell('B2')->getValue()) . ' / ' . Excel::text($sheet->getCell('C2')->getValue()));
    check('число различных дат группы посчитано',
        (int) $sheet->getCell('D2')->getValue() === 3,
        'получено: ' . Excel::text($sheet->getCell('D2')->getValue()));
    check('первая и последняя даты группы взяты',
        Excel::text($sheet->getCell('E2')->getValue()) === '2026-02-09'
        && Excel::text($sheet->getCell('F2')->getValue()) === '2026-02-11',
        'получено: ' . Excel::text($sheet->getCell('E2')->getValue()) . ' / ' . Excel::text($sheet->getCell('F2')->getValue()));
    $spreadsheet->disconnectWorksheets();
}

// Подсказка для нейросети: без неё модель снова возьмёт join_values и получится перечисление
$prompt = Prompts::system();
check('в подсказке есть операция aggregate', str_contains($prompt, '### aggregate'));
check('в подсказке сказано, что join_values не складывает числа', str_contains($prompt, 'НЕ складывает'));
check('в подсказке есть пример промежуточных итогов', str_contains($prompt, 'Пример 6'));
check('в подсказке перечислены виды итогов',
    str_contains($prompt, 'unique_count') && str_contains($prompt, 'avg'));

// ------------------------------------------------------------------ готовый отчёт с итогами

echo "\n== 13. Строки-итоги в готовом отчёте ==\n";

// Отчёт, из которого Excel уже сделал промежуточные итоги: подытог после каждого артикула
// («A-1 Итог») и «Общий итог» внизу. Это подытоги, а не данные.
$reportPath = $totalsDir . '/отчёт-с-итогами.xlsx';
$book = Excel::newSpreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setCellValue('A1', 'Дата');
$sheet->setCellValue('B1', 'Артикул продавца');
$sheet->setCellValue('C1', 'Сумма хранения, руб');

$reportRows = [
    ['2026-02-09', 'A-1', 1.5],
    ['2026-02-10', 'A-1', 2.25],
    ['', 'A-1 Итог', '=SUBTOTAL(9,C2:C3)'],
    ['2026-02-09', 'A-2', 10],
    ['', 'A-2 Итог', '=SUBTOTAL(9,C5:C5)'],
    ['', 'Общий итог', '=SUBTOTAL(9,C2:C5)'],
    ['2026-02-09', 'A-3', -4],
    ['2026-02-09', 'Итоговый товар', 7],
];
foreach ($reportRows as $offset => $row) {
    $number = $offset + 2;
    if ($row[0] !== '') {
        $sheet->setCellValueExplicit('A' . $number, $row[0], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
    $sheet->setCellValueExplicit('B' . $number, $row[1], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('C' . $number, $row[2]);
}
Excel::save($book, $reportPath);
$book->disconnectWorksheets();

$reportRecipe = [
    'schema' => RecipeValidator::SCHEMA,
    'name' => 'Свод из отчёта с итогами',
    'description' => 'Складывает сумму хранения по артикулам, пропуская строки-итоги',
    'input' => ['type' => 'xlsx', 'sheet' => 1, 'header_rows' => [1], 'data_from_row' => 2],
    'steps' => [
        ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
            'columns' => ['date' => 'A', 'article' => 'B', 'storage_sum' => 'C'],
            'multi' => 'first', 'skip_if_empty' => ['article']],
        ['op' => 'group_by', 'by' => 'article', 'sort' => true],
        ['op' => 'aggregate', 'map' => ['total' => ['field' => 'storage_sum', 'agg' => 'sum', 'round' => 2]]],
        ['op' => 'write_new_sheet', 'output' => 'свод.xlsx', 'columns' => [
            ['column' => 'A', 'title' => 'Артикул продавца', 'field' => 'article'],
            ['column' => 'B', 'title' => 'Сумма хранения, руб', 'field' => 'total'],
        ]],
    ],
];

$reportResult = Runner::execute($reportRecipe, ['inputs' => ['input' => $reportPath]]);
check('сценарий по отчёту с итогами выполнен', $reportResult['ok'], (string) $reportResult['error']);
check('строки-итоги не попали в чтение',
    (int) ($reportResult['summary']['stats']['прочитано строк'] ?? -1) === 5,
    'прочитано: ' . ($reportResult['summary']['stats']['прочитано строк'] ?? 'нет'));
check('число пропущенных строк-итогов учтено в статистике',
    (int) ($reportResult['summary']['stats']['пропущено строк-итогов'] ?? -1) === 3,
    'пропущено: ' . ($reportResult['summary']['stats']['пропущено строк-итогов'] ?? 'нет'));
check('в журнале объяснено, почему строки пропущены',
    str_contains(implode(' | ', array_column($reportResult['logs'], 'message')), 'это подытоги, а не данные'));

$reportOutput = $reportResult['out_dir'] . '/свод.xlsx';
if (is_file($reportOutput)) {
    $spreadsheet = Excel::load($reportOutput, false);
    $sheet = $spreadsheet->getSheet(0);
    $articles = [];
    $total = 0.0;
    for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
        $articles[] = Excel::text($sheet->getCell('A' . $row)->getValue());
        $total += (float) $sheet->getCell('B' . $row)->getValue();
    }

    check('в своде только настоящие артикулы',
        $articles === ['A-1', 'A-2', 'A-3', 'Итоговый товар'],
        implode(', ', $articles));
    check('подытоги не прибавились к сумме',
        round($total, 2) === 16.75,
        'получено: ' . round($total, 2));
    check('слово «итог» внутри другого слова данные не отменяет',
        in_array('Итоговый товар', $articles, true));
    $spreadsheet->disconnectWorksheets();
}

// Кому нужно прочитать строки-итоги как данные — отключает пропуск
$keepTotalsRecipe = $reportRecipe;
$keepTotalsRecipe['name'] = 'Отчёт с итогами целиком';
$keepTotalsRecipe['steps'][0]['skip_totals'] = false;
$keepTotalsRecipe['steps'][3]['output'] = 'всё.xlsx';
$keepTotalsResult = Runner::execute($keepTotalsRecipe, ['inputs' => ['input' => $reportPath]]);
check('при skip_totals=false строки-итоги читаются',
    $keepTotalsResult['ok']
    && (int) ($keepTotalsResult['summary']['stats']['прочитано строк'] ?? 0) === 8
    && !isset($keepTotalsResult['summary']['stats']['пропущено строк-итогов']),
    'прочитано: ' . ($keepTotalsResult['summary']['stats']['прочитано строк'] ?? 'нет'));

$prompt = Prompts::system();
check('в подсказке описаны строки-итоги отчёта', str_contains($prompt, 'skip_totals'));
check('в подсказке сказано, что строки-итоги пропускаются сами',
    str_contains($prompt, 'пропускает их сама'));

// ------------------------------------------------------------------ промежуточные итоги

echo "\n== 14. Промежуточные итоги: строки данных и «Итог» после группы ==\n";

check('операция промежуточных итогов есть в реестре', Ops::exists('subtotals'));

$subtotalsRecipe = [
    'schema' => RecipeValidator::SCHEMA,
    'name' => 'Промежуточные итоги по артикулу',
    'description' => 'Выводит строки данных и подытог после каждого артикула',
    'input' => ['type' => 'xlsx', 'sheet' => 1, 'header_rows' => [1], 'data_from_row' => 2],
    'steps' => [
        ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
            'columns' => ['date' => 'A', 'article' => 'B', 'storage_sum' => 'C'],
            'multi' => 'first', 'skip_if_empty' => ['article']],
        ['op' => 'group_by', 'by' => 'article', 'sort' => true],
        ['op' => 'subtotals',
            'label' => ['field' => 'article', 'suffix' => ' Итог'],
            'map' => ['storage_sum' => ['agg' => 'sum', 'round' => 2]],
            'keep' => ['date', 'article', 'storage_sum'],
            'sort_details_by' => ['date']],
        ['op' => 'write_new_sheet', 'output' => 'подытоги.xlsx', 'columns' => [
            ['column' => 'A', 'title' => 'Дата', 'field' => 'date'],
            ['column' => 'B', 'title' => 'Артикул продавца', 'field' => 'article'],
            ['column' => 'C', 'title' => 'Сумма хранения, руб', 'field' => 'storage_sum'],
        ]],
    ],
];

$validation = RecipeValidator::validate($subtotalsRecipe);
check('сценарий с промежуточными итогами прошёл проверку', $validation['ok'], implode('; ', $validation['errors']));

$subtotalsResult = Runner::execute($subtotalsRecipe, ['inputs' => ['input' => $totalsSample]]);
check('сценарий с промежуточными итогами выполнен', $subtotalsResult['ok'], (string) $subtotalsResult['error']);
check('строки данных не свернулись, а подытоги добавлены',
    (int) ($subtotalsResult['summary']['rows'] ?? 0) === 10
    && (int) ($subtotalsResult['summary']['stats']['строк-итогов'] ?? 0) === 4,
    'строк: ' . ($subtotalsResult['summary']['rows'] ?? 'нет')
        . ', строк-итогов: ' . ($subtotalsResult['summary']['stats']['строк-итогов'] ?? 'нет'));

$subtotalsPath = $subtotalsResult['out_dir'] . '/подытоги.xlsx';
if (is_file($subtotalsPath)) {
    $spreadsheet = Excel::load($subtotalsPath, false);
    $sheet = $spreadsheet->getSheet(0);

    $layout = [];
    for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
        $layout[] = Excel::text($sheet->getCell('A' . $row)->getValue())
            . '|' . Excel::text($sheet->getCell('B' . $row)->getValue())
            . '|' . Excel::text($sheet->getCell('C' . $row)->getValue());
    }

    // Отчёт по хранению: A-1 (три дня), A-2 (два дня), A-3 (один день)
    $expected = [
        '2026-02-09|A-1|1.5',
        '2026-02-10|A-1|2.25',
        '2026-02-11|A-1|0.75',
        '|A-1 Итог|4.5',
        '2026-02-09|A-2|10',
        '2026-02-10|A-2|нет данных',
        '|A-2 Итог|10',
        '2026-02-09|A-3|-4',
        '|A-3 Итог|-4',
        '|Общий итог|10.5',
    ];
    check('расклад как в Excel: строки данных и «X Итог» после каждого артикула',
        $layout === $expected,
        implode(' / ', $layout));

    check('в строке подытога стоит сумма группы, а не текст',
        is_numeric($sheet->getCell('C5')->getValue()) && (float) $sheet->getCell('C5')->getValue() === 4.5,
        'тип: ' . gettype($sheet->getCell('C5')->getValue()) . ', значение: ' . Excel::text($sheet->getCell('C5')->getValue()));
    check('в строках данных остались только выбранные поля',
        Excel::text($sheet->getCell('A2')->getValue()) === '2026-02-09');

    $spreadsheet->disconnectWorksheets();
}

// Общий итог сходится с колонкой подытогов, несмотря на округление каждой группы
$roundingSample = $totalsDir . '/округление.xlsx';
$book = Excel::newSpreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setCellValue('A1', 'Артикул продавца');
$sheet->setCellValue('B1', 'Сумма');
$sheet->setCellValue('A2', 'B-1');
$sheet->setCellValue('B2', 0.6);
$sheet->setCellValue('A3', 'B-2');
$sheet->setCellValue('B3', 0.6);
Excel::save($book, $roundingSample);
$book->disconnectWorksheets();

$roundingRecipe = [
    'schema' => RecipeValidator::SCHEMA,
    'name' => 'Подытоги с округлением',
    'input' => ['type' => 'xlsx', 'sheet' => 1, 'header_rows' => [1], 'data_from_row' => 2],
    'steps' => [
        ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
            'columns' => ['article' => 'A', 'sum' => 'B'], 'multi' => 'first'],
        ['op' => 'group_by', 'by' => 'article', 'sort' => true],
        ['op' => 'subtotals', 'label' => ['field' => 'article'], 'map' => ['sum' => ['agg' => 'sum', 'round' => 0]]],
        ['op' => 'write_new_sheet', 'output' => 'округление.xlsx', 'columns' => [
            ['column' => 'A', 'title' => 'Артикул продавца', 'field' => 'article'],
            ['column' => 'B', 'title' => 'Сумма', 'field' => 'sum'],
        ]],
    ],
];

$roundingResult = Runner::execute($roundingRecipe, ['inputs' => ['input' => $roundingSample]]);
$roundingPath = $roundingResult['out_dir'] . '/округление.xlsx';
if (is_file($roundingPath)) {
    $spreadsheet = Excel::load($roundingPath, false);
    $sheet = $spreadsheet->getSheet(0);
    check('общий итог складывает подытоги, а не исходные значения',
        (float) $sheet->getCell('B3')->getValue() === 1.0
        && (float) $sheet->getCell('B5')->getValue() === 1.0
        && (float) $sheet->getCell('B6')->getValue() === 2.0,
        'подытоги: ' . Excel::text($sheet->getCell('B3')->getValue()) . ', ' . Excel::text($sheet->getCell('B5')->getValue())
            . '; общий итог: ' . Excel::text($sheet->getCell('B6')->getValue()));
    $spreadsheet->disconnectWorksheets();
}

// Общий итог можно отключить, а подпись — собрать шаблоном
$noGrandRecipe = $subtotalsRecipe;
$noGrandRecipe['name'] = 'Подытоги без общего итога';
$noGrandRecipe['steps'][2]['grand_total'] = false;
$noGrandRecipe['steps'][2]['label'] = ['field' => 'article', 'template' => '{article} — всего'];
$noGrandRecipe['steps'][3]['output'] = 'без-общего.xlsx';
$noGrandResult = Runner::execute($noGrandRecipe, ['inputs' => ['input' => $totalsSample]]);
check('общий итог отключается', $noGrandResult['ok']
    && (int) ($noGrandResult['summary']['stats']['строк-итогов'] ?? 0) === 3
    && (int) ($noGrandResult['summary']['rows'] ?? 0) === 9,
    'строк: ' . ($noGrandResult['summary']['rows'] ?? 'нет'));

$noGrandPath = $noGrandResult['out_dir'] . '/без-общего.xlsx';
if (is_file($noGrandPath)) {
    $sheet = Excel::load($noGrandPath, false)->getSheet(0);
    check('подпись подытога собирается по шаблону',
        Excel::text($sheet->getCell('B5')->getValue()) === 'A-1 — всего',
        Excel::text($sheet->getCell('B5')->getValue()));
    check('последняя строка — подытог последнего артикула',
        Excel::text($sheet->getCell('B10')->getValue()) === 'A-3 — всего',
        Excel::text($sheet->getCell('B10')->getValue()));
}

// Готовый отчёт с итогами: подытоги файла пропускаются, а свои добавляются заново
$reportSubtotals = $subtotalsRecipe;
$reportSubtotals['name'] = 'Подытоги из отчёта с итогами';
$reportSubtotals['steps'][3]['output'] = 'из-отчёта.xlsx';
$reportSubtotalsResult = Runner::execute($reportSubtotals, ['inputs' => ['input' => $reportPath]]);
check('подытоги по отчёту с готовыми итогами выполнены',
    $reportSubtotalsResult['ok'], (string) $reportSubtotalsResult['error']);

$reportSubtotalsPath = $reportSubtotalsResult['out_dir'] . '/из-отчёта.xlsx';
if (is_file($reportSubtotalsPath)) {
    $sheet = Excel::load($reportSubtotalsPath, false)->getSheet(0);
    $grand = 0.0;
    $labels = [];
    for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
        $article = Excel::text($sheet->getCell('B' . $row)->getValue());
        if (mb_stripos($article, 'итог') !== false) {
            $labels[] = $article;
            if ($article === 'Общий итог') {
                $grand = (float) $sheet->getCell('C' . $row)->getValue();
            }
        }
    }

    // «Итоговый товар» — это строка данных (в её названии есть слово «итог»),
    // а «A-1 Итог» и «A-2 Итог» из файла пропущены: свои подытоги считаются заново
    check('подытоги файла не удвоились, добавлены только свои',
        $labels === ['A-1 Итог', 'A-2 Итог', 'A-3 Итог', 'Итоговый товар', 'Итоговый товар Итог', 'Общий итог'],
        implode(', ', $labels));
    check('общий итог равен сумме данных, а не сумме с подытогами файла',
        round($grand, 2) === 16.75,
        'получено: ' . round($grand, 2));
    $spreadsheet = null;
}

// Неизвестный вид итога в подытогах — понятная ошибка
$badSubtotalRecipe = $subtotalsRecipe;
$badSubtotalRecipe['name'] = 'Подытоги с ошибкой';
$badSubtotalRecipe['steps'][2]['map'] = ['storage_sum' => ['agg' => 'сложить']];
$badSubtotalResult = Runner::execute($badSubtotalRecipe, ['inputs' => ['input' => $totalsSample]]);
check('неизвестный вид итога в подытогах останавливает сценарий',
    $badSubtotalResult['ok'] === false && str_contains((string) $badSubtotalResult['error'], 'Неизвестный вид итога'),
    (string) $badSubtotalResult['error']);

// Без группировки: строки списком и только общий итог
$plainSubtotalsRecipe = $subtotalsRecipe;
$plainSubtotalsRecipe['name'] = 'Подытоги без группировки';
unset($plainSubtotalsRecipe['steps'][1]);
$plainSubtotalsRecipe['steps'][2]['output'] = 'списком.xlsx';
$plainSubtotalsResult = Runner::execute($plainSubtotalsRecipe, ['inputs' => ['input' => $totalsSample]]);
check('без группировки строки не теряются',
    $plainSubtotalsResult['ok']
    && (int) ($plainSubtotalsResult['summary']['stats']['строк-итогов'] ?? 0) === 1
    && (int) ($plainSubtotalsResult['summary']['rows'] ?? 0) === 7,
    'строк: ' . ($plainSubtotalsResult['summary']['rows'] ?? 'нет')
        . ', строк-итогов: ' . ($plainSubtotalsResult['summary']['stats']['строк-итогов'] ?? 'нет'));

$prompt = Prompts::system();
check('в подсказке есть операция subtotals', str_contains($prompt, '### subtotals'));
check('в подсказке описаны промежуточные итоги', str_contains($prompt, 'subtotals, правило 24'));
check('в подсказке сказано не дублировать общий итог у write_new_sheet',
    str_contains($prompt, 'общий итог уже добавлен'));

// ------------------------------------------------------------------ сколько файлов нужно задаче

echo "\n== 15. Сколько файлов нужно задаче ==\n";

$oneFileRecipe = [
    'schema' => 'excelmaster/recipe/1',
    'name' => 'Правка таблицы на месте',
    'description' => 'Читает таблицу и записывает результат в неё же',
    'steps' => [
        ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
            'columns' => ['key' => 'B'], 'multi' => 'first'],
        ['op' => 'write_cells', 'template' => 'template', 'sheet' => 1, 'data_from_row' => 2,
            'match' => ['column' => 'B', 'field' => 'key'], 'cells' => ['F' => 'key'],
            'output' => 'результат.xlsx'],
    ],
];

$oneFileInputs = \App\Lib\RecipeInputs::describe($oneFileRecipe, 'single');
check('сценарий для одного файла объявляет два псевдонима', count($oneFileInputs) === 2,
    'входов: ' . count($oneFileInputs));
check('для одного файла второе поле берёт файл у основного',
    (string) ($oneFileInputs[1]['same_as'] ?? '') === 'input',
    (string) ($oneFileInputs[1]['same_as'] ?? 'нет'));
check('для одного файла поле в окне запуска одно',
    count(\App\Lib\RecipeInputs::primary($oneFileInputs)) === 1,
    'полей: ' . count(\App\Lib\RecipeInputs::primary($oneFileInputs)));
check('подписи полей понятны без псевдонимов',
    (string) $oneFileInputs[0]['label'] === 'Ваша таблица'
    && (string) $oneFileInputs[1]['label'] === 'Шаблон',
    $oneFileInputs[0]['label'] . ' / ' . $oneFileInputs[1]['label']);

$twoFileInputs = \App\Lib\RecipeInputs::describe($oneFileRecipe, 'template');
check('для задачи с шаблоном два отдельных поля',
    count(\App\Lib\RecipeInputs::primary($twoFileInputs)) === 2,
    'полей: ' . count(\App\Lib\RecipeInputs::primary($twoFileInputs)));
check('для задачи с шаблоном шаблон не берётся у основного файла',
    !isset($twoFileInputs[1]['same_as']));

$lookupRecipeInputs = \App\Lib\RecipeInputs::describe($lookupRecipe, 'lookup');
check('для задачи со справочником справочник отдельным полем',
    (string) ($lookupRecipeInputs[1]['alias'] ?? '') === 'lookup'
    && !isset($lookupRecipeInputs[1]['same_as']),
    (string) ($lookupRecipeInputs[1]['alias'] ?? 'нет'));
check('подпись справочника берётся из сценария, если она задана',
    (string) ($lookupRecipeInputs[1]['label'] ?? '') === 'Прайс',
    (string) ($lookupRecipeInputs[1]['label'] ?? 'нет'));
check('без своей подписи поле называется словами',
    \App\Lib\RecipeInputs::label('lookup', []) === 'Файл-справочник (прайс)'
    && \App\Lib\RecipeInputs::label('template', []) === 'Шаблон'
    && \App\Lib\RecipeInputs::label('input', []) === 'Ваша таблица',
    \App\Lib\RecipeInputs::label('lookup', []));

check('порядок полей: таблица, шаблон, справочник',
    \App\Lib\RecipeInputs::order(['lookup', 'other', 'template', 'input']) === ['input', 'template', 'lookup', 'other'],
    implode(',', \App\Lib\RecipeInputs::order(['lookup', 'other', 'template', 'input'])));

// Сценарий, объявивший входы, не может взять файл «из ниоткуда»
$undeclared = $oneFileRecipe;
$undeclared['inputs'] = [['alias' => 'input', 'label' => 'Ваша таблица']];
$undeclaredCheck = RecipeValidator::validate($undeclared);
check('необъявленный вход отклоняется',
    $undeclaredCheck['ok'] === false
    && str_contains(implode(' ', $undeclaredCheck['errors']), 'не объявлен во входах'),
    implode('; ', $undeclaredCheck['errors']));
check('объявленные входы проверку проходят',
    RecipeValidator::validate($oneFileRecipe + ['inputs' => $oneFileInputs])['ok'] === true,
    implode('; ', RecipeValidator::validate($oneFileRecipe + ['inputs' => $oneFileInputs])['errors']));

check('псевдонимы файлов сценария собраны',
    RecipeValidator::fileAliases($oneFileRecipe) === ['input', 'template'],
    implode(',', RecipeValidator::fileAliases($oneFileRecipe)));

// Подсказка для нейросети: сколько файлов у пользователя
$onePrompt = Prompts::user('Разложи ссылки по строкам', ['columns' => []], [], ['file_mode' => 'single']);
check('в запросе сказано, что файл один', str_contains($onePrompt, 'У пользователя ОДИН файл'),
    $onePrompt);
check('в запросе сказано писать в тот же файл',
    str_contains($onePrompt, '"template": "input"'));
check('в запросе запрещён лишний второй файл',
    str_contains($onePrompt, 'Второй файл ("lookup" или отдельный "template") не добавляй'));

$lookupPrompt = Prompts::user('Возьми цены из прайса', ['columns' => []], [], [
    'file_mode' => 'lookup',
    'second_alias' => 'lookup',
    'second_name' => 'прайс.xlsx',
    'second_profile' => ['columns' => []],
]);
check('в запросе сказано, что файлов два', str_contains($lookupPrompt, 'ДВА файла'));
check('в запросе назван псевдоним справочника', str_contains($lookupPrompt, 'псевдоним "lookup"'));
check('в запросе назван профиль справочника',
    str_contains($lookupPrompt, '## Профиль файла-справочника (второй файл)'));

$templatePrompt = Prompts::user('Заполни шаблон', ['columns' => []], [], [
    'file_mode' => 'template',
    'second_alias' => 'template',
    'second_name' => 'шаблон.xlsx',
    'second_profile' => ['columns' => []],
]);
check('в запросе назван профиль шаблона',
    str_contains($templatePrompt, '## Профиль файла-шаблона (второй файл)')
    && str_contains($templatePrompt, 'псевдоним "template"'));

$systemPrompt = Prompts::system();
check('в подсказке есть правило о числе файлов',
    str_contains($systemPrompt, 'СКОЛЬКО ФАЙЛОВ НУЖНО ПОЛЬЗОВАТЕЛЮ'));
check('в подсказке есть самопроверка перед ответом',
    str_contains($systemPrompt, 'Перед ответом проверь себя'));
check('в подсказке разрешено писать в тот же файл',
    str_contains($systemPrompt, 'и "input", если результат записывается в ту же таблицу'));

// ------------------------------------------------------------------ дубликаты, сортировка, диаграммы

echo "\n== 16. Дубликаты, сортировка, диаграммы ==\n";

$dedupeDir = Paths::tmpDir('dedupe');
Paths::ensure($dedupeDir);
$dedupeSample = $dedupeDir . '/повторы.xlsx';

$dedupeBook = Excel::newSpreadsheet();
$dedupeSheet = $dedupeBook->getActiveSheet();
$dedupeSheet->fromArray([
    ['Артикул', 'Город', 'Сумма', 'Отметка'],
    ['100', 'Москва', 50, 'первая'],
    ['100', 'Москва', 30, 'вторая'],
    ['200', 'Питер', 10, 'первая'],
    ['200', 'Питер', 20, 'вторая'],
    ['300', 'Казань', 90, 'единственная'],
    ['', 'Не указан', 5, 'без артикула'],
], null, 'A1');
Excel::save($dedupeBook, $dedupeSample);
$dedupeBook->disconnectWorksheets();

$dedupeRecipe = [
    'schema' => RecipeValidator::SCHEMA,
    'name' => 'Проверка удаления дубликатов',
    'steps' => [
        ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
            'columns' => ['article' => 'A', 'city' => 'B', 'sum' => 'C', 'mark' => 'D'], 'multi' => 'first'],
        ['op' => 'dedupe', 'by' => 'article', 'count_field' => 'repeats'],
        ['op' => 'write_new_sheet', 'output' => 'без-повторов.xlsx', 'columns' => [
            ['column' => 'A', 'title' => 'Артикул', 'field' => 'article'],
            ['column' => 'B', 'title' => 'Повторов', 'field' => 'repeats'],
            ['column' => 'C', 'title' => 'Отметка', 'field' => 'mark'],
        ]],
    ],
];

$dedupeCheck = RecipeValidator::validate($dedupeRecipe);
check('сценарий с удалением дубликатов прошёл проверку', $dedupeCheck['ok'], implode('; ', $dedupeCheck['errors']));

$dedupeResult = Runner::execute($dedupeRecipe, ['inputs' => ['input' => $dedupeSample]]);
check('обработка с удалением дубликатов выполнена', $dedupeResult['ok'], (string) $dedupeResult['error']);

$dedupeRows = $dedupeResult['preview']['rows'] ?? [];
check('осталась одна строка на артикул', count($dedupeRows) === 4, 'строк: ' . count($dedupeRows));
check('первая строка ключа сохранена',
    (string) ($dedupeRows[0]['mark'] ?? '') === 'первая', (string) ($dedupeRows[0]['mark'] ?? 'нет'));
check('число повторов посчитано',
    (int) ($dedupeRows[0]['repeats'] ?? 0) === 2 && (int) ($dedupeRows[2]['repeats'] ?? 0) === 1,
    'первый: ' . ($dedupeRows[0]['repeats'] ?? 'нет') . ', третий: ' . ($dedupeRows[2]['repeats'] ?? 'нет'));
check('строка без ключа сохранена, а не склеена',
    (string) ($dedupeRows[3]['mark'] ?? '') === 'без артикула', (string) ($dedupeRows[3]['mark'] ?? 'нет'));
check('в статистике отражено число убранных строк',
    (int) ($dedupeResult['summary']['stats']['убрано дубликатов'] ?? 0) === 2,
    'убрано: ' . ($dedupeResult['summary']['stats']['убрано дубликатов'] ?? 'нет'));

$dedupeLastRecipe = $dedupeRecipe;
$dedupeLastRecipe['name'] = 'Дубликаты: оставить последнюю';
$dedupeLastRecipe['steps'][1]['keep'] = 'last';
$dedupeLastRecipe['steps'][2]['output'] = 'последние.xlsx';
$dedupeLast = Runner::execute($dedupeLastRecipe, ['inputs' => ['input' => $dedupeSample]]);
check('при keep=last остаётся последняя строка ключа',
    $dedupeLast['ok'] && (string) (($dedupeLast['preview']['rows'][0] ?? [])['mark'] ?? '') === 'вторая',
    (string) (($dedupeLast['preview']['rows'][0] ?? [])['mark'] ?? (string) $dedupeLast['error']));

$dedupeSortRecipe = $dedupeRecipe;
$dedupeSortRecipe['name'] = 'Дубликаты и сортировка';
$dedupeSortRecipe['steps'][1]['sort'] = true;
$dedupeSortRecipe['steps'][2]['output'] = 'по-ключу.xlsx';
$dedupeSorted = Runner::execute($dedupeSortRecipe, ['inputs' => ['input' => $dedupeSample]]);
$sortedKeys = array_map(
    static fn (array $row) => (string) ($row['article'] ?? ''),
    $dedupeSorted['preview']['rows'] ?? []
);
check('результат удаления дубликатов сортируется по ключу',
    $dedupeSorted['ok'] && $sortedKeys === ['', '100', '200', '300'],
    implode(', ', $sortedKeys) ?: (string) $dedupeSorted['error']);

// Сортировка по значению — основа ABC-анализа
$sortRecipe = [
    'schema' => RecipeValidator::SCHEMA,
    'name' => 'Проверка сортировки',
    'steps' => [
        ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
            'columns' => ['article' => 'A', 'sum' => 'C'], 'multi' => 'first'],
        ['op' => 'group_by', 'by' => 'article'],
        ['op' => 'aggregate', 'map' => ['total' => ['field' => 'sum', 'agg' => 'sum']]],
        ['op' => 'sort_rows', 'by' => 'total', 'dir' => 'desc', 'numeric' => true],
        ['op' => 'write_new_sheet', 'output' => 'по-убыванию.xlsx', 'columns' => [
            ['column' => 'A', 'title' => 'Артикул', 'field' => 'article'],
            ['column' => 'B', 'title' => 'Сумма', 'field' => 'total'],
        ]],
    ],
];

$sortResult = Runner::execute($sortRecipe, ['inputs' => ['input' => $dedupeSample]]);
check('сортировка по убыванию выполнена', $sortResult['ok'], (string) $sortResult['error']);
$sortRows = $sortResult['preview']['rows'] ?? [];
check('строки идут от большей суммы к меньшей',
    (string) ($sortRows[0]['article'] ?? '') === '300' && (string) ($sortRows[1]['article'] ?? '') === '100',
    'порядок: ' . implode(', ', array_map(static fn (array $row) => (string) ($row['article'] ?? ''), $sortRows)));

$sortAscRecipe = $sortRecipe;
$sortAscRecipe['name'] = 'Проверка сортировки по возрастанию';
$sortAscRecipe['steps'][3] = ['op' => 'sort_rows', 'by' => 'total', 'numeric' => true];
$sortAscRecipe['steps'][4]['output'] = 'по-возрастанию.xlsx';
$sortAsc = Runner::execute($sortAscRecipe, ['inputs' => ['input' => $dedupeSample]]);
$ascTotals = array_map(
    static fn (array $row) => (float) ($row['total'] ?? 0),
    $sortAsc['preview']['rows'] ?? []
);
check('сортировка по возрастанию выполнена',
    $sortAsc['ok'] && $ascTotals === [5.0, 30.0, 80.0, 90.0],
    implode(', ', $ascTotals) ?: (string) $sortAsc['error']);

$sortTextRecipe = $sortRecipe;
$sortTextRecipe['name'] = 'Проверка сортировки текста';
$sortTextRecipe['steps'][3] = ['op' => 'sort_rows', 'by' => 'article'];
$sortTextRecipe['steps'][4]['output'] = 'по-алфавиту.xlsx';
$sortText = Runner::execute($sortTextRecipe, ['inputs' => ['input' => $dedupeSample]]);
check('текст сортируется по алфавиту',
    $sortText['ok'] && (string) (($sortText['preview']['rows'][0] ?? [])['article'] ?? '') === '100',
    (string) (($sortText['preview']['rows'][0] ?? [])['article'] ?? (string) $sortText['error']));

// Диаграмма по готовому результату
$chartRecipe = $sortRecipe;
$chartRecipe['name'] = 'Свод с диаграммой';
$chartRecipe['steps'][] = ['op' => 'insert_chart', 'source' => 'по-убыванию.xlsx', 'output' => 'с-диаграммой.xlsx',
    'type' => 'bar', 'title' => 'Сумма по артикулам', 'categories' => 'A',
    'series' => ['B' => 'Сумма'], 'data_from_row' => 2];

$chartValidation = RecipeValidator::validate($chartRecipe);
check('сценарий с диаграммой прошёл проверку', $chartValidation['ok'], implode('; ', $chartValidation['errors']));

$chartResult = Runner::execute($chartRecipe, ['inputs' => ['input' => $dedupeSample]]);
check('обработка с диаграммой выполнена', $chartResult['ok'], (string) $chartResult['error']);
check('в статистике учтена диаграмма',
    (int) ($chartResult['summary']['stats']['диаграмм'] ?? 0) === 1,
    'диаграмм: ' . ($chartResult['summary']['stats']['диаграмм'] ?? 'нет'));

$chartFile = '';
foreach ($chartResult['preview']['files'] ?? [] as $file) {
    if (($file['kind'] ?? '') === 'chart') {
        $chartFile = (string) ($file['path'] ?? '');
    }
}
check('файл с диаграммой создан', $chartFile !== '' && is_file($chartFile), $chartFile);

if ($chartFile !== '' && is_file($chartFile)) {
    // Диаграмма — часть формата xlsx: проверяем, что она действительно записана
    $zip = new ZipArchive();
    $chartXml = '';
    if ($zip->open($chartFile) === true) {
        $chartXml = (string) $zip->getFromName('xl/charts/chart1.xml');
        $zip->close();
    }

    check('диаграмма записана в файл', $chartXml !== '', 'части xl/charts/chart1.xml нет');
    check('вид диаграммы — столбчатая', str_contains($chartXml, 'barChart'));
    check('диаграмма ссылается на колонку значений', str_contains($chartXml, '$B$2:$B$5'), $chartXml);
    check('подписи взяты из колонки категорий', str_contains($chartXml, '$A$2:$A$5'));
    check('название диаграммы записано', str_contains($chartXml, 'Сумма по артикулам'));
    check('подпись серии записана', str_contains($chartXml, '<c:v>Сумма</c:v>'));

    // Excel покажет диаграмму, только если она связана с листом в частях книги
    $zip = new ZipArchive();
    $sheetRels = '';
    $drawingRels = '';
    if ($zip->open($chartFile) === true) {
        $sheetRels = (string) $zip->getFromName('xl/worksheets/_rels/sheet1.xml.rels');
        $drawingRels = (string) $zip->getFromName('xl/drawings/_rels/drawing1.xml.rels');
        $zip->close();
    }
    check('лист ссылается на область с диаграммой', str_contains($sheetRels, 'drawing1.xml'));
    check('область ссылается на файл диаграммы', str_contains($drawingRels, '../charts/chart1.xml'));

    $chartBack = Excel::load($chartFile, false);
    check('файл с диаграммой открывается',
        $chartBack->getSheet(0)->getHighestDataRow() === 5,
        'строк: ' . $chartBack->getSheet(0)->getHighestDataRow());
    check('данные в файле сохранены',
        (string) $chartBack->getSheet(0)->getCell('A2')->getValue() === '300',
        (string) $chartBack->getSheet(0)->getCell('A2')->getValue());
    $chartBack->disconnectWorksheets();
}

$pieRecipe = $chartRecipe;
$pieRecipe['name'] = 'Круговая диаграмма';
$pieRecipe['steps'][count($pieRecipe['steps']) - 1]['type'] = 'pie';
$pieRecipe['steps'][count($pieRecipe['steps']) - 1]['output'] = 'круговая.xlsx';
$pieResult = Runner::execute($pieRecipe, ['inputs' => ['input' => $dedupeSample]]);
check('круговая диаграмма построена', $pieResult['ok'], (string) $pieResult['error']);
$pieFile = '';
foreach ($pieResult['preview']['files'] ?? [] as $file) {
    if (($file['kind'] ?? '') === 'chart') {
        $pieFile = (string) ($file['path'] ?? '');
    }
}
$pieXml = '';
if ($pieFile !== '' && is_file($pieFile)) {
    $zip = new ZipArchive();
    if ($zip->open($pieFile) === true) {
        $pieXml = (string) $zip->getFromName('xl/charts/chart1.xml');
        $zip->close();
    }
}
check('вид круговой диаграммы записан', str_contains($pieXml, 'pieChart'));

// В режиме проверки файлы не создаются
$chartDry = Runner::execute($chartRecipe, ['dry_run' => true, 'inputs' => ['input' => $dedupeSample]]);
check('проверка сценария с диаграммой проходит без файлов',
    $chartDry['ok'] && (int) ($chartDry['summary']['files'] ?? 0) === 0,
    'файлов: ' . ($chartDry['summary']['files'] ?? 'нет'));

check('в подсказке есть операции дубликатов, сортировки и диаграмм',
    str_contains(Prompts::system(), '### dedupe')
    && str_contains(Prompts::system(), '### sort_rows')
    && str_contains(Prompts::system(), '### insert_chart'));

// Ссылки облаков в режиме проверки: сеть не нужна, но ссылка помечена как облачная
$cloudSample = $dedupeDir . '/ссылки.xlsx';
$cloudBook = Excel::newSpreadsheet();
$cloudBook->getActiveSheet()->fromArray([
    ['Группа', 'Ссылка'],
    ['Щ001', 'https://cloud.mail.ru/public/7B2u/d9Vu3d4TQ'],
    ['Щ002', 'https://yadi.sk/i/QgQ6fFZ_d6Cp9Q'],
    ['Щ003', 'https://site.ru/фото/1.jpg'],
], null, 'A1');
Excel::save($cloudBook, $cloudSample);
$cloudBook->disconnectWorksheets();

$cloudRecipe = [
    'schema' => RecipeValidator::SCHEMA,
    'name' => 'Проверка ссылок облаков',
    'steps' => [
        ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
            'columns' => ['group' => 'A', 'link' => 'B'], 'multi' => 'first'],
        ['op' => 'download_files', 'url_field' => 'link', 'group_field' => 'group',
            'path' => '{group}/{index}.{ext}', 'resolve' => true],
    ],
];

$cloudDry = Runner::execute($cloudRecipe, ['dry_run' => true, 'inputs' => ['input' => $cloudSample]]);
$cloudPlanned = array_column($cloudDry['preview']['planned'] ?? [], 'path');
check('проверка сценария со ссылками облаков проходит без сети',
    $cloudDry['ok'] && (int) ($cloudDry['summary']['files'] ?? 0) === 0,
    (string) ($cloudDry['error'] ?? ''));
check('ссылки облаков помечены как публичные',
    in_array('публичная ссылка облака будет разрешена', $cloudPlanned, true),
    implode(', ', $cloudPlanned));
check('обычная ссылка остаётся обычной',
    in_array('Щ003/1.jpg', $cloudPlanned, true), implode(', ', $cloudPlanned));

// ------------------------------------------------------------------ итог

echo "\n" . str_repeat('-', 60) . "\n";
echo "Пройдено проверок: {$passed}, ошибок: {$failed}\n";
exit($failed === 0 ? 0 : 1);
