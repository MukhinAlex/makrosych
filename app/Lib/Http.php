<?php

declare(strict_types=1);

namespace App\Lib;

/**
 * Сетевые операции: HTTP-запросы, скачивание файлов, разрешение ссылок Яндекс Диска.
 *
 * Логика разрешения публичных ссылок Яндекс Диска перенесена из yandex_disk.php:
 * ссылки вида yadi.sk/d/... и yadi.sk/i/... нельзя скачать напрямую через curl,
 * требуется публичный API https://cloud-api.yandex.net/v1/disk/public/resources
 */
final class Http
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

    /**
     * Путь к CA-бандлу, который поставляется вместе с программой.
     *
     * Указывается явно, чтобы https работал при любом рабочем каталоге: значение
     * curl.cainfo из php.ini разрешается относительно каталога запуска, а сборка
     * должна работать и при запуске из другого места.
     */
    public static function caBundle(): string
    {
        if (!defined('APP_ROOT')) {
            return '';
        }

        $bundle = APP_ROOT . '/runtime/php/extras/ssl/cacert.pem';

        return is_file($bundle) ? $bundle : '';
    }

    /**
     * Настройки проверки сертификатов для curl.
     *
     * Кроме собственного бандла подключается системное хранилище сертификатов
     * Windows. Это нужно из-за антивирусов и корпоративных прокси: они проверяют
     * защищённые соединения и подписывают их своим корневым сертификатом, которого
     * в бандле Mozilla нет, — без системного хранилища запрос не проходит проверку.
     * Проверка сертификата при этом остаётся включённой: поддельный, просроченный
     * или выданный чужому имени сертификат по-прежнему отвергается.
     *
     * @return array<int, mixed>
     */
    public static function sslOptions(): array
    {
        $options = [];

        $bundle = self::caBundle();
        if ($bundle !== '') {
            $options[CURLOPT_CAINFO] = $bundle;
        }

        if (defined('CURLSSLOPT_NATIVE_CA')) {
            $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }

        return $options;
    }

    public static function get(string $url, int $timeout = 30): string|false
    {
        $curl = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ] + self::sslOptions();

        curl_setopt_array($curl, $options);

        $data = curl_exec($curl);
        curl_close($curl);

        return ($data === false || $data === '') ? false : $data;
    }

    /**
     * Проверка, что значение — ссылка http/https.
     *
     * Обычная filter_var не принимает адреса с кириллическим доменом
     * (например https://игрушкиоптом.рф/photo/1.jpg), поэтому имя хоста
     * проверяется отдельно и при возможности переводится в punycode.
     */
    public static function isUrl(string $url): bool
    {
        $url = trim($url, " \t\n\r\"'");

        if ($url === '' || preg_match('~[\s\x00-\x1F]~u', $url) === 1) {
            return false;
        }

        if (preg_match('~^(https?)://([^/?#]+)(.*)$~iu', $url, $matches) !== 1) {
            return false;
        }

        $host = $matches[2];
        $port = '';
        if (preg_match('~^(.+?)(:\d+)$~', $host, $parts) === 1) {
            $host = $parts[1];
            $port = $parts[2];
        }

        if ($host === '' || str_contains($host, '..')) {
            return false;
        }

        // Обычное имя хоста: проверяем строгими средствами PHP
        // (путь может содержать кириллицу, поэтому проверяем только адрес сайта)
        if (preg_match('~^[\x20-\x7E]+$~', $host) === 1) {
            return filter_var($matches[1] . '://' . $host . $port . '/', FILTER_VALIDATE_URL) !== false;
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (!is_string($ascii) || $ascii === '') {
                return false;
            }

            return filter_var($matches[1] . '://' . $ascii . $port . '/', FILTER_VALIDATE_URL) !== false;
        }

        // Без расширения intl доверяем схеме и непустому имени хоста: curl такой адрес разрешает
        return true;
    }

    /**
     * Скачивание файла с повторными попытками.
     *
     * @return array{success: bool, size: int, error: string, attempts: int}
     */
    public static function download(string $url, string $path, int $timeout = 60, int $retries = 2): array
    {
        if ($url === '' || !self::isUrl($url)) {
            return ['success' => false, 'size' => 0, 'error' => 'Некорректная ссылка', 'attempts' => 0];
        }

        $directory = dirname($path);
        if ($directory !== '' && !is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        $attempts = max(1, $retries);
        $lastError = '';
        $ssl = self::sslOptions();

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($attempt > 1) {
                sleep(1);
            }

            $curl = curl_init($url);
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT => self::USER_AGENT,
            ] + $ssl;

            curl_setopt_array($curl, $options);

            $data = curl_exec($curl);
            $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = (string) curl_error($curl);
            curl_close($curl);

            if ($httpCode === 200 && $data !== false && strlen((string) $data) > 0) {
                if (@file_put_contents($path, $data) === false) {
                    return ['success' => false, 'size' => 0, 'error' => 'Не удалось записать файл', 'attempts' => $attempt];
                }

                return ['success' => true, 'size' => strlen((string) $data), 'error' => '', 'attempts' => $attempt];
            }

            $lastError = $httpCode === 200
                ? 'Пустой файл (0 байт)'
                : "HTTP {$httpCode}" . ($error !== '' ? " — {$error}" : '');
        }

        return ['success' => false, 'size' => 0, 'error' => $lastError, 'attempts' => $attempts];
    }

    public static function isYandex(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_contains($host, 'yadi.sk') || str_contains($host, 'disk.yandex');
    }

    /**
     * Разрешение ссылки в список файлов.
     *
     * @return array{success: bool, files: array<int, array{name: string, url: string, size: int}>, error: string}
     */
    public static function resolve(string $url): array
    {
        $url = trim($url, " \"';,\t\n\r\0\x0B");
        if ($url === '' || !self::isUrl($url)) {
            return ['success' => false, 'files' => [], 'error' => 'Некорректная ссылка'];
        }

        if (!self::isYandex($url)) {
            $name = basename((string) (parse_url($url, PHP_URL_PATH) ?: ''));

            return [
                'success' => true,
                'files' => [[
                    'name' => $name !== '' ? Paths::sanitizeFilename($name) : 'file',
                    'url' => $url,
                    'size' => 0,
                ]],
                'error' => '',
            ];
        }

        return self::resolveYandexPublic($url, '/', 0);
    }

    /** @return array{success: bool, files: array<int, array{name: string, url: string, size: int}>, error: string} */
    private static function resolveYandexPublic(string $publicUrl, string $path, int $depth): array
    {
        if ($depth > 10) {
            return ['success' => false, 'files' => [], 'error' => 'Слишком большая вложенность папок'];
        }

        $apiUrl = 'https://cloud-api.yandex.net/v1/disk/public/resources?public_key=' . urlencode($publicUrl);
        if ($path !== '/') {
            $apiUrl .= '&path=' . urlencode($path);
        }

        $response = self::get($apiUrl);
        if ($response === false) {
            return ['success' => false, 'files' => [], 'error' => 'Не удалось обратиться к API Яндекс Диска'];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['success' => false, 'files' => [], 'error' => 'Некорректный ответ API Яндекс Диска'];
        }

        if (isset($data['error'])) {
            $code = (string) $data['error'];
            $message = (string) ($data['message'] ?? $data['description'] ?? '');

            return [
                'success' => false,
                'files' => [],
                'error' => "Ошибка API: {$code}" . ($message !== '' ? " ({$message})" : ''),
            ];
        }

        if (($data['type'] ?? '') === 'file') {
            return [
                'success' => true,
                'files' => [[
                    'name' => Paths::sanitizeFilename((string) ($data['name'] ?? 'file')),
                    'url' => (string) ($data['file'] ?? ''),
                    'size' => (int) ($data['size'] ?? 0),
                ]],
                'error' => '',
            ];
        }

        if (isset($data['_embedded']['items']) && is_array($data['_embedded']['items'])) {
            $files = [];
            foreach ($data['_embedded']['items'] as $item) {
                $type = $item['type'] ?? 'file';
                if ($type === 'file') {
                    $files[] = [
                        'name' => Paths::sanitizeFilename((string) ($item['name'] ?? 'file')),
                        'url' => (string) ($item['file'] ?? ''),
                        'size' => (int) ($item['size'] ?? 0),
                    ];
                } elseif ($type === 'dir') {
                    $sub = self::resolveYandexPublic($publicUrl, (string) ($item['path'] ?? $path), $depth + 1);
                    if ($sub['success']) {
                        $files = array_merge($files, $sub['files']);
                    }
                }
            }

            return ['success' => true, 'files' => $files, 'error' => ''];
        }

        return ['success' => false, 'files' => [], 'error' => 'Не удалось определить тип ресурса'];
    }

    /** Расширение файла по ссылке. */
    public static function guessExtension(string $url, string $fallback = 'jpg'): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if ($extension !== '' && preg_match('~^[a-z0-9]{1,5}$~', $extension) === 1) {
            return $extension;
        }

        return $fallback;
    }
}
