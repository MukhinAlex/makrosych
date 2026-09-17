<?php

declare(strict_types=1);

/**
 * HTTP API приложения.
 *
 * Все действия, изменяющие состояние, требуют заголовок X-App-Token со значением
 * из data/.token — это защищает локальный порт от обращений из других программ
 * и со сторонних страниц в браузере.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Engine\Ops;
use App\Lib\Paths;
use App\Lib\Profiler;
use App\Lib\RecipeValidator;
use App\Lib\Runner;
use App\Lib\Settings;
use App\Lib\Store;
use App\Llm\Client;
use App\Llm\Prompts;

set_time_limit(0);
ini_set('memory_limit', '1024M');

// ---------------------------------------------------------------- инфраструктура

/** @return array<string, mixed> */
function api_bootstrap(): array
{
    return [
        'ok' => true,
        'version' => APP_VERSION,
        'paths' => [
            'root' => Paths::root(),
            'data' => Paths::dataRoot(),
            'mode' => Paths::mode(),
            'portable' => Paths::isPortable(),
        ],
        'runtime' => [
            'program' => APP_VERSION,
            'php' => PHP_VERSION,
            'system' => php_uname('s') . ' ' . php_uname('r'),
            'bits' => PHP_INT_SIZE * 8,
        ],
        'settings' => Settings::publicView(),
        'privacy' => [
            'level' => Settings::privacyLevel(),
            'label' => Settings::privacyLabel(),
            'host' => Settings::providerHost(),
        ],
        'catalog' => Ops::catalog(),
        'transforms' => \App\Engine\Transform::catalog(),
        'recipes' => Store::list(),
    ];
}

function api_token(): string
{
    $file = Paths::tokenFile();
    if (is_file($file)) {
        $token = trim((string) file_get_contents($file));
        if ($token !== '') {
            return $token;
        }
    }

    $token = bin2hex(random_bytes(24));
    file_put_contents($file, $token);

    return $token;
}

function api_check_token(): void
{
    $provided = (string) ($_SERVER['HTTP_X_APP_TOKEN'] ?? '');
    $expected = api_token();

    if ($provided === '' || !hash_equals($expected, $provided)) {
        api_json(['ok' => false, 'error' => 'Отказано в доступе: неверный токен приложения'], 403);
    }
}

/** @param array<string, mixed> $payload */
function api_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_fail(string $message, int $status = 400): never
{
    api_json(['ok' => false, 'error' => $message], $status);
}

/** @return array<string, mixed> */
function api_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $_POST;
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    return $_POST;
}

/**
 * Настройки подключения: сохранённые, при необходимости дополненные присланными.
 *
 * Позволяет проверять подключение и запрашивать список моделей до сохранения настроек.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function api_provider_config(array $input): array
{
    $saved = (array) Settings::get('provider', []);
    $provided = (array) ($input['provider'] ?? []);

    if ($provided === []) {
        return $saved;
    }

    foreach ($provided as $key => $value) {
        if ($value === '' && $key === 'api_key' && ($saved[$key] ?? '') !== '') {
            continue;
        }
        $saved[$key] = $value;
    }

    return $saved;
}

function api_session_dir(string $sessionId): string
{
    $safe = preg_replace('~[^a-zA-Z0-9._-]~', '', $sessionId) ?? '';
    if ($safe === '') {
        api_fail('Не указана сессия');
    }

    return Paths::path('samples', $safe);
}

/** @return array<string, mixed> */
function api_session_profile(string $sessionId): array
{
    $dir = api_session_dir($sessionId);
    $file = $dir . '/profile.json';
    if (!is_file($file)) {
        api_fail('Профиль образца не найден — загрузите файл заново');
    }

    $profile = json_decode((string) file_get_contents($file), true);
    if (!is_array($profile)) {
        api_fail('Профиль образца повреждён');
    }

    return $profile;
}

function api_session_sample(string $sessionId): string
{
    $found = glob(api_session_dir($sessionId) . '/sample.*') ?: [];
    if ($found === []) {
        api_fail('Файл-образец не найден в сессии');
    }

    return $found[0];
}

