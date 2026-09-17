<?php

declare(strict_types=1);

/**
 * Проверка генерации сценария на настроенной модели нейросети.
 *
 * Показывает, что именно уходит в модель, что она вернула, проходит ли сценарий
 * проверку и что получается при прогоне на образце.
 *
 * Запуск: php tools/try_generate.php <файл-образец> "текст задачи" [--model=имя] [--timeout=секунд]
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Lib\Profiler;
use App\Lib\RecipeValidator;
use App\Lib\Runner;
use App\Lib\Settings;
use App\Llm\Client;
use App\Llm\Prompts;

$positional = [];
$overrides = [];

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('~^--([a-z_]+)=(.+)$~', $argument, $matches) === 1) {
        $overrides[$matches[1]] = $matches[2];
        continue;
    }
    $positional[] = $argument;
}

$file = $positional[0] ?? '';
$task = $positional[1] ?? '';

if ($file === '' || !is_file($file) || $task === '') {
    fwrite(STDERR, "Запуск: php tools/try_generate.php <файл-образец> \"текст задачи\" [--model=имя] [--timeout=секунд]\n");
    exit(1);
}

$providerConfig = (array) Settings::get('provider', []);
if (isset($overrides['model'])) {
    $providerConfig['model'] = $overrides['model'];
}
if (isset($overrides['timeout'])) {
    $providerConfig['timeout'] = (int) $overrides['timeout'];
}
if (isset($overrides['base_url'])) {
    $providerConfig['base_url'] = $overrides['base_url'];
}

$client = new Client($providerConfig);
if (!$client->isConfigured()) {
    fwrite(STDERR, "Не настроено подключение: укажите адрес сервиса и модель в приложении (вкладка «Настройки»).\n");
    exit(1);
}

echo "Адрес сервиса: " . (string) ($providerConfig['base_url'] ?? '') . "\n";
echo "Модель: " . (string) ($providerConfig['model'] ?? '') . "\n";
echo "Таймаут: " . (int) ($providerConfig['timeout'] ?? 0) . " с\n";
echo "Уровень приватности: " . Settings::privacyLabel() . "\n\n";

// --- Профиль файла (без нейросети) ---
$profile = Profiler::profile($file, ['sample_rows' => (int) Settings::get('privacy.sample_rows', 3)]);
$profileText = Profiler::toPromptText($profile);

echo "=== Профиль файла (отправляется в модель) ===\n";
echo mb_substr($profileText, 0, 1200) . (mb_strlen($profileText) > 1200 ? "\n… (обрезано для показа)\n" : "\n");

$system = Prompts::system();
echo "\nРазмер запроса: подсказка " . mb_strlen($system) . " символов + профиль " . mb_strlen($profileText) . " символов\n";

// Диагностика: выясняем, на каком шаге сервис перестаёт отвечать
if (in_array('--diag', $argv, true)) {
    echo "\n=== Диагностика запросов ===\n";

    $steps = [
        ['Короткий запрос без подсказки', [
            ['role' => 'user', 'content' => 'Ответь одним словом: работает'],
        ], 64, false],
        ['Полная подсказка + короткий вопрос', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => 'Ответь одним словом: понял'],
        ], 64, false],
        ['Полная подсказка + задача, режим JSON', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => Prompts::user($task, $profile)],
        ], 1024, true],
    ];

    foreach ($steps as [$title, $messages, $maxTokens, $json]) {
        echo "- {$title} (max_tokens={$maxTokens}, json=" . ($json ? 'да' : 'нет') . ")… ";
        $started = microtime(true);
        $probe = $client->chat($messages, ['json' => $json, 'max_tokens' => $maxTokens, 'temperature' => 0]);
        $elapsed = round(microtime(true) - $started, 1);

        if ($probe['ok']) {
            echo "ответ за {$elapsed} с, " . mb_strlen($probe['content']) . " символов\n";
        } else {
            echo "ОШИБКА за {$elapsed} с: " . mb_substr($probe['error'], 0, 160) . "\n";
        }
    }

    echo "\nГотово.\n";
    exit(0);
}

// --- Запрос к модели ---
echo "\n=== Запрос к модели ===\n";
$started = microtime(true);
$response = $client->chat([
    ['role' => 'system', 'content' => $system],
    ['role' => 'user', 'content' => Prompts::user($task, $profile)],
], ['json' => true, 'temperature' => 0]);
$elapsed = round(microtime(true) - $started, 1);

if (!$response['ok']) {
    echo "Модель не ответила за {$elapsed} с\n";
    echo 'Ошибка: ' . $response['error'] . "\n";
    exit(1);
}

$usage = $response['usage'];
$tokens = isset($usage['total_tokens']) ? ", токенов: {$usage['total_tokens']}" : '';
echo "Ответ получен за {$elapsed} с{$tokens}\n";
echo 'Рабочий адрес: ' . $response['base_url'] . "\n";

// --- Разбор ответа ---
$parsed = Client::extractJson($response['content']);
if ($parsed === null) {
    echo "\nНе удалось разобрать ответ как JSON. Ответ модели:\n";
    echo mb_substr($response['content'], 0, 2000) . "\n";
    exit(1);
}

$recipe = $parsed['recipe'] ?? null;

if (!is_array($recipe)) {
    echo "\nМодель не составила сценарий.\n";
    foreach ((array) ($parsed['questions'] ?? []) as $question) {
        echo "  Вопрос: {$question}\n";
    }
    echo 'Пояснение: ' . (string) ($parsed['explanation'] ?? '') . "\n";
    exit(1);
}

echo "\n=== Что предложила модель ===\n";
echo (string) ($parsed['explanation'] ?? '') . "\n";

foreach ((array) ($parsed['questions'] ?? []) as $question) {
    echo "  Вопрос: {$question}\n";
}
foreach ((array) ($parsed['warnings'] ?? []) as $warning) {
    echo "  Замечание: {$warning}\n";
}

// --- Проверка сценария ---
$validation = RecipeValidator::validate($recipe);
echo "\n=== Проверка сценария ===\n";
echo $validation['ok'] ? "Проверка пройдена\n" : "ЕСТЬ ОШИБКИ\n";
foreach ($validation['errors'] as $error) {
    echo "  Ошибка: {$error}\n";
}
foreach ($validation['warnings'] as $warning) {
    echo "  Предупреждение: {$warning}\n";
}

echo "\nШаги: " . implode(' → ', $validation['ops']) . "\n";
echo 'Обращается к сети: ' . ($validation['uses_network'] ? 'да' : 'нет')
    . ', к нейросети: ' . ($validation['uses_ai'] ? 'да' : 'нет') . "\n";

echo "\nСценарий целиком:\n";
echo json_encode($recipe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

if (!$validation['ok']) {
    exit(1);
}

// --- Прогон на образце ---
echo "\n=== Прогон на образце (файлы не создаются) ===\n";
$result = Runner::execute($recipe, [
    'dry_run' => true,
    'inputs' => ['input' => $file, 'template' => $file],
]);

if (!$result['ok']) {
    echo 'Ошибка прогона: ' . (string) $result['error'] . "\n";
    exit(1);
}

$summary = $result['summary'];
echo "Строк: {$summary['rows']}, файлов: {$summary['files']}, запланировано: {$summary['planned']}\n";

$preview = $result['preview'];
if (($preview['rows'] ?? []) !== []) {
    echo "\nПервые строки результата (⏎ — перенос строки внутри ячейки):\n";
    $columns = $preview['columns'];
    echo '  ' . implode(' | ', array_map(static fn ($c) => mb_substr((string) $c, 0, 20), $columns)) . "\n";
    foreach (array_slice($preview['rows'], 0, 3) as $row) {
        $cells = [];
        foreach ($columns as $column) {
            $value = str_replace(["\r\n", "\n", "\r"], '⏎', (string) ($row[$column] ?? ''));
            $cells[] = mb_substr($value, 0, 60);
        }
        echo '  ' . implode(' | ', $cells) . "\n";
    }
}

if (($preview['planned'] ?? []) !== []) {
    echo "\nБудет создано (первые 5):\n";
    foreach (array_slice($preview['planned'], 0, 5) as $item) {
        echo "  {$item['path']}\n";
    }
}

echo "\nГотово: сценарий составлен моделью и успешно проверен на образце.\n";
exit(0);
