<?php

declare(strict_types=1);

/**
 * Общие тестовые данные: пути к файлам проекта и эталонные сценарии.
 *
 * Используется утилитами tools/selftest.php и tools/apitest.php.
 */

use App\Lib\RecipeValidator;

/** @return array<string, string> */
function fixture_paths(): array
{
    $parent = dirname(dirname(__DIR__));

    return [
        'links' => $parent . '/files/4.xlsx',
        'links_original' => $parent . '/files/6_original.xlsx',
        'links_newline' => $parent . '/files/6.xlsx',
        'source' => $parent . '/0104/исходник.xlsx',
        'template' => $parent . '/0104/душ уголки шаблон-классификатор СО.xlsx',
        'reference' => $parent . '/0104/душ уголки шаблон-классификатор СО_заполненный.xlsx',
    ];
}

/**
 * Сценарий: ссылки из колонки F собрать в одну ячейку через перенос строки.
 *
 * @return array<string, mixed>
 */
function fixture_links_recipe(): array
{
    return [
        'schema' => RecipeValidator::SCHEMA,
        'name' => 'Ссылки через перенос строки',
        'description' => 'Собирает ссылки из колонки F в одну ячейку, разделяя их переносом строки, и включает перенос текста',
        'input' => ['type' => 'xlsx', 'sheet' => 1, 'header_rows' => [1], 'data_from_row' => 6],
        'steps' => [
            ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 6,
                'columns' => ['key' => 'B', 'links' => 'F'], 'multi' => 'first',
                'skip_if_empty' => ['links']],
            ['op' => 'map_field', 'field' => 'links', 'transform' => [
                'trim' => true,
                'regex_replace' => ['pattern' => '~\s*;\s*~', 'replacement' => "\n"],
            ]],
            ['op' => 'write_cells', 'template' => 'template', 'sheet' => 1, 'data_from_row' => 6,
                'match' => ['column' => 'B', 'field' => 'key'],
                'cells' => ['F' => 'links'],
                'wrap_text' => ['F'], 'vertical_align' => 'top',
                'output' => '6_результат.xlsx'],
        ],
    ];
}

/**
 * Сценарий: активные гиперссылки в колонке B по коду из колонки A.
 *
 * @return array<string, mixed>
 */
function fixture_hyperlink_recipe(): array
{
    return [
        'schema' => RecipeValidator::SCHEMA,
        'name' => 'Ссылки на карточки товара',
        'description' => 'Ставит в колонку B активную ссылку на карточку товара по коду из колонки A, текст ячейки не меняет',
        'input' => ['type' => 'xlsx', 'sheet' => 1, 'header_rows' => [2], 'data_from_row' => 3],
        'steps' => [
            ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 3,
                'columns' => ['code' => 'A'], 'multi' => 'first', 'skip_if_empty' => ['code']],
            ['op' => 'map_field', 'field' => 'code', 'into' => 'url',
                'transform' => ['trim' => true, 'prefix' => 'https://игрушкиоптом.рф/catalog/']],
            ['op' => 'write_cells', 'template' => 'template', 'sheet' => 1, 'data_from_row' => 3,
                'match' => ['column' => 'A', 'field' => 'code'],
                'hyperlinks' => ['B' => ['url_field' => 'url', 'keep_text' => true]],
                'output' => 'ссылки.xlsx'],
        ],
    ];
}

/**
 * Сценарий: формулы Excel в шаблоне — сумма по строке.
 *
 * @return array<string, mixed>
 */
function fixture_formula_recipe(): array
{
    return [
        'schema' => RecipeValidator::SCHEMA,
        'name' => 'Формулы в шаблоне',
        'description' => 'Заполняет цену и количество, а сумму считает формулой Excel в строке товара',
        'input' => ['type' => 'xlsx', 'sheet' => 1, 'header_rows' => [1], 'data_from_row' => 2],
        'steps' => [
            ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
                'columns' => ['code' => 'A', 'price' => 'B', 'qty' => 'C'], 'multi' => 'first',
                'skip_if_empty' => ['code']],
            ['op' => 'write_cells', 'template' => 'template', 'sheet' => 1, 'data_from_row' => 2,
                'match' => ['column' => 'A', 'field' => 'code'],
                'cells' => ['B' => 'price', 'C' => 'qty'],
                'formulas' => ['D' => '=B{row}*C{row}', 'E' => '={col:price}{row}+{col:qty}{row}'],
                'output' => 'формулы.xlsx'],
        ],
    ];
}

/**
 * Сценарий: вставка изображений в колонку шаблона по ссылке или пути из поля.
 * Заодно заполняет значения и считает сумму формулой — всё в одном файле.
 *
 * @return array<string, mixed>
 */
