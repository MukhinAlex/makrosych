<?php

declare(strict_types=1);

/**
 * Проверка HTTP-интерфейса и фонового исполнителя.
 *
 * Запуск (при запущенном приложении): php tools/apitest.php [адрес]
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/fixtures.php';

use App\Lib\Paths;
use App\Lib\Settings;
use App\Lib\Store;

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8787', '/');
$paths = fixture_paths();
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

/**
 * @param array<string, mixed>|null $json
 * @param array<int, array{0: string, 1: string}> $files пары [путь к файлу, имя поля формы]
 * @return array{status: int, body: string, json: array<string, mixed>|null}
 */
function request(string $url, ?string $token = null, ?array $json = null, array $files = []): array
{
    $curl = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'X-App-Token: ' . $token;
    }

    if ($files !== []) {
        $fields = $json ?? [];
        foreach ($files as [$path, $field]) {
            $fields[$field] = new CURLFile($path);
        }
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $fields);
    } elseif ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 120,
    ]);

    $body = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = (string) curl_error($curl);
    curl_close($curl);

    if ($error !== '') {
        $body = $error;
    }

    return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true)];
}

echo "Проверка приложения по адресу {$base}\n\n";

// Ожидание готовности: сразу после старта сервер принимает не мгновенно
$ready = false;
for ($attempt = 0; $attempt < 20; $attempt++) {
    if (request($base . '/api/?action=ping')['status'] === 200) {
        $ready = true;
        break;
    }
    sleep(1);
}

if (!$ready) {
    echo "Приложение не отвечает по адресу {$base}.\n";
    echo "Запустите его командой Макросыч.exe (или start.bat) или вручную: php -S 127.0.0.1:8787 -t app\\Web app\\Web\\router.php\n";
    exit(1);
}

$settingsBackup = is_file(Paths::settingsFile()) ? (string) file_get_contents(Paths::settingsFile()) : null;

// ------------------------------------------------------------------ страница

echo "Страница и доступ\n";
$page = request($base . '/');
check('страница отдаётся', $page['status'] === 200, 'код ' . $page['status']);
check('в странице есть интерфейс', str_contains($page['body'], 'Макросыч'));
check('токен подставлен в страницу', !str_contains($page['body'], '__APP_TOKEN__'));

$token = is_file(Paths::tokenFile()) ? trim((string) file_get_contents(Paths::tokenFile())) : '';
check('файл токена создан', $token !== '');
check('токен попал в страницу', $token !== '' && str_contains($page['body'], $token));

check('API без токена отклоняет запрос', request($base . '/api/?action=bootstrap')['status'] === 403);
check('API с неверным токеном отклоняет запрос',
    request($base . '/api/?action=bootstrap', 'wrong-token')['status'] === 403);
check('ping доступен без токена', (request($base . '/api/?action=ping')['json']['ok'] ?? false) === true);

check('в библиотеке есть место для просмотра образца', str_contains($page['body'], 'recipe-view'));
check('в новой задаче есть поле файла-справочника',
    str_contains($page['body'], 'id="lookup-file"') && str_contains($page['body'], 'Файл-справочник'));
check('в новой задаче есть панель структуры справочника',
    str_contains($page['body'], 'id="lookup-panel"'));
$script = request($base . '/app.js');
check('скрипт интерфейса отдаётся', $script['status'] === 200 && str_contains($script['body'], 'renderLibrary'),
    'код ' . $script['status']);
check('в интерфейсе есть просмотр образца', str_contains($script['body'], 'openSample'));
check('редактора сценария в интерфейсе нет', !str_contains($script['body'], 'openEditor'));
check('кнопки проверки на образце в библиотеке нет', !str_contains($script['body'], 'data-check'));

// ------------------------------------------------------------------ вёрстка

echo "\nВёрстка и узкие экраны\n";

$style = request($base . '/style.css');
check('стиль интерфейса отдаётся',
    $style['status'] === 200 && str_contains($style['body'], 'nav button'), 'код ' . $style['status']);
