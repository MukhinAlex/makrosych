<?php

declare(strict_types=1);

namespace App\Llm;

use App\Lib\Http;
use App\Lib\Settings;

/**
 * Клиент нейросети.
 *
 * Поддерживаются два протокола:
 *  - openai    — /chat/completions (OpenAI, RouterAI, Ollama, LM Studio, vLLM, любой совместимый);
 *  - anthropic — /v1/messages (Claude).
 *
 * Локальные серверы (LM Studio, Ollama, llama.cpp, vLLM) отдают совместимый API
 * по пути /v1, поэтому адрес подбирается автоматически: сначала проверяется вариант
 * с /v1, затем без него. Ключ передаётся только в заголовке запроса и нигде
 * не сохраняется в журналах.
 */
final class Client
{
    /** @var array<string, mixed> */
    private array $config;

    private ?string $resolvedBase = null;

    /** @param array<string, mixed>|null $config */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) Settings::get('provider', []);
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['base_url'] ?? '')) !== ''
            && trim((string) ($this->config['model'] ?? '')) !== '';
    }

    /**
     * Протокол сервиса по его адресу.
     *
     * Протокол Claude нужен только самому Anthropic, поэтому он выбирается по адресу:
     * в интерфейсе нет отдельного поля, которое пользователь не может выбрать осознанно.
     * Если сервис отвечает по протоколу Claude на другом адресе (свой прокси), можно
     * задать `"type": "anthropic"` в data/settings.json — это указание имеет приоритет.
     */
    public static function protocolFor(string $baseUrl, string $configuredType = ''): string
    {
        if (strtolower(trim($configuredType)) === 'anthropic') {
            return 'anthropic';
        }

        return str_contains(strtolower($baseUrl), 'anthropic.com') ? 'anthropic' : 'openai';
    }

    /** Адрес, на котором удалось получить ответ. */
    public function resolvedBaseUrl(): string
    {
        return $this->resolvedBase ?? (string) ($this->config['base_url'] ?? '');
    }

    /**
     * Запрос к модели.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options json (bool), temperature (float), max_tokens (int)
     * @return array{ok: bool, content: string, error: string, http: int, usage: array<string, mixed>, base_url: string}
     */
    public function chat(array $messages, array $options = []): array
    {
        if (!$this->isConfigured()) {
            return $this->failure('Не настроен провайдер нейросети (адрес и модель)');
        }

        if (self::protocolFor(
            (string) ($this->config['base_url'] ?? ''),
            (string) ($this->config['type'] ?? '')
        ) === 'anthropic') {
            return $this->chatAnthropic($messages, $options);
        }

        $last = null;
        foreach ($this->candidateBases() as $base) {
            $result = $this->chatOpenAi($base, $messages, $options);
            if ($result['ok']) {
                $this->resolvedBase = $base;

                return $result;
            }

            // Ошибка «не тот адрес» — пробуем следующий вариант.
            // Ошибка уровня модели (например, неизвестное имя) адресом не исправляется.
            if (!$this->looksLikeWrongEndpoint($result)) {
                return $result;
            }

            $last = $result;
        }

        return $last ?? $this->failure('Не удалось обратиться к сервису нейросети');
    }

    /**
     * Список доступных моделей сервиса.
     *
     * @return array{ok: bool, models: string[], error: string, base_url: string}
     */
    public function models(): array
    {
        if (trim((string) ($this->config['base_url'] ?? '')) === '') {
            return ['ok' => false, 'models' => [], 'error' => 'Не указан адрес сервиса', 'base_url' => ''];
        }

        $lastError = '';
        foreach ($this->candidateBases() as $candidate) {
            $response = $this->send('GET', $candidate . '/models', $this->headers(), null);

            if ($response['http'] === 200 && is_array($response['data'])) {
                $models = $this->extractModels($response['data']);
                if ($models !== []) {
                    $this->resolvedBase = $candidate;

                    return ['ok' => true, 'models' => $models, 'error' => '', 'base_url' => $candidate];
                }
            }

            $lastError = $response['error'] !== ''
                ? $response['error']
                : "Сервис не вернул список моделей (HTTP {$response['http']})";
        }

        return ['ok' => false, 'models' => [], 'error' => $lastError, 'base_url' => ''];
    }

    /** Проверка соединения. */
    public function test(): array
    {
        $result = $this->chat([
            ['role' => 'user', 'content' => 'Ответь одним словом: работает'],
        ], ['json' => false, 'max_tokens' => 512]);

        if (!$result['ok']) {
            return $result;
        }

        return [
            'ok' => true,
            'content' => $result['content'],
            'error' => '',
            'http' => $result['http'],
            'usage' => $result['usage'],
            'model' => (string) ($this->config['model'] ?? ''),
            'host' => (string) (parse_url((string) ($this->config['base_url'] ?? ''), PHP_URL_HOST) ?: ''),
            'base_url' => $this->resolvedBaseUrl(),
        ];
    }

    /** Извлекает JSON-объект из ответа модели. */
    public static function extractJson(string $content): ?array
    {
        $content = trim($content);

        if (preg_match('~```(?:json)?\s*(.+?)```~si', $content, $matches) === 1) {
            $content = trim($matches[1]);
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Варианты адреса сервиса в порядке проверки.
     *
     * @return string[]
     */
    public function candidateBases(): array
    {
        $base = rtrim((string) ($this->config['base_url'] ?? ''), '/');
        if ($base === '') {
            return [];
        }

        // Версия уже указана — используем адрес как есть
        if (preg_match('~/v\d+$~', $base) === 1) {
            return [$base];
        }

        return array_values(array_unique([$base . '/v1', $base]));
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return array{ok: bool, content: string, error: string, http: int, usage: array<string, mixed>, base_url: string}
     */
    private function chatOpenAi(string $base, array $messages, array $options): array
    {
        $json = (bool) ($options['json'] ?? true);

        $body = [
            'model' => (string) $this->config['model'],
            'messages' => $messages,
            'temperature' => (float) ($options['temperature'] ?? ($this->config['temperature'] ?? 0)),
            // Ограничение обязательно: без него модель может отвечать неограниченно долго
            'max_tokens' => (int) ($options['max_tokens'] ?? ($this->config['max_tokens'] ?? 8192)),
        ];

        if ($json) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $url = $base . '/chat/completions';
        $response = $this->send('POST', $url, $this->headers(), $body);

        // Некоторые сервисы не поддерживают response_format — повторяем без него
        if (!$response['ok'] && $json && str_contains(mb_strtolower($response['error']), 'response_format')) {
            unset($body['response_format']);
            $response = $this->send('POST', $url, $this->headers(), $body);
        }

        if (!$response['ok']) {
            return $this->failure($response['error'], $response['http'], $base);
        }

        $choice = (array) ($response['data']['choices'][0] ?? []);
        $message = (array) ($choice['message'] ?? []);
        $content = (string) ($message['content'] ?? '');
        if ($content === '') {
            $content = (string) ($choice['text'] ?? '');
        }

        if ($content === '') {
            $reasoning = (string) ($message['reasoning_content'] ?? $message['reasoning'] ?? '');
            $finish = (string) ($choice['finish_reason'] ?? '');

            if ($reasoning !== '' || $finish === 'length') {
                $hint = ' Похоже, модель «с рассуждениями» израсходовала весь лимит токенов на размышления.'
                    . ' Увеличьте лимит ответа в настройках либо выберите модель без режима рассуждений'
                    . ' (обычно в названии есть «instruct»).';
            } else {
                $hint = ' Сервис вернул пустой ответ — попробуйте другую модель.';
            }

            return $this->failure('Модель вернула пустой ответ.' . $hint, $response['http'], $base);
        }

        return [
            'ok' => true,
            'content' => $content,
            'error' => '',
            'http' => $response['http'],
            'usage' => (array) ($response['data']['usage'] ?? []),
            'base_url' => $base,
        ];
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return array{ok: bool, content: string, error: string, http: int, usage: array<string, mixed>, base_url: string}
     */
    private function chatAnthropic(array $messages, array $options): array
    {
        $base = rtrim((string) $this->config['base_url'], '/');
        $url = str_contains($base, '/v1') ? $base . '/messages' : $base . '/v1/messages';

        $system = '';
        $dialog = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'system') {
                $system .= ($system === '' ? '' : "\n\n") . $message['content'];
                continue;
            }
            $dialog[] = $message;
        }

        $body = [
            'model' => (string) $this->config['model'],
            'max_tokens' => (int) ($options['max_tokens'] ?? 8192),
            'messages' => $dialog,
            'temperature' => (float) ($options['temperature'] ?? ($this->config['temperature'] ?? 0)),
        ];
        if ($system !== '') {
            $body['system'] = $system;
        }

        $headers = [
            'Content-Type: application/json',
            'anthropic-version: 2023-06-01',
        ];
        $key = trim((string) ($this->config['api_key'] ?? ''));
        if ($key !== '') {
            $headers[] = 'x-api-key: ' . $key;
        }

        $response = $this->send('POST', $url, $headers, $body);
        if (!$response['ok']) {
            return $this->failure($response['error'], $response['http'], $base);
        }

        $parts = [];
        foreach ((array) ($response['data']['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
            }
        }
        $content = implode("\n", $parts);

        if ($content === '') {
            return $this->failure('Модель вернула пустой ответ', $response['http'], $base);
        }

        return [
            'ok' => true,
            'content' => $content,
            'error' => '',
            'http' => $response['http'],
            'usage' => (array) ($response['data']['usage'] ?? []),
            'base_url' => $base,
        ];
    }

    /** @return array<int, string> */
    private function headers(): array
    {
        $headers = ['Content-Type: application/json'];
        $key = trim((string) ($this->config['api_key'] ?? ''));
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }

        return $headers;
    }

    /**
     * Ошибка похожа на «не тот адрес сервиса» — имеет смысл попробовать другой вариант.
     *
     * @param array<string, mixed> $result
     */
    private function looksLikeWrongEndpoint(array $result): bool
    {
        if ((int) ($result['http'] ?? 0) === 404) {
            return true;
        }

        $message = mb_strtolower((string) ($result['error'] ?? ''));
        foreach (['unexpected endpoint', 'not found', 'unknown endpoint', 'no route', 'method not allowed'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $headers
     * @param array<string, mixed>|null $body
     * @return array{ok: bool, http: int, data: array<string, mixed>, error: string}
     */
    private function send(string $method, string $url, array $headers, ?array $body): array
    {
        $timeout = max(10, (int) ($this->config['timeout'] ?? 180));

        $curl = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ] + Http::sslOptions();

        curl_setopt_array($curl, $options);

        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt(
                $curl,
                CURLOPT_POSTFIELDS,
                json_encode($body ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $raw = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = (string) curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            $hint = str_contains($curlError, 'SSL') || str_contains($curlError, 'certificate')
                ? ' Сертификат сервиса не прошёл проверку. Так бывает, когда защищённые соединения '
                    . 'проверяет антивирус или прокси: добавьте адрес сервиса в его исключения.'
                : ' Проверьте адрес сервиса и подключение к интернету.';

            return [
                'ok' => false,
                'http' => $httpCode,
                'data' => [],
                'error' => 'Ошибка соединения: ' . ($curlError !== '' ? $curlError : 'неизвестная ошибка') . '.' . $hint,
            ];
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return [
                'ok' => false,
                'http' => $httpCode,
                'data' => [],
                'error' => "Некорректный ответ сервиса (HTTP {$httpCode})",
            ];
        }

        if ($httpCode >= 400 || isset($data['error'])) {
            $errorNode = $data['error'] ?? null;

            if (is_array($errorNode)) {
                $message = $errorNode['message'] ?? $errorNode['type'] ?? json_encode($errorNode, JSON_UNESCAPED_UNICODE);
            } elseif (is_string($errorNode) && $errorNode !== '') {
                $message = $errorNode;
            } else {
                $message = $data['message'] ?? "HTTP {$httpCode}";
            }

            if (is_array($message)) {
                $message = json_encode($message, JSON_UNESCAPED_UNICODE);
            }

            return ['ok' => false, 'http' => $httpCode, 'data' => $data, 'error' => 'Сервис вернул ошибку: ' . $message];
        }

        return ['ok' => true, 'http' => $httpCode, 'data' => $data, 'error' => ''];
    }

    /** @return array<int, string> */
    private function extractModels(array $data): array
    {
        $items = $data['data'] ?? $data['models'] ?? [];
        $models = [];

        foreach ((array) $items as $item) {
            $id = is_array($item) ? ($item['id'] ?? $item['name'] ?? $item['model_key'] ?? null) : null;
            if (is_string($id) && $id !== '') {
                $models[] = $id;
            }
        }

        sort($models);

        return array_values(array_unique($models));
    }

    /**
     * @return array{ok: bool, content: string, error: string, http: int, usage: array<string, mixed>, base_url: string}
     */
    private function failure(string $error, int $http = 0, string $base = ''): array
    {
        return ['ok' => false, 'content' => '', 'error' => $error, 'http' => $http, 'usage' => [], 'base_url' => $base];
    }
}