function fixture_image_recipe(): array
{
    return [
        'schema' => RecipeValidator::SCHEMA,
        'name' => 'Карточки товаров с фото и суммой',
        'description' => 'Вставляет фото по ссылке из колонки B в колонку C по коду из колонки A, заполняет цену и количество, считает сумму формулой',
        'input' => ['type' => 'xlsx', 'sheet' => 1, 'header_rows' => [1], 'data_from_row' => 2],
        'steps' => [
            ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
                'columns' => ['code' => 'A', 'photo' => 'B', 'price' => 'D', 'qty' => 'E'], 'multi' => 'first',
                'skip_if_empty' => ['code']],
            ['op' => 'insert_images', 'template' => 'template', 'sheet' => 1, 'data_from_row' => 2,
                'match' => ['column' => 'A', 'field' => 'code'],
                'images' => ['C' => ['url_field' => 'photo', 'height' => 40]],
                'cells' => ['D' => 'price', 'E' => 'qty'],
                'formulas' => ['F' => '=D{row}*E{row}'],
                'output' => 'карточки.xlsx'],
        ],
    ];
}

/**
 * Сценарий: вставка колонки с фото между кодом и номенклатурой и гиперссылка на номенклатуру.
 * Соответствует задаче «гиперссылка на карточку товара + колонка с изображением».
 *
 * @return array<string, mixed>
 */
function fixture_product_cards_recipe(): array
{
    return [
        'schema' => RecipeValidator::SCHEMA,
        'name' => 'Фото и ссылка в новой колонке',
        'description' => 'Вставляет колонку «Изображение» между кодом и номенклатурой, ставит фото по ссылке и активную гиперссылку на номенклатуру',
        'input' => ['type' => 'xlsx', 'sheet' => 1, 'header_rows' => [2], 'data_from_row' => 3],
        'steps' => [
            ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 3,
                'columns' => ['code' => 'A', 'name' => 'B'], 'multi' => 'first',
                'skip_if_empty' => ['code']],
            ['op' => 'insert_images', 'template' => 'template', 'sheet' => 1, 'data_from_row' => 3,
                'match' => ['column' => 'A', 'field' => 'code'],
                'insert_columns' => ['B' => 'Изображение'], 'header_row' => 2,
                'images' => ['B' => [
                    'template' => 'https://игрушкиоптом.рф/unior/uploads/catalog/thumbs/medium/{code}.jpg',
                ]],
                'height' => 100,
                'hyperlinks' => ['C' => [
                    'template' => 'https://игрушкиоптом.рф/catalog/{code}',
                    'keep_text' => true,
                ]],
                'output' => 'карточки.xlsx'],
        ],
    ];
}

/**
 * Сценарий: скачивание изображений из колонки G в папки по артикулу из B.
 *
 * @return array<string, mixed>
 */
function fixture_download_recipe(): array
{
    return [
        'schema' => RecipeValidator::SCHEMA,
        'name' => 'Фото по артикулам',
        'description' => 'Скачивает изображения из колонки G в папки по артикулу из B',
        'input' => ['type' => 'xlsx', 'sheet' => 1, 'header_rows' => [1], 'data_from_row' => 6],
        'steps' => [
            ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 6,
                'columns' => ['article' => 'B', 'links' => 'G'], 'multi' => 'first'],
            ['op' => 'split_multi', 'field' => 'links', 'into' => 'link', 'separator' => 'newline', 'expand' => true],
            ['op' => 'filter', 'conditions' => [
                ['field' => 'article', 'op' => 'not_empty'],
                ['field' => 'link', 'op' => 'contains', 'value' => 'http'],
            ]],
            ['op' => 'download_files', 'url_field' => 'link', 'group_field' => 'article',
                'path' => '{article}/{index}.{ext}', 'resolve' => false],
            ['op' => 'report_xlsx', 'output' => 'отчёт.xlsx'],
        ],
    ];
}

/**
 * Сценарий: сбор грузовых мест по артикулу и заполнение шаблона.
 * Соответствует логике существующего скрипта transfer_data_v5.php.
 *
 * @return array<string, mixed>
 */