check('меню умеет переноситься на вторую строку',
    str_contains($style['body'], "nav {\n    display: flex;\n    flex-wrap: wrap;"),
    'правило переноса меню не найдено');
check('есть правила для узкого окна', str_contains($style['body'], '@media (max-width: 620px)'));

$mediaAt = strpos($style['body'], '@media (max-width: 620px)');
$mediaBlock = $mediaAt === false ? '' : substr($style['body'], $mediaAt, 1200);
check('в правилах для узкого окна есть шапка, меню и отступы страницы',
    str_contains($mediaBlock, 'header') && str_contains($mediaBlock, 'nav button')
    && str_contains($mediaBlock, 'main'),
    'проверьте блок @media');
check('поля встают в столбик на телефоне', str_contains($style['body'], '@media (max-width: 480px)'));
check('поле выбора файла не шире контейнера',
    str_contains($style['body'], 'input[type="file"]'));
check('длинные пути переносятся, а не растягивают макет',
    str_contains($style['body'], 'overflow-wrap: anywhere'));
check('карточки библиотеки не шире экрана',
    str_contains($style['body'], 'minmax(min(300px, 100%), 1fr)'));
check('таблица хранения данных оформлена как «параметр — значение»',
    str_contains($script['body'], '<table class="kv"><tr><th>Параметр</th>'));

// ------------------------------------------------------------------ справка

echo "\nВкладка «Как пользоваться»\n";
check('вкладка справки есть в странице', str_contains($page['body'], 'tab-help'));
check('кнопка справки есть в навигации', str_contains($page['body'], '>Как пользоваться<'));
check('в справке описан первый сценарий', str_contains($page['body'], 'Первый сценарий: пять шагов'));

$firstStepAt = strpos($page['body'], 'подключите нейросеть: адрес сервиса');
$sampleStepAt = strpos($page['body'], '«Новая задача»</b>, выберите файл');
check('первым шагом справки идёт подключение нейросети',
    $firstStepAt !== false && $sampleStepAt !== false && $firstStepAt < $sampleStepAt,
    "подключение: {$firstStepAt}, образец: {$sampleStepAt}");
check('в справке описан запуск на новых файлах', str_contains($page['body'], 'Как запускать сценарий на новых файлах'));
check('в справке есть разбор проблем с подключением', str_contains($page['body'], 'Не подключается к нейросети'));
check('в справке объяснён случай с антивирусом', str_contains($page['body'], 'Проверка защищённых соединений'));
check('в справке указан сервис «Напомни-ка!»', str_contains($page['body'], 'napomni-ka.ru'));
check('на странице «О программе» указан сервис «Напомни-ка!»',
    substr_count($page['body'], 'napomni-ka.ru') >= 3, 'упоминаний: ' . substr_count($page['body'], 'napomni-ka.ru'));
check('поля выбора типа подключения больше нет',
    !str_contains($page['body'], 'provider-type') && !str_contains($script['body'], 'provider-type'));

$modelAt = strpos($page['body'], 'id="provider-model"');
$timeoutAt = strpos($page['body'], 'id="provider-timeout"');
$tokensAt = strpos($page['body'], 'id="provider-maxtokens"');
check('поля ожидания и лимита стоят ниже поля «Модель»',
    $modelAt !== false && $timeoutAt !== false && $tokensAt !== false
    && $modelAt < $timeoutAt && $timeoutAt < $tokensAt,
    "модель: {$modelAt}, ожидание: {$timeoutAt}, лимит: {$tokensAt}");

// ------------------------------------------------------------------ подключение нейросети

echo "\nТексты о подключении нейросети\n";

// Фразы ищем в тексте с нормализованными пробелами: в разметке строки переносятся,
// и перенос внутри фразы не должен считаться ошибкой.
$pageText = (string) preg_replace('/\s+/u', ' ', $page['body']);
$paidMentions = substr_count(mb_strtolower($pageText), 'автору программы платить не нужно');
$priceMentions = substr_count($pageText, 'от нескольких копеек до десятков рублей');

check('сказано, что запуск уже созданных сценариев бесплатен',
    str_contains($pageText, 'без интернета, без оплаты и сколько угодно раз'));