/** Файл-справочник сессии (второй файл, необязательный): например, прайс для ВПР. */
function api_session_lookup(string $sessionId): ?string
{
    $found = glob(api_session_dir($sessionId) . '/lookup.*') ?: [];

    return $found === [] ? null : $found[0];
}

/** @return array<string, mixed>|null */
function api_session_lookup_profile(string $sessionId): ?array
{
    $file = api_session_dir($sessionId) . '/lookup_profile.json';
    if (!is_file($file)) {
        return null;
    }

    $profile = json_decode((string) file_get_contents($file), true);

    return is_array($profile) ? $profile : null;
}

/**
 * Входные файлы для проверки и сохранения сценария.
 *
 * @return array<string, string>
 */
function api_session_inputs(string $sessionId): array
{
    $sample = api_session_sample($sessionId);
    $inputs = ['input' => $sample, 'template' => $sample];
    $lookup = api_session_lookup($sessionId);
    if ($lookup !== null) {
        $inputs['lookup'] = $lookup;
    }

    return $inputs;
}

/** @return array<int, array<string, mixed>> */
function api_session_history(string $sessionId): array
{
    $file = api_session_dir($sessionId) . '/chat.json';
    if (!is_file($file)) {
        return [];
    }

    $history = json_decode((string) file_get_contents($file), true);

    return is_array($history) ? $history : [];
}