function fixture_transfer_recipe(): array
{
    return [
        'schema' => RecipeValidator::SCHEMA,
        'name' => 'Перенос данных грузовых мест в шаблон',
        'description' => 'Собирает грузовые места по артикулу товара и заполняет шаблон',
        'input' => ['type' => 'xlsx', 'sheet' => 1, 'header_rows' => [1], 'data_from_row' => 2],
        'params' => [
            ['name' => 'start_row', 'label' => 'Начальная строка исходника', 'type' => 'number', 'default' => 2],
        ],
        'steps' => [
            ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => '{{start_row}}',
                'columns' => ['article' => 'B', 'barcode' => 'AD', 'length' => 'Y', 'width' => 'Z',
                    'height' => 'AA', 'weight' => 'AC']],
            // Артикул товара переносится вниз на строки грузовых мест
            ['op' => 'carry_value', 'field' => 'article', 'marker' => 'barcode'],
            ['op' => 'filter', 'conditions' => [['field' => 'barcode', 'op' => 'not_empty']]],
            ['op' => 'group_by', 'by' => 'article'],
            ['op' => 'join_values', 'map' => ['barcodes' => 'barcode', 'lengths' => 'length',
                'widths' => 'width', 'heights' => 'height', 'weights' => 'weight'],
                'separator' => ';', 'skip_empty' => true,
                'transform' => ['weights' => ['multiply' => 1000, 'round' => 0]]],
            ['op' => 'write_cells', 'template' => 'template', 'sheet' => 1, 'data_from_row' => 5,
                'match' => ['column' => 'B', 'field' => 'article'],
                'cells' => ['F' => 'barcodes', 'I' => 'lengths', 'H' => 'widths', 'J' => 'heights', 'K' => 'weights'],
                'output' => 'заполненный.xlsx'],
        ],
    ];
}

/**
 * Сценарий: ВПР — наименование и цена из прайса по коду товара.
 *
 * Два входных файла: таблица товаров (input) и прайс (lookup). Подписи полей выбора файлов
 * заданы в "files", иначе в окне запуска оба поля назывались бы «Файл данных».
 *
 * @return array<string, mixed>
 */
function fixture_lookup_recipe(): array
{
    return [
        'schema' => RecipeValidator::SCHEMA,
        'name' => 'Наименования и цены из прайса (ВПР)',
        'description' => 'Находит товар в прайсе по коду и подставляет в таблицу наименование и цену',
        'input' => ['type' => 'xlsx', 'sheet' => 1, 'header_rows' => [1], 'data_from_row' => 2],
        'files' => ['input' => 'Таблица с товарами', 'lookup' => 'Прайс'],
        'steps' => [
            ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
                'columns' => ['code' => 'A', 'qty' => 'D'], 'multi' => 'first', 'skip_if_empty' => ['code']],
            ['op' => 'lookup_field', 'file' => 'lookup', 'sheet' => 1, 'data_from_row' => 2,
                'key_column' => 'A', 'key_field' => 'code',
                'columns' => ['name' => 'B', 'price' => 'C'], 'multi' => 'first',
                'defaults' => ['name' => 'нет в прайсе']],
            ['op' => 'write_cells', 'template' => 'input', 'sheet' => 1, 'data_from_row' => 2,
                'match' => ['column' => 'A', 'field' => 'code'],
                'cells' => ['B' => 'name', 'C' => 'price'],
                'output' => 'товары-с-ценами.xlsx'],
        ],
    ];
}

/**
 * Сценарий: миниатюры из папки с фотографиями и активные ссылки на большие файлы.
 *
 * Картинки ищутся в папке по маске «код.*», поэтому расширение файла и лишние слова
 * в имени значения не имеют. Папка — параметр сценария: пользователь вводит её в окне
 * запуска, а не правит сценарий.
 *
 * @return array<string, mixed>
 */
function fixture_photo_folder_recipe(): array
{
    return [
        'schema' => RecipeValidator::SCHEMA,
        'name' => 'Миниатюры и ссылки на фото из папки',
        'description' => 'Ищет фотографии в папке по коду товара, вставляет миниатюры в новую колонку и ставит ссылки на большие файлы',
        'input' => ['type' => 'xlsx', 'sheet' => 1, 'header_rows' => [2], 'data_from_row' => 3],
        'files' => ['input' => 'Таблица с товарами'],
        'params' => [
            ['name' => 'folder', 'label' => 'Папка с фотографиями', 'type' => 'string', 'default' => 'samples/фото'],
        ],
        'steps' => [
            ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 3,
                'columns' => ['code' => 'A'], 'multi' => 'first', 'skip_if_empty' => ['code']],
            ['op' => 'insert_images', 'template' => 'input', 'sheet' => 1, 'data_from_row' => 3,
                'match' => ['column' => 'A', 'field' => 'code'],
                'insert_columns' => ['B' => 'Миниатюра', 'C' => 'Большое фото'], 'header_row' => 2,
                'images' => ['B' => ['folder' => '{{folder}}', 'pattern' => '{code}.*', 'height' => 60]],
                'hyperlinks' => ['C' => ['folder' => '{{folder}}', 'pattern' => '{code}.*',
                    'keep_text' => false, 'text' => 'открыть']],
                'output' => 'товары-с-фото.xlsx'],
        ],
    ];
}