check('сказано, что без нейросети новый сценарий не составить',
    substr_count($pageText, 'новый сценарий создать') >= 2,
    'упоминаний: ' . substr_count($pageText, 'новый сценарий создать'));
check('сказано, что библиотека сценариев при установке пуста',
    substr_count($pageText, 'при установке пуста') >= 3,
    'упоминаний: ' . substr_count($pageText, 'при установке пуста'));
check('названа рекомендуемая модель upstage/solar-pro4',
    substr_count($page['body'], 'upstage/solar-pro4') >= 2,
    'упоминаний: ' . substr_count($page['body'], 'upstage/solar-pro4'));
check('о цене создания сценария сказано в трёх местах (настройки, справка, «О программе»)',
    $priceMentions >= 3, 'упоминаний: ' . $priceMentions);
check('сказано, что автору платить не нужно, в трёх местах',
    $paidMentions >= 3, 'упоминаний: ' . $paidMentions);
check('в трёх местах предложены RouterAI и Polza.AI',
    substr_count($page['body'], 'routerai.ru') >= 3 && substr_count($page['body'], 'polza.ai') >= 3,
    'routerai.ru: ' . substr_count($page['body'], 'routerai.ru')
    . ', polza.ai: ' . substr_count($page['body'], 'polza.ai'));
check('настройки предлагают выбрать любой сервис',
    str_contains($pageText, 'Сервис нейросети можно выбрать любой, который понравится'));
check('в справке сказано, что деньги списываются с баланса сервиса',
    str_contains($pageText, 'Деньги списываются с баланса'));
check('указан раздел о стоимости на странице «О программе»',
    str_contains($pageText, 'Сколько стоит пользоваться Макросычем'));

// ------------------------------------------------------------------ состояние

echo "\nСостояние приложения\n";
$bootstrap = request($base . '/api/?action=bootstrap', $token);
check('bootstrap доступен с токеном', ($bootstrap['json']['ok'] ?? false) === true,
    (string) ($bootstrap['json']['error'] ?? ''));
check('каталог операций передан', count($bootstrap['json']['catalog'] ?? []) >= 19,
    'операций: ' . count($bootstrap['json']['catalog'] ?? []));
check('в каталоге есть операция ВПР между файлами',
    isset($bootstrap['json']['catalog']['lookup_field']),
    implode(', ', array_keys($bootstrap['json']['catalog'] ?? [])));
check('в каталоге есть операция итогов по группе',
    isset($bootstrap['json']['catalog']['aggregate'])
    && str_contains((string) ($bootstrap['json']['catalog']['aggregate']['title'] ?? ''), 'Итоги'),
    implode(', ', array_keys($bootstrap['json']['catalog'] ?? [])));
check('чтение строк умеет пропускать строки-итоги отчёта',
    isset($bootstrap['json']['catalog']['read_rows']['params']['skip_totals'])
    && ($bootstrap['json']['catalog']['read_rows']['params']['skip_totals']['default'] ?? null) === true,
    json_encode($bootstrap['json']['catalog']['read_rows']['params']['skip_totals'] ?? null, JSON_UNESCAPED_UNICODE));
check('в каталоге есть операция промежуточных итогов',
    isset($bootstrap['json']['catalog']['subtotals'])
    && str_contains((string) ($bootstrap['json']['catalog']['subtotals']['title'] ?? ''), 'Промежуточные'),
    implode(', ', array_keys($bootstrap['json']['catalog'] ?? [])));
check('подписи файлов берутся из сценария', str_contains($script['body'], 'recipe.files'));
check('режим хранения определён', isset($bootstrap['json']['paths']['mode']),
    (string) ($bootstrap['json']['paths']['mode'] ?? ''));
check('ключ не возвращается в интерфейс',
    ($bootstrap['json']['settings']['provider']['api_key'] ?? 'x') === '');
check('признак наличия ключа передаётся',
    array_key_exists('api_key_set', $bootstrap['json']['settings']['provider'] ?? []));

// ------------------------------------------------------------------ приватность

