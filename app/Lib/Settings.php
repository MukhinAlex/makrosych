<?php

declare(strict_types=1);

namespace App\Lib;

/**
 * Настройки приложения: провайдер нейросети, приватность, интерфейс.
 *
 * Ключ API хранится только в program/data/settings.json на этой машине.
 * Он не включается в экспортируемые сценарии и не пишется в журналы.
 */
final class Settings
{
    public const DEFAULTS = [
        'provider' => [
            // Протокол выбирается по адресу сервиса (см. Client::protocolFor).
            // 'anthropic' здесь — только ручное указание для своего прокси.
            'type' => '',
            'base_url' => 'https://routerai.ru/api/v1',
            'model' => '',
            'api_key' => '',
            // Локальные модели на большом запросе отвечают медленно
            'timeout' => 600,
            'temperature' => 0,
            // Ограничение длины ответа. Для моделей «с рассуждениями» нужен большой запас:
            // они тратят лимит на размышления, и на сам ответ ничего не остаётся
            'max_tokens' => 16384,
        ],
        'privacy' => [
            'sample_rows' => 3,
            'mask_values' => false,
            'confirm_external' => true,
        ],
        // Внешний вид интерфейса: размер текста (normal, large, xlarge).
        'ui' => [
            'scale' => 'normal',
        ],
    ];

    /** Допустимые значения масштаба текста. */
    public const UI_SCALES = ['normal', 'large', 'xlarge'];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stored = [];
        $file = Paths::settingsFile();
        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }

        self::$cache = self::merge(self::DEFAULTS, $stored);

        return self::$cache;
    }

    public static function save(array $values): array
    {
        $current = self::all();
        $merged = self::merge($current, $values);

        Paths::ensure(Paths::dataRoot());
        file_put_contents(
            Paths::settingsFile(),
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        self::$cache = $merged;

        return $merged;
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        $value = self::all();
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /** Настройки для интерфейса: без ключа API. */
    public static function publicView(): array
    {
        $all = self::all();
        $key = (string) ($all['provider']['api_key'] ?? '');
        $all['provider']['api_key'] = '';
        $all['provider']['api_key_set'] = $key !== '';
        $all['provider']['api_key_hint'] = $key === '' ? '' : self::maskKey($key);

        return $all;
    }

    public static function maskKey(string $key): string
    {
        if (strlen($key) <= 8) {
            return str_repeat('•', strlen($key));
        }

        return substr($key, 0, 4) . str_repeat('•', 6) . substr($key, -4);
    }

    /**
     * Уровень приватности провайдера.
     *
     * local    — модель на этом же компьютере, данные не покидают его;
     * private  — адрес во внутренней сети, данные остаются в компании;
     * external — публичный сервис, требуется предупреждение пользователя.
     */
    public static function privacyLevel(): string
    {
        $host = self::providerHost();

        if ($host === '') {
            return 'external';
        }

        $host = strtolower($host);
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return 'local';
        }

        if (preg_match('~^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.)~', $host)) {
            return 'private';
        }

        if (str_ends_with($host, '.local') || str_ends_with($host, '.lan') || str_ends_with($host, '.internal')) {
            return 'private';
        }

        return 'external';
    }

    public static function privacyLabel(): string
    {
        return match (self::privacyLevel()) {
            'local' => 'Локальная модель — данные не покидают компьютер',
            'private' => 'Внутренняя сеть — данные остаются в компании',
            default => 'Внешний сервис — данные уйдут третьей стороне',
        };
    }

    public static function providerHost(): string
    {
        $url = (string) self::get('provider.base_url', '');
        if ($url === '') {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    public static function providerConfigured(): bool
    {
        return (string) self::get('provider.model', '') !== ''
            && (string) self::get('provider.base_url', '') !== '';
    }

    /** Слияние с заменой значений по ключам, кроме пустой строки поверх непустой. */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
                continue;
            }

            if ($key === 'api_key' && $value === '' && isset($base[$key]) && $base[$key] !== '') {
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
