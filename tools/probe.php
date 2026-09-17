<?php

declare(strict_types=1);

/**
 * Диагностика подключения к сервису нейросети.
 *
 * Проверяет, по какому адресу сервис отдаёт список моделей и принимает запросы,
 * и делает один короткий пробный запрос. Помогает понять, нужен ли суффикс /v1.
 *
 * Запуск: php tools/probe.php [адрес] [модель]
 * Пример: php tools/probe.php http://127.0.0.1:1234
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Lib\Http;
use App\Lib\Settings;

$base = rtrim($argv[1] ?? (string) Settings::get('provider.base_url', ''), '/');
$model = (string) ($argv[2] ?? Settings::get('provider.model', ''));
$key = (string) Settings::get('provider.api_key', '');

if ($base === '') {
    fwrite(STDERR, "Укажите адрес: php tools/probe.php http://127.0.0.1:1234 [модель]\n");
    exit(1);
}

/**
 * @return array{code: int, body: string, error: string, json: array<string, mixed>|null}
 */
function http(string $url, ?array $json = null, string $key = '', int $timeout = 30): array
{
    $curl = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($key !== '') {
        $headers[] = 'Authorization: Bearer ' . $key;
    }

    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
    ] + Http::sslOptions());

    $body = (string) curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = (string) curl_error($curl);
    curl_close($curl);

    return ['code' => $code, 'body' => $body, 'error' => $error, 'json' => json_decode($body, true)];
}

/** @return string[] */
function modelIds(array $response): array
{
    if (!is_array($response['json'])) {
        return [];
    }

    $items = $response['json']['data'] ?? $response['json']['models'] ?? [];
    $ids = [];
    foreach ((array) $items as $item) {
        $id = is_array($item) ? ($item['id'] ?? $item['name'] ?? $item['model_key'] ?? null) : null;
        if (is_string($id) && $id !== '') {
            $ids[] = $id;
        }
    }

    return $ids;
}

// Кандидаты адреса: как указано, с /v1 и без него
$withoutVersion = preg_replace('~/?v\d+$~', '', $base) ?? $base;
$candidates = array_values(array_unique([$base, $withoutVersion . '/v1']));

echo "Проверяемый адрес: {$base}\n";
echo 'Модель в настройках: ' . ($model !== '' ? $model : '(не указана)') . "\n";
echo 'Ключ: ' . ($key !== '' ? 'задан' : 'пустой') . "\n\n";

$working = null;
$availableModels = [];

foreach ($candidates as $candidate) {
    $url = $candidate . '/models';
    $response = http($url, null, $key, 15);
    $status = $response['code'] > 0 ? "HTTP {$response['code']}" : ('нет ответа: ' . $response['error']);
    $ids = modelIds($response);

    echo "GET {$url} → {$status}, моделей: " . count($ids) . "\n";

    if ($ids !== []) {
        foreach (array_slice($ids, 0, 10) as $id) {
            echo "    - {$id}\n";
        }
        if (count($ids) > 10) {
            echo '    … и ещё ' . (count($ids) - 10) . "\n";
        }

        // Рабочим считаем адрес, который действительно отдаёт список моделей
        if ($working === null) {
            $working = $candidate;
            $availableModels = $ids;
        }
        continue;
    }

    $snippet = trim(mb_substr($response['body'], 0, 160));
    if ($response['code'] !== 200 && $snippet !== '') {
        echo '  ответ: ' . $snippet . "\n";
    } elseif ($response['code'] === 200) {
        echo "  список пуст — адрес не подходит для работы\n";
    }
}

if ($working === null) {
    echo "\nНе удалось найти рабочий адрес. Проверьте, что сервис запущен и слушает указанный порт.\n";
    exit(1);
}

echo "\nРабочий адрес: {$working}\n";

$testModel = $model !== '' ? $model : ($availableModels[0] ?? '');
if ($testModel === '') {
    echo "Не удалось определить модель для пробного запроса.\n";
    exit(1);
}

echo "Пробный запрос к модели «{$testModel}»…\n";

// Запас токенов: у моделей с рассуждениями ответ может быть пустым при малом лимите
$response = http($working . '/chat/completions', [
    'model' => $testModel,
    'messages' => [['role' => 'user', 'content' => 'Ответь одним словом: работает']],
    'max_tokens' => 512,
    'temperature' => 0,
], $key, 180);

echo 'HTTP ' . $response['code'] . "\n";

if ($response['code'] === 200 && is_array($response['json'])) {
    $message = (array) ($response['json']['choices'][0]['message'] ?? []);
    $content = trim((string) ($message['content'] ?? ''));
    $reasoning = trim((string) ($message['reasoning_content'] ?? $message['reasoning'] ?? ''));
    $finish = (string) ($response['json']['choices'][0]['finish_reason'] ?? '');

    if ($content !== '') {
        echo "Ответ модели: {$content}\n";
    } else {
        echo "Модель вернула пустой ответ";
        echo $finish !== '' ? " (finish_reason: {$finish})" : '';
        echo "\n";
        if ($reasoning !== '') {
            echo 'В поле рассуждений: ' . mb_substr($reasoning, 0, 200) . "\n";
            echo "Модель потратила весь лимит токенов на рассуждения — для неё нужен больший лимит.\n";
        }
    }

    echo "\nПодключение работает. Укажите в настройках адрес: {$working}\n";
} else {
    echo 'Ошибка: ' . trim(mb_substr($response['body'], 0, 300)) . "\n";
    exit(1);
}

// --- Отдельная проверка режима JSON: часть сервисов на нём зависает ---
echo "\nПроверка режима JSON (response_format: json_object)…\n";

$jsonStarted = microtime(true);
$jsonResponse = http($working . '/chat/completions', [
    'model' => $testModel,
    'messages' => [['role' => 'user', 'content' => 'Верни JSON вида {"ok": true}']],
    'max_tokens' => 256,
    'temperature' => 0,
    'response_format' => ['type' => 'json_object'],
], $key, 60);
$jsonElapsed = round(microtime(true) - $jsonStarted, 1);

if ($jsonResponse['code'] === 0) {
    echo "ЗАВИСАЕТ: нет ответа за {$jsonElapsed} с\n";
    echo "Вывод: сервис не отвечает в режиме JSON — приложение будет работать без него.\n";
    exit(3);
}

if ($jsonResponse['code'] === 200) {
    echo "Режим JSON поддерживается, ответ за {$jsonElapsed} с\n";
    exit(0);
}

echo "Ошибка режима JSON: HTTP {$jsonResponse['code']} за {$jsonElapsed} с\n";
echo 'Ответ: ' . trim(mb_substr($jsonResponse['body'], 0, 200)) . "\n";
exit(0);