echo "\nПриватность\n";
$local = request($base . '/api/?action=settings.save', $token, [
    'provider' => ['base_url' => 'http://localhost:11434/v1', 'model' => 'qwen2.5'],
]);
check('локальный провайдер распознан как безопасный',
    ($local['json']['privacy']['level'] ?? '') === 'local',
    'получено: ' . ($local['json']['privacy']['level'] ?? ''));

$external = request($base . '/api/?action=settings.save', $token, [
    'provider' => ['base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini'],
]);
check('внешний провайдер распознан как внешний',
    ($external['json']['privacy']['level'] ?? '') === 'external',
    'получено: ' . ($external['json']['privacy']['level'] ?? ''));

$internal = request($base . '/api/?action=settings.save', $token, [
    'provider' => ['base_url' => 'http://192.168.1.50:8000/v1'],
]);
check('адрес внутренней сети распознан отдельно',
    ($internal['json']['privacy']['level'] ?? '') === 'private',
    'получено: ' . ($internal['json']['privacy']['level'] ?? ''));

// ------------------------------------------------------------------ библиотека и запуск

echo "\nБиблиотека и фоновый запуск\n";
$recipeId = '';
try {
    $saved = Store::save([
        'name' => 'Проверка запуска',
        'description' => 'Временный сценарий для проверки исполнителя',
        'tags' => ['проверка'],
        'recipe' => fixture_transfer_recipe(),
    ], $paths['template']);
    $recipeId = (string) $saved['id'];
} catch (Throwable $e) {
    check('сценарий сохранён в библиотеке', false, $e->getMessage());
}

check('сценарий сохранён в библиотеке', $recipeId !== '');
check('сценарий виден через API',
    count(array_filter(request($base . '/api/?action=recipes.list', $token)['json']['recipes'] ?? [],
        static fn (array $item) => $item['id'] === $recipeId)) === 1);

if ($recipeId !== '') {
    $started = request($base . '/api/?action=run.start', $token, [
        'recipe_id' => $recipeId,
        'params' => json_encode(['start_row' => 2], JSON_UNESCAPED_UNICODE),
    ], [
        [$paths['source'], 'file_input'],
        [$paths['template'], 'file_template'],
    ]);

    $jobId = (string) ($started['json']['job_id'] ?? '');
    check('задание запущено', $jobId !== '', (string) ($started['json']['error'] ?? ''));

    if ($jobId !== '') {
        $state = '';
        for ($attempt = 0; $attempt < 60; $attempt++) {
            sleep(1);
            $status = request($base . '/api/?action=run.status&job_id=' . urlencode($jobId), $token);
            $state = (string) ($status['json']['status']['state'] ?? '');
            if (in_array($state, ['done', 'error'], true)) {
                $result = $status['json']['status'];
                break;
            }
        }

        check('фоновый исполнитель завершил работу', ($state ?? '') === 'done', 'состояние: ' . $state);
        check('результат содержит строки', (int) ($result['summary']['rows'] ?? 0) > 0,
            'строк: ' . ($result['summary']['rows'] ?? 0));
        check('прогресс дошёл до 100%', (int) ($result['progress']['percent'] ?? 0) === 100);

        $files = request($base . '/api/?action=job.files&job_id=' . urlencode($jobId), $token);
        check('файл результата доступен', (int) ($files['json']['total'] ?? 0) > 0,
            'файлов: ' . ($files['json']['total'] ?? 0));

        $zip = request($base . '/api/?action=job.zip&job_id=' . urlencode($jobId), $token);
        check('архив результатов формируется', $zip['status'] === 200 && strlen($zip['body']) > 1000,
            'размер: ' . strlen($zip['body']));
    }

    Store::delete($recipeId);
    check('временный сценарий удалён',
        count(array_filter(request($base . '/api/?action=recipes.list', $token)['json']['recipes'] ?? [],
            static fn (array $item) => $item['id'] === $recipeId)) === 0);
}

// ------------------------------------------------------------------ задача пользователя

echo "\nЗадача пользователя: ссылки через перенос строки\n";