function api_session_append(string $sessionId, string $role, string $content): void
{
    $dir = Paths::ensure(api_session_dir($sessionId));
    $history = api_session_history($sessionId);
    $history[] = ['role' => $role, 'content' => $content, 'at' => date('c')];
    file_put_contents(
        $dir . '/chat.json',
        json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

/**
 * Путь к php.exe для командной строки — всегда в UTF-8.
 *
 * PHP_BINARY на Windows отдаёт путь в системной кодировке (например cp1251), а команда
 * для popen должна быть в UTF-8: иначе кириллица в пути программы (папка «Макросыч-1.0»
 * или «Загрузки») превращается в мусор и cmd отвечает «Не удается найти указанный путь».
 */
function api_php_binary(): string
{
    $bundled = Paths::root() . '/runtime/php/php.exe';
    if (is_file($bundled)) {
        return $bundled;
    }

    $binary = PHP_BINARY;
    if (PHP_OS_FAMILY === 'Windows' && !mb_check_encoding($binary, 'UTF-8')) {
        $binary = mb_convert_encoding($binary, 'UTF-8', 'CP' . sapi_windows_cp_get('ansi'));
    }

    return $binary;
}

/** Запуск CLI-процесса в фоне (встроенный сервер PHP однопоточный). */
function api_spawn_worker(string $jobId): void
{
    $php = api_php_binary();
    $script = APP_DIR . '/Worker/run.php';
    $jobDir = Paths::ensure(Paths::jobsDir() . '/' . $jobId);
    $log = $jobDir . '/worker.log';

    // Настройки PHP воркер получает из php.ini рядом с php.exe — тот же файл, что и сервер
    $args = [$php, $script, '--job=' . $jobId];

    $command = 'start /B "" ' . implode(' ', array_map('escapeshellarg', $args))
        . ' > ' . escapeshellarg($log) . ' 2>&1';

    if (pclose(popen($command, 'r')) === -1) {
        throw new \RuntimeException('Не удалось запустить обработку');
    }
}

/** @return array<string, mixed> */
function api_job_status(string $jobId): array
{
    $safe = preg_replace('~[^a-zA-Z0-9._-]~', '', $jobId) ?? '';
    $jobDir = Paths::jobsDir() . '/' . $safe;
    $statusFile = $jobDir . '/status.json';

    if (!is_file($statusFile)) {
        return ['state' => 'missing', 'job_id' => $safe];
    }

    $status = json_decode((string) file_get_contents($statusFile), true);
    if (!is_array($status)) {
        return ['state' => 'missing', 'job_id' => $safe];
    }

    $status['log'] = api_tail_log($jobDir . '/log.jsonl', 120);
    $status['job_id'] = $safe;

    return $status;
}

/** @return array<int, array<string, mixed>> */
function api_tail_log(string $file, int $limit): array
{
    if (!is_file($file)) {
        return [];
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice($lines, -$limit);

    $entries = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $entries[] = $decoded;
        }
    }

    return $entries;
}

// ---------------------------------------------------------------- действия

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$input = api_input();

if ($action === '') {
    api_fail('Не указано действие');
}

if ($action !== 'ping') {
    api_check_token();
}

switch ($action) {
    // ------------------------------------------------------- общее
    case 'ping':
        api_json(['ok' => true, 'version' => APP_VERSION]);

    case 'bootstrap':
        api_json(api_bootstrap());

    case 'settings.save':
        $provider = (array) ($input['provider'] ?? []);
        $privacy = (array) ($input['privacy'] ?? []);
        Settings::save(array_filter([
            'provider' => $provider === [] ? null : $provider,
            'privacy' => $privacy === [] ? null : $privacy,
        ], static fn ($value) => $value !== null));

        api_json([
            'ok' => true,
            'settings' => Settings::publicView(),
            'privacy' => [
                'level' => Settings::privacyLevel(),
                'label' => Settings::privacyLabel(),
                'host' => Settings::providerHost(),
            ],
        ]);

    case 'settings.test':
        $client = new Client(api_provider_config($input));
        $result = $client->test();
        api_json($result + ['privacy' => Settings::privacyLevel()]);

    case 'settings.models':
        $client = new Client(api_provider_config($input));
        api_json($client->models() + ['privacy' => Settings::privacyLevel()]);

    // ------------------------------------------------------- сессия создания сценария
    case 'session.create':
        if (!isset($_FILES['sample']) || $_FILES['sample']['error'] !== UPLOAD_ERR_OK) {
            api_fail('Файл-образец не загружен');
        }

        $extension = strtolower(pathinfo((string) $_FILES['sample']['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            api_fail('Поддерживаются файлы XLSX, XLS и CSV');
        }

        $sessionId = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
        $dir = Paths::ensure(api_session_dir($sessionId));
        $samplePath = $dir . '/sample.' . $extension;

        if (!move_uploaded_file($_FILES['sample']['tmp_name'], $samplePath)) {
            api_fail('Не удалось сохранить загруженный файл');
        }

        try {
            $profile = Profiler::profile($samplePath, [
                'sample_rows' => (int) Settings::get('privacy.sample_rows', 3),
                'mask' => (bool) Settings::get('privacy.mask_values', false),
            ]);
        } catch (\Throwable $e) {
            api_fail('Не удалось прочитать файл: ' . $e->getMessage());
        }

        file_put_contents(
            $dir . '/profile.json',
            json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // Необязательный второй файл: справочник, из которого берут данные (прайс для ВПР)
        $lookupProfile = null;
        foreach (glob($dir . '/lookup.*') ?: [] as $old) {
            @unlink($old);
        }
        @unlink($dir . '/lookup_profile.json');

        if (isset($_FILES['lookup']) && $_FILES['lookup']['error'] === UPLOAD_ERR_OK) {
            $lookupExtension = strtolower(pathinfo((string) $_FILES['lookup']['name'], PATHINFO_EXTENSION));
            if (!in_array($lookupExtension, ['xlsx', 'xls', 'csv'], true)) {
                api_fail('Файл-справочник: поддерживаются XLSX, XLS и CSV');
            }

            $lookupPath = $dir . '/lookup.' . $lookupExtension;
            if (!move_uploaded_file($_FILES['lookup']['tmp_name'], $lookupPath)) {
                api_fail('Не удалось сохранить файл-справочник');
            }

            try {
                $lookupProfile = Profiler::profile($lookupPath, [
                    'sample_rows' => max(2, min(3, (int) Settings::get('privacy.sample_rows', 3))),
                    'mask' => (bool) Settings::get('privacy.mask_values', false),
                ]);
            } catch (\Throwable $e) {
                @unlink($lookupPath);
                api_fail('Не удалось прочитать файл-справочник: ' . $e->getMessage());
            }

            file_put_contents(
                $dir . '/lookup_profile.json',
                json_encode($lookupProfile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        api_json([
            'ok' => true,
            'session_id' => $sessionId,
            'profile' => $profile,
            'lookup_profile' => $lookupProfile,
            'lookup_name' => $lookupProfile !== null ? basename((string) $_FILES['lookup']['name']) : '',
            'privacy' => [
                'level' => Settings::privacyLevel(),
                'label' => Settings::privacyLabel(),
                'host' => Settings::providerHost(),
                'confirm_required' => (bool) Settings::get('privacy.confirm_external', true),
            ],
            'provider_configured' => Settings::providerConfigured(),
        ]);

    case 'session.generate':
        $sessionId = (string) ($input['session_id'] ?? '');
        $task = trim((string) ($input['prompt'] ?? ''));
        if ($task === '') {
            api_fail('Не описан текст задачи');
        }

        // Предупреждение о передаче данных внешнему сервису
        if (Settings::privacyLevel() === 'external' && (bool) Settings::get('privacy.confirm_external', true)) {
            if (empty($input['confirm_external'])) {
                api_json([
                    'ok' => false,
                    'need_confirmation' => true,
                    'error' => 'Требуется подтверждение передачи образца внешнему сервису',
                    'privacy' => [
                        'level' => 'external',
                        'host' => Settings::providerHost(),
                        'label' => Settings::privacyLabel(),
                    ],
                ], 409);
            }
        }

        $profile = api_session_profile($sessionId);
        $lookupProfile = api_session_lookup_profile($sessionId);
        $history = api_session_history($sessionId);
        $client = new Client();

        $messages = [
            ['role' => 'system', 'content' => Prompts::system()],
            ['role' => 'user', 'content' => Prompts::user($task, $profile, $history, $lookupProfile)],
        ];

        $response = $client->chat($messages, ['json' => true, 'temperature' => 0]);
        if (!$response['ok']) {
            api_fail($response['error'], 502);
        }

        $parsed = Client::extractJson($response['content']);
        if ($parsed === null) {
            api_fail('Модель вернула ответ, который не удалось разобрать как JSON', 502);
        }

        // Одна попытка исправления, если сценарий не прошёл проверку
        $validation = null;
        $recipe = $parsed['recipe'] ?? null;
        if (is_array($recipe)) {
            $validation = RecipeValidator::validate($recipe);
            if (!$validation['ok']) {
                $repair = $client->chat([
                    ['role' => 'system', 'content' => Prompts::system()],
                    ['role' => 'user', 'content' => Prompts::repair(implode('; ', $validation['errors']), $response['content'])],
                ], ['json' => true, 'temperature' => 0]);

                if ($repair['ok']) {
                    $repaired = Client::extractJson($repair['content']);
                    if (is_array($repaired) && is_array($repaired['recipe'] ?? null)) {
                        $candidate = RecipeValidator::validate($repaired['recipe']);
                        if ($candidate['ok'] || count($candidate['errors']) < count($validation['errors'])) {
                            $parsed = $repaired;
                            $recipe = $repaired['recipe'];
                            $validation = $candidate;
                        }
                    }
                }
            }
        }

        api_session_append($sessionId, 'user', $task);
        api_session_append($sessionId, 'assistant', (string) ($parsed['explanation'] ?? ''));

        api_json([
            'ok' => true,
            'session_id' => $sessionId,
            'recipe' => $recipe,
            'explanation' => (string) ($parsed['explanation'] ?? ''),
            'questions' => (array) ($parsed['questions'] ?? []),
            'warnings' => (array) ($parsed['warnings'] ?? []),
            'validation' => $validation,
            'usage' => $response['usage'],
            'privacy' => [
                'level' => Settings::privacyLevel(),
                'host' => Settings::providerHost(),
            ],
        ]);

    case 'session.dryrun':
        $sessionId = (string) ($input['session_id'] ?? '');
        $recipe = (array) ($input['recipe'] ?? []);
        if ($recipe === []) {
            api_fail('Не передан сценарий для проверки');
        }

        $validation = RecipeValidator::validate($recipe);
        if (!$validation['ok']) {
            api_json(['ok' => false, 'error' => 'Сценарий не прошёл проверку', 'validation' => $validation], 422);
        }

        try {
            $result = Runner::execute($recipe, [
                'dry_run' => true,
                'inputs' => api_session_inputs($sessionId),
            ]);
        } catch (\Throwable $e) {
            api_fail('Ошибка проверки: ' . $e->getMessage(), 500);
        }

        api_json([
            'ok' => $result['ok'],
            'error' => $result['error'],
            'preview' => $result['preview'],
            'summary' => $result['summary'],
            'logs' => $result['logs'],
            'duration' => $result['duration'],
            'validation' => $validation,
        ]);

    case 'session.save':
        $sessionId = (string) ($input['session_id'] ?? '');
        $recipe = (array) ($input['recipe'] ?? []);
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            api_fail('Укажите название сценария');
        }

        $expected = null;
        try {
            $expected = Runner::execute($recipe, [
                'dry_run' => true,
                'inputs' => api_session_inputs($sessionId),
            ]);
            $expected = [
                'summary' => $expected['summary'],
                'preview_rows' => $expected['preview']['rows'],
                'checked_at' => date('c'),
            ];
        } catch (\Throwable) {
            $expected = null;
        }

        // Файл-справочник сохраняется вместе со сценарием: иначе его нельзя перепроверить
        $extraSamples = [];
        $lookupPath = api_session_lookup($sessionId);
        if ($lookupPath !== null) {
            $extraSamples['lookup'] = $lookupPath;
        }

        try {
            $saved = Store::save([
                'name' => $name,
                'description' => (string) ($input['description'] ?? ''),
                'tags' => (array) ($input['tags'] ?? []),
                'recipe' => $recipe,
                'created_by_ai' => (bool) ($input['created_by_ai'] ?? false),
                'provider' => (string) ($input['provider'] ?? Settings::providerHost()),
            ], api_session_sample($sessionId), $expected, $extraSamples);
        } catch (\Throwable $e) {
            api_fail($e->getMessage());
        }

        api_json(['ok' => true, 'recipe_entry' => $saved, 'recipes' => Store::list()]);

    // ------------------------------------------------------- библиотека
    case 'recipes.list':
        api_json(['ok' => true, 'recipes' => Store::list()]);

    case 'recipe.get':
        $entry = Store::get((string) ($_GET['id'] ?? ''));
        if ($entry === null) {
            api_fail('Сценарий не найден', 404);
        }
        api_json(['ok' => true, 'recipe_entry' => $entry]);

    case 'recipe.delete':
        $id = (string) ($input['id'] ?? '');
        api_json(['ok' => Store::delete($id), 'recipes' => Store::list()]);

    case 'recipe.export':
        try {
            $path = Store::export((string) ($_GET['id'] ?? ''), (bool) ($_GET['sample'] ?? false));
        } catch (\Throwable $e) {
            api_fail($e->getMessage(), 404);
        }
        api_send_file($path, basename($path));

    case 'recipe.import':
        if (!isset($_FILES['archive']) || $_FILES['archive']['error'] !== UPLOAD_ERR_OK) {
            api_fail('Архив сценария не загружен');
        }

        $tmp = Paths::ensure(Paths::tmpDir('imports')) . '/' . uniqid('recipe_', true) . '.zip';
        if (!move_uploaded_file($_FILES['archive']['tmp_name'], $tmp)) {
            api_fail('Не удалось сохранить загруженный архив');
        }

        try {
            $entry = Store::import($tmp);
        } catch (\Throwable $e) {
            @unlink($tmp);
            api_fail($e->getMessage());
        }

        @unlink($tmp);
        api_json(['ok' => true, 'recipe_entry' => $entry, 'recipes' => Store::list()]);

    case 'recipe.validate':
        api_json(['ok' => true, 'validation' => RecipeValidator::validate((array) ($input['recipe'] ?? []))]);

    case 'recipe.sample_profile':
        $id = (string) ($_GET['id'] ?? '');
        $entry = Store::get($id);
        if ($entry === null) {
            api_fail('Сценарий не найден', 404);
        }

        $sample = Store::samplePath($id);
        if ($sample === null) {
            api_fail('У этого сценария нет сохранённого образца', 404);
        }

        try {
            // Образец показываем пользователю как есть — маскировка нужна только
            // при отправке данных в модель, а не для просмотра на своём экране.
            $profile = Profiler::profile($sample, [
                'sample_rows' => max(5, (int) Settings::get('privacy.sample_rows', 3)),
                'mask' => false,
            ]);
        } catch (\Throwable $e) {
            api_fail('Не удалось прочитать образец: ' . $e->getMessage());
        }

        api_json(['ok' => true, 'profile' => $profile, 'sample' => $entry['sample']]);

    // ------------------------------------------------------- запуск
    case 'run.start':
        $recipeId = (string) ($input['recipe_id'] ?? ($_POST['recipe_id'] ?? ''));
        $entry = Store::get($recipeId);
        if ($entry === null) {
            api_fail('Сценарий не найден', 404);
        }

        $jobId = Runner::newJobId();
        $jobDir = Paths::ensure(Paths::jobsDir() . '/' . $jobId);

        // Входные файлы задания
        $inputs = [];
        $uploadDir = Paths::ensure($jobDir . '/input');
        foreach ($_FILES as $field => $file) {
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }

            $alias = preg_replace('~^file_~', '', (string) $field) ?: 'input';
            $name = Paths::sanitizeFilename((string) $file['name']);
            $target = $uploadDir . '/' . $alias . '_' . $name;
            if (move_uploaded_file($file['tmp_name'], $target)) {
                $inputs[$alias] = $target;
            }
        }

        // Если файлов не приложили — используем образец сценария
        if ($inputs === []) {
            $sample = Store::samplePath($recipeId);
            if ($sample !== null) {
                $inputs = ['input' => $sample, 'template' => $sample];
            }
        }

        if ($inputs === []) {
            api_fail('Не приложен ни один файл для обработки');
        }

        $params = $input['params'] ?? [];
        if (is_string($params)) {
            $params = json_decode($params, true) ?: [];
        }

        file_put_contents(
            $jobDir . '/job.json',
            json_encode([
                'job_id' => $jobId,
                'recipe_id' => $recipeId,
                'recipe' => $entry['recipe'],
                'options' => [
                    'inputs' => $inputs,
                    'params' => (array) $params,
                    'dry_run' => (bool) ($input['dry_run'] ?? false),
                ],
                'created_at' => date('c'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        file_put_contents($jobDir . '/status.json', json_encode([
            'state' => 'queued',
            'job_id' => $jobId,
            'queued_at' => date('c'),
            'total_steps' => count((array) ($entry['recipe']['steps'] ?? [])),
        ], JSON_UNESCAPED_UNICODE));

        try {
            api_spawn_worker($jobId);
        } catch (\Throwable $e) {
            api_fail($e->getMessage(), 500);
        }

        api_json(['ok' => true, 'job_id' => $jobId, 'status' => api_job_status($jobId)]);

    case 'run.status':
        api_json(['ok' => true, 'status' => api_job_status((string) ($_GET['job_id'] ?? ''))]);

    case 'run.cancel':
        $status = api_job_status((string) ($input['job_id'] ?? ''));
        $pid = (int) ($status['pid'] ?? 0);
        if ($pid > 0) {
            pclose(popen('taskkill /PID ' . $pid . ' /T /F > NUL 2>&1', 'r'));
        }
        api_json(['ok' => true, 'status' => api_job_status((string) ($input['job_id'] ?? ''))]);

    case 'job.files':
        $jobId = preg_replace('~[^a-zA-Z0-9._-]~', '', (string) ($_GET['job_id'] ?? '')) ?? '';
        $outDir = Paths::jobsDir() . '/' . $jobId . '/out';
        $files = [];
        if (is_dir($outDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($outDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $item) {
                /** @var \SplFileInfo $item */
                if (!$item->isFile()) {
                    continue;
                }
                $path = str_replace('\\', '/', $item->getPathname());
                $files[] = [
                    'path' => Paths::relativeToData($path),
                    'relative' => ltrim(substr($path, strlen(str_replace('\\', '/', $outDir))), '/'),
                    'name' => $item->getFilename(),
                    'size' => $item->getSize(),
                    'size_human' => Paths::humanSize($item->getSize()),
                ];
            }
        }

        usort($files, static fn (array $a, array $b) => strcmp($a['relative'], $b['relative']));
        api_json(['ok' => true, 'files' => $files, 'total' => count($files)]);

    case 'job.zip':
        $jobId = preg_replace('~[^a-zA-Z0-9._-]~', '', (string) ($_GET['job_id'] ?? '')) ?? '';
        $outDir = Paths::jobsDir() . '/' . $jobId . '/out';
        if (!is_dir($outDir)) {
            api_fail('Результаты задания не найдены', 404);
        }

        $zipPath = Paths::ensure(Paths::tmpDir('jobs')) . '/' . $jobId . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            api_fail('Не удалось создать архив результатов', 500);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($outDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile()) {
                $zip->addFile($item->getPathname(), str_replace('\\', '/', substr($item->getPathname(), strlen($outDir) + 1)));
            }
        }
        $zip->close();

        api_send_file($zipPath, 'результат_' . $jobId . '.zip');

    case 'file.download':
        $relative = (string) ($_GET['path'] ?? '');
        $path = Paths::dataRoot() . '/' . ltrim(str_replace('\\', '/', $relative), '/');
        if (!is_file($path) || !Paths::isInsideData($path)) {
            api_fail('Файл не найден', 404);
        }
        api_send_file($path, basename($path));

    // ------------------------------------------------------- данные и обновление
    case 'data.backup':
        try {
            $zipPath = api_backup_data();
        } catch (\Throwable $e) {
            api_fail('Не удалось создать резервную копию: ' . $e->getMessage(), 500);
        }
        api_send_file($zipPath, 'Макросыч_данные_' . date('Y-m-d') . '.zip');

    case 'data.open':
        $folder = str_replace('/', '\\', Paths::dataRoot());
        pclose(popen('start "" ' . escapeshellarg($folder), 'r'));
        api_json(['ok' => true, 'data' => Paths::dataRoot()]);

    default:
        api_fail('Неизвестное действие: ' . $action, 404);
}

/** Отдача файла клиенту. */
function api_send_file(string $path, string $downloadName): never
{
    if (!is_file($path)) {
        api_fail('Файл не найден', 404);
    }

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header(
        "Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName)
    );
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

/**
 * Резервная копия каталога данных: сценарии, настройки, загруженные и готовые файлы.
 *
 * Временные файлы (data/tmp) и токен доступа не включаются — они создаются заново.
 * Архив кладётся в data/tmp/backups, поэтому сам в копию не попадает.
 */
function api_backup_data(): string
{
    $dataRoot = Paths::dataRoot();
    $zipPath = Paths::ensure(Paths::tmpDir('backups')) . '/backup_' . date('Ymd-His') . '.zip';

    $zip = new \ZipArchive();
    if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        throw new \RuntimeException('не удалось создать архив');
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dataRoot, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );

    $rootLength = strlen(rtrim(str_replace('\\', '/', $dataRoot), '/')) + 1;
    foreach ($iterator as $item) {
        /** @var \SplFileInfo $item */
        $relative = substr(str_replace('\\', '/', $item->getPathname()), $rootLength);
        if ($relative === '' || $relative === '.token' || explode('/', $relative)[0] === 'tmp') {
            continue;
        }

        if ($item->isDir()) {
            $zip->addEmptyDir($relative);
        } elseif ($item->isFile()) {
            $zip->addFile($item->getPathname(), $relative);
        }
    }

    $zip->close();

    return $zipPath;
}