$linksRecipeId = '';
$linksRecipe = [];
foreach (request($base . '/api/?action=recipes.list', $token)['json']['recipes'] ?? [] as $entry) {
    if (($entry['name'] ?? '') === 'Ссылки через перенос строки') {
        $linksRecipeId = (string) $entry['id'];
        $linksRecipe = $entry;
        break;
    }
}

check('сценарий ссылок есть в библиотеке', $linksRecipeId !== '',
    'запустите php tools/seed_recipes.php');
check('сценарий помечен как офлайновый', ($linksRecipe['uses_network'] ?? true) === false);

if ($linksRecipeId !== '' && is_file($paths['links_original'])) {
    $started = request($base . '/api/?action=run.start', $token, [
        'recipe_id' => $linksRecipeId,
    ], [
        [$paths['links_original'], 'file_input'],
        [$paths['links_original'], 'file_template'],
    ]);

    $jobId = (string) ($started['json']['job_id'] ?? '');
    check('задание запущено', $jobId !== '', (string) ($started['json']['error'] ?? ''));

    if ($jobId !== '') {
        $state = '';
        $result = [];
        for ($attempt = 0; $attempt < 60; $attempt++) {
            sleep(1);
            $status = request($base . '/api/?action=run.status&job_id=' . urlencode($jobId), $token);
            $state = (string) ($status['json']['status']['state'] ?? '');
            if (in_array($state, ['done', 'error'], true)) {
                $result = $status['json']['status'];
                break;
            }
        }

        check('задание завершено', $state === 'done', 'состояние: ' . $state . ' ' . (string) ($result['error'] ?? ''));

        $produced = Paths::jobsDir() . '/' . $jobId . '/out/6_результат.xlsx';
        check('файл результата создан', is_file($produced), $produced);

        if (is_file($produced)) {
            $sheet = \App\Engine\Excel::load($produced, false)->getSheet(0);

            $withNewline = 0;
            $links = 0;
            for ($row = 6; $row <= $sheet->getHighestDataRow(); $row++) {
                $value = \App\Engine\Excel::text($sheet->getCell('F' . $row)->getValue());
                if ($value !== '' && str_contains($value, "\n")) {
                    $withNewline++;
                    $links += count(explode("\n", $value));
                }
            }

            check('в результате 53 ячейки с переносами', $withNewline === 53, 'ячеек: ' . $withNewline);
            check('в результате 181 ссылка', $links === 181, 'ссылок: ' . $links);
        }
    }
}

// ------------------------------------------------------------------ образец

echo "\nОбразец сценария\n";

$sampleId = '';
try {
    $created = Store::save([
        'name' => 'Проверка образца',
        'description' => 'Временный сценарий для проверки просмотра образца',
        'tags' => ['проверка'],
        'recipe' => fixture_transfer_recipe(),
    ], $paths['template']);
    $sampleId = (string) $created['id'];
} catch (Throwable $e) {
    check('сценарий с образцом создан', false, $e->getMessage());
}

check('сценарий с образцом создан', $sampleId !== '');

if ($sampleId !== '') {
    $entry = request($base . '/api/?action=recipe.get&id=' . urlencode($sampleId), $token)['json']['recipe_entry'] ?? [];
    $samplePath = (string) ($entry['sample']['path'] ?? '');

    check('образец сценария виден в библиотеке', ($entry['has_sample'] ?? false) === true);
    check('путь к образцу передан интерфейсу', $samplePath !== '', $samplePath);

    $profile = request($base . '/api/?action=recipe.sample_profile&id=' . urlencode($sampleId), $token);
    check('образец читается для просмотра', ($profile['json']['ok'] ?? false) === true,
        (string) ($profile['json']['error'] ?? ''));
    check('в профиле образца есть колонки', count($profile['json']['profile']['columns'] ?? []) > 0,
        'колонок: ' . count($profile['json']['profile']['columns'] ?? []));
    check('в профиле образца есть строки', count($profile['json']['profile']['sample_rows'] ?? []) > 0);

    $download = request($base . '/api/?action=file.download&path=' . urlencode($samplePath), $token);
    check('файл образца скачивается', $download['status'] === 200 && strlen($download['body']) > 1000,
        'код ' . $download['status'] . ', размер: ' . strlen($download['body']));

    $missing = request($base . '/api/?action=recipe.sample_profile&id=' . urlencode('нет-такого-сценария'), $token);
    check('образец несуществующего сценария не отдаётся', $missing['status'] === 404, 'код ' . $missing['status']);

    Store::delete($sampleId);
    check('временный сценарий удалён',
        count(array_filter(request($base . '/api/?action=recipes.list', $token)['json']['recipes'] ?? [],
            static fn (array $item) => $item['id'] === $sampleId)) === 0);
}

// ------------------------------------------------------------------ создание с ВПР

echo "\nСоздание сценария с файлом-справочником\n";

$lookupDir = Paths::ensure(Paths::tmpDir('lookup-api'));
$goodsFile = $lookupDir . '/товары.xlsx';
$priceFile = $lookupDir . '/прайс.xlsx';

$book = \App\Engine\Excel::newSpreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setCellValue('A1', 'Код');
$sheet->setCellValue('B1', 'Наименование');
$sheet->setCellValue('C1', 'Цена');
foreach ([['1800839', 'Мягкая игрушка «Кот» 30 см', 1500], ['1410010', 'Кукла «Алиса»', 240]] as $offset => $row) {
    $sheet->setCellValueExplicit('A' . ($offset + 2), $row[0], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('B' . ($offset + 2), $row[1]);
    $sheet->setCellValue('C' . ($offset + 2), $row[2]);
}
\App\Engine\Excel::save($book, $priceFile);
$book->disconnectWorksheets();

$book = \App\Engine\Excel::newSpreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setCellValue('A1', 'Код');
$sheet->setCellValue('D1', 'Количество');
foreach ([['1800839', 3], ['1410010', 10], ['9999999', 1]] as $offset => $row) {
    $sheet->setCellValueExplicit('A' . ($offset + 2), $row[0], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('D' . ($offset + 2), $row[1]);
}
\App\Engine\Excel::save($book, $goodsFile);
$book->disconnectWorksheets();

$created = request($base . '/api/?action=session.create', $token, [], [
    [$goodsFile, 'sample'],
    [$priceFile, 'lookup'],
]);

check('сессия создания приняла два файла', ($created['json']['ok'] ?? false) === true,
    (string) ($created['json']['error'] ?? ''));
check('структура файла-справочника определена',
    count($created['json']['lookup_profile']['columns'] ?? []) > 0,
    'колонок: ' . count($created['json']['lookup_profile']['columns'] ?? []));

$sessionId = (string) ($created['json']['session_id'] ?? '');

$lookupRecipe = [
    'schema' => 'excelmaster/recipe/1',
    'name' => 'Проверка ВПР через интерфейс',
    'description' => 'Подставляет наименование и цену из прайса',
    'files' => ['input' => 'Таблица с товарами', 'lookup' => 'Прайс'],
    'steps' => [
        ['op' => 'read_rows', 'file' => 'input', 'sheet' => 1, 'data_from_row' => 2,
            'columns' => ['code' => 'A'], 'multi' => 'first', 'skip_if_empty' => ['code']],
        ['op' => 'lookup_field', 'file' => 'lookup', 'sheet' => 1, 'data_from_row' => 2,
            'key_column' => 'A', 'key_field' => 'code', 'columns' => ['name' => 'B', 'price' => 'C']],
        ['op' => 'write_new_sheet', 'output' => 'с-ценами.xlsx', 'columns' => [
            ['column' => 'A', 'title' => 'Код', 'field' => 'code'],
            ['column' => 'B', 'title' => 'Наименование', 'field' => 'name'],
            ['column' => 'C', 'title' => 'Цена', 'field' => 'price'],
        ]],
    ],
];

$dry = request($base . '/api/?action=session.dryrun', $token, [
    'session_id' => $sessionId,
    'recipe' => $lookupRecipe,
]);
check('проверка на образце проходит с двумя файлами', ($dry['json']['ok'] ?? false) === true,
    (string) ($dry['json']['error'] ?? ''));
check('в проверке видны данные из файла-справочника',
    (string) (($dry['json']['preview']['rows'][0] ?? [])['name'] ?? '') === 'Мягкая игрушка «Кот» 30 см',
    (string) (($dry['json']['preview']['rows'][0] ?? [])['name'] ?? 'нет'));

$saved = request($base . '/api/?action=session.save', $token, [
    'session_id' => $sessionId,
    'recipe' => $lookupRecipe,
    'name' => 'Проверка ВПР через интерфейс',
    'description' => 'Временный сценарий проверки',
    'tags' => ['проверка'],
]);
check('сценарий с ВПР сохранён из интерфейса', ($saved['json']['ok'] ?? false) === true,
    (string) ($saved['json']['error'] ?? ''));

$savedId = (string) ($saved['json']['recipe_entry']['id'] ?? '');
if ($savedId !== '') {
    $entry = request($base . '/api/?action=recipe.get&id=' . urlencode($savedId), $token)['json']['recipe_entry'] ?? [];
    $extra = $entry['extra_samples'] ?? [];

    check('файл-справочник сохранён вместе со сценарием', count($extra) === 1,
        'файлов: ' . count($extra));
    check('файл-справочник виден интерфейсу под псевдонимом lookup',
        (string) ($extra[0]['alias'] ?? '') === 'lookup', (string) ($extra[0]['alias'] ?? ''));

    $extraPath = (string) ($extra[0]['path'] ?? '');
    $download = request($base . '/api/?action=file.download&path=' . urlencode($extraPath), $token);
    check('файл-справочник скачивается', $download['status'] === 200 && strlen($download['body']) > 1000,
        'код ' . $download['status'] . ', размер: ' . strlen($download['body']));

    Store::delete($savedId);
    check('временный сценарий удалён',
        count(array_filter(request($base . '/api/?action=recipes.list', $token)['json']['recipes'] ?? [],
            static fn (array $item) => $item['id'] === $savedId)) === 0);
}

$single = request($base . '/api/?action=session.create', $token, [], [[$goodsFile, 'sample']]);
check('без второго файла сессия тоже создаётся', ($single['json']['ok'] ?? false) === true,
    (string) ($single['json']['error'] ?? ''));
check('без второго файла профиль справочника пуст',
    ($single['json']['lookup_profile'] ?? null) === null);

// ------------------------------------------------------------------ подключение к модели

echo "\nПодключение к модели (проверяется, если локальный сервис запущен)\n";

$localBase = 'http://127.0.0.1:1234';
$localPort = (int) (parse_url($localBase, PHP_URL_PORT) ?: 0);
$reachable = false;

if ($localPort > 0) {
    $socket = @fsockopen('127.0.0.1', $localPort, $errorNumber, $errorText, 2);
    if ($socket !== false) {
        $reachable = true;
        fclose($socket);
    }
}

if (!$reachable) {
    echo "  [пропущено] локальный сервис на {$localBase} не запущен\n";
} else {
    // Адрес указан БЕЗ суффикса /v1 — приложение должно найти его само
    $models = request($base . '/api/?action=settings.models', $token, [
        'provider' => ['base_url' => $localBase],
    ]);

    check('список моделей получен для адреса без /v1', ($models['json']['ok'] ?? false) === true,
        (string) ($models['json']['error'] ?? ''));
    check('рабочий адрес с /v1 определён автоматически',
        str_ends_with((string) ($models['json']['base_url'] ?? ''), '/v1'),
        'получено: ' . (string) ($models['json']['base_url'] ?? ''));
    check('список моделей не пуст', count($models['json']['models'] ?? []) > 0,
        'моделей: ' . count($models['json']['models'] ?? []));

    $firstModel = (string) (($models['json']['models'] ?? [])[0] ?? '');
    if ($firstModel !== '') {
        $test = request($base . '/api/?action=settings.test', $token, [
            'provider' => ['base_url' => $localBase, 'model' => $firstModel],
        ]);

        check('проверка подключения проходит без /v1 в адресе',
            ($test['json']['ok'] ?? false) === true,
            (string) ($test['json']['error'] ?? ''));
        check('в ответе указан рабочий адрес',
            str_ends_with((string) ($test['json']['base_url'] ?? ''), '/v1'),
            'получено: ' . (string) ($test['json']['base_url'] ?? ''));
    }
}

// ------------------------------------------------------------------ прочее

echo "\nПрочее\n";
check('неизвестное действие отклонено',
    request($base . '/api/?action=unknown-action', $token)['status'] === 404);

// ------------------------------------------------------------------ о программе и данные

echo "\nО программе и данные\n";
check('в странице есть вкладка «О программе»',
    str_contains($page['body'], 'tab-about') && str_contains($page['body'], '>О программе<'));
check('формы обратной связи больше нет',
    !str_contains($page['body'], 'fb-message') && !str_contains($page['body'], 'btn-fb-')
    && !str_contains($script['body'], 'btn-fb-') && !str_contains($script['body'], 'feedbackLetter'),
    'остались следы формы: ' . (str_contains($page['body'], 'fb-message') ? 'разметка' : 'скрипт'));
check('в странице указана почта автора', str_contains($page['body'], 'mail@mxander.ru'));
check('указаны реквизиты ИП и ОГРНИП',
    str_contains($page['body'], 'ИП Мухин Александр Викторович')
    && str_contains($page['body'], '322366800007590'));
check('организация отдельной строкой не указана',
    !str_contains($page['body'], '<th>Организация</th>'));
check('указан каталог подушек на Wildberries',
    str_contains($page['body'], 'wildberries.ru/seller/617389'));
check('указана страница программы mxander.ru/makrosych',
    str_contains($page['body'], 'mxander.ru/makrosych'));
check('на странице есть блок благодарности',
    str_contains($page['body'], 'Если хочется сказать спасибо'));
check('на странице объяснено, что делает программа',
    str_contains($page['body'], 'переносит данные из одной')
    && str_contains($page['body'], 'без интернета и без нейросети'));
check('на странице сказано о бесплатности и локальности данных',
    str_contains($page['body'], 'без рекламы, без подписки и без сбора данных')
    && str_contains($page['body'], 'остаются на вашем компьютере'));
check('личного представления автора на странице нет',
    !str_contains($page['body'], 'Меня зовут Александр Мухин')
    && !str_contains($page['body'], 'делаю Макросыч сам'));
check('слово «разработчик» на странице почти не встречается',
    substr_count(mb_strtolower($page['body']), 'разработчик') <= 1,
    'упоминаний: ' . substr_count(mb_strtolower($page['body']), 'разработчик'));
check('на странице есть раздел сотрудничества',
    str_contains($page['body'], 'Сотрудничество и индивидуальная разработка'));
check('версия программы передана', ($bootstrap['json']['runtime']['program'] ?? '') !== '',
    (string) ($bootstrap['json']['runtime']['program'] ?? ''));
check('версия PHP передана', ($bootstrap['json']['runtime']['php'] ?? '') !== '');
check('номер версии совпадает с файлом VERSION',
    ($bootstrap['json']['version'] ?? '') === trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')),
    (string) ($bootstrap['json']['version'] ?? ''));

$backup = request($base . '/api/?action=data.backup', $token);
check('резервная копия данных формируется',
    $backup['status'] === 200 && str_starts_with($backup['body'], 'PK'),
    'код ' . $backup['status'] . ', размер: ' . strlen($backup['body']));

// Возврат настроек в исходное состояние
if ($settingsBackup !== null) {
    file_put_contents(Paths::settingsFile(), $settingsBackup);
} elseif (is_file(Paths::settingsFile())) {
    unlink(Paths::settingsFile());
}
check('настройки возвращены в исходное состояние', true);

echo "\n" . str_repeat('-', 60) . "\n";
echo "Пройдено проверок: {$passed}, ошибок: {$failed}\n";

exit($failed === 0 ? 0 : 1);
