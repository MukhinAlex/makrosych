<?php

declare(strict_types=1);

namespace App\Lib;

use App\Engine\Ops;

/**
 * Библиотека сценариев пользователя.
 *
 * Один сценарий — одна папка в data/recipes/<id>/:
 *   recipe.json   — сам сценарий
 *   meta.json     — имя, описание, теги, версия, статистика запусков
 *   README.md     — описание, сгенерированное из метаданных
 *   sample.*      — образец, на котором сценарий проверялся (необязательно)
 *   expected.json — ожидаемый результат проверки (необязательно)
 *   runs/         — журнал запусков
 */
final class Store
{
    /** Служебные файлы папки сценария — всё остальное считается дополнительным образцом. */
    private const SERVICE_FILES = ['recipe.json', 'meta.json', 'README.md', 'expected.json'];

    private const TRANSLIT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
        'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    /** @return array<int, array<string, mixed>> */
    public static function list(): array
    {
        $index = self::readIndex();
        if ($index === []) {
            return self::refreshIndex();
        }

        // Индекс, созданный прежней версией программы, не знает про образец —
        // дополняем на месте, чтобы кнопка «Образец» не пропадала.
        foreach ($index as $position => $entry) {
            if (!array_key_exists('has_sample', $entry)) {
                $index[$position]['has_sample'] = self::samplePath((string) ($entry['id'] ?? '')) !== null;
            }
        }

        usort($index, static fn (array $a, array $b) => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));

        return $index;
    }

    /** @return array<string, mixed>|null */
    public static function get(string $id): ?array
    {
        $dir = self::recipeDir($id);
        if (!is_dir($dir)) {
            return null;
        }

        $recipe = self::readJson($dir . '/recipe.json');
        if ($recipe === null) {
            return null;
        }

        $meta = self::readJson($dir . '/meta.json') ?? [];
        $sample = self::samplePath($id);

        $extraSamples = [];
        foreach (self::extraSampleFiles($id) as $alias => $file) {
            $extraSamples[] = [
                'alias' => $alias,
                'path' => Paths::relativeToData($file),
                'name' => basename($file),
                'size_human' => Paths::humanSize((int) filesize($file)),
            ];
        }

        return [
            'id' => $id,
            'meta' => $meta,
            'recipe' => $recipe,
            'extra_samples' => $extraSamples,
            'has_sample' => $sample !== null,
            'sample' => $sample !== null ? [
                'path' => Paths::relativeToData($sample),
                'name' => basename($sample),
                'size_human' => Paths::humanSize((int) filesize($sample)),
            ] : null,
            'runs' => self::runs($id, 10),
        ];
    }

    /**
     * Сохранение сценария.
     *
     * @param array<string, mixed> $payload name, description, tags, recipe, id (для обновления)
     * @return array<string, mixed> Сохранённая запись
     */
    public static function save(array $payload, ?string $samplePath = null, ?array $expected = null, array $extraSamples = []): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Не задано имя сценария');
        }

        $recipe = (array) ($payload['recipe'] ?? []);
        $recipe['name'] = $name;
        $recipe['description'] = (string) ($payload['description'] ?? ($recipe['description'] ?? ''));

        $validation = RecipeValidator::validate($recipe);
        if (!$validation['ok']) {
            throw new \RuntimeException('Сценарий не прошёл проверку: ' . implode('; ', $validation['errors']));
        }

        $id = (string) ($payload['id'] ?? '');
        $isNew = $id === '';
        if ($isNew) {
            $id = self::uniqueId(self::slug($name));
        }

        $dir = Paths::ensure(self::recipeDir($id));
        $existing = self::readJson($dir . '/meta.json') ?? [];

        $meta = [
            'id' => $id,
            'name' => $name,
            'description' => (string) ($payload['description'] ?? ''),
            'tags' => array_values(array_map('strval', (array) ($payload['tags'] ?? []))),
            'created_at' => (string) ($existing['created_at'] ?? date('c')),
            'updated_at' => date('c'),
            'version' => $isNew ? 1 : ((int) ($existing['version'] ?? 1) + 1),
            'uses_ai' => (bool) $validation['uses_ai'],
            'uses_network' => (bool) $validation['uses_network'],
            'ops' => $validation['ops'],
            'runs_count' => (int) ($existing['runs_count'] ?? 0),
            'last_run_at' => $existing['last_run_at'] ?? null,
            'last_run_ok' => $existing['last_run_ok'] ?? null,
            'created_by_ai' => (bool) ($payload['created_by_ai'] ?? false),
            'provider' => (string) ($payload['provider'] ?? ''),
        ];

        self::writeJson($dir . '/recipe.json', $recipe);
        self::writeJson($dir . '/meta.json', $meta);

        if ($samplePath !== null && is_file($samplePath)) {
            $extension = strtolower(pathinfo($samplePath, PATHINFO_EXTENSION));
            foreach (glob($dir . '/sample.*') ?: [] as $old) {
                @unlink($old);
            }
            copy($samplePath, $dir . '/sample.' . $extension);
        }

        // Дополнительные файлы сценария (например, справочник «lookup» для ВПР)
        foreach ($extraSamples as $alias => $path) {
            $alias = (string) preg_replace('~[^a-zA-Z0-9_]~', '', (string) $alias);
            if ($alias === '' || !is_file((string) $path)) {
                continue;
            }

            $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
            foreach (glob($dir . '/' . $alias . '.*') ?: [] as $old) {
                @unlink($old);
            }
            copy((string) $path, $dir . '/' . $alias . '.' . $extension);
        }

        if ($expected !== null) {
            self::writeJson($dir . '/expected.json', $expected);
        }

        file_put_contents($dir . '/README.md', self::renderReadme($meta, $recipe));

        self::writeIndex();
        $saved = self::get($id);

        return $saved ?? ['id' => $id, 'meta' => $meta, 'recipe' => $recipe];
    }

    public static function delete(string $id): bool
    {
        $dir = self::recipeDir($id);
        if (!is_dir($dir)) {
            return false;
        }

        Paths::removeDir($dir);
        self::writeIndex();

        return true;
    }

    public static function recordRun(string $id, array $run): void
    {
        $dir = self::recipeDir($id);
        if (!is_dir($dir)) {
            return;
        }

        Paths::ensure($dir . '/runs');
        $jobId = (string) ($run['job_id'] ?? date('Ymd-His'));
        self::writeJson($dir . '/runs/' . $jobId . '.json', $run + ['finished_at' => date('c')]);

        $meta = self::readJson($dir . '/meta.json') ?? [];
        $meta['runs_count'] = (int) ($meta['runs_count'] ?? 0) + 1;
        $meta['last_run_at'] = date('c');
        $meta['last_run_ok'] = (bool) ($run['ok'] ?? false);
        self::writeJson($dir . '/meta.json', $meta);

        self::writeIndex();
    }

    /** @return array<int, array<string, mixed>> */
    public static function runs(string $id, int $limit = 20): array
    {
        $dir = self::recipeDir($id) . '/runs';
        if (!is_dir($dir)) {
            return [];
        }

        $runs = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $run = self::readJson($file);
            if ($run !== null) {
                $runs[] = $run;
            }
        }

        usort($runs, static fn (array $a, array $b) => strcmp((string) ($b['finished_at'] ?? ''), (string) ($a['finished_at'] ?? '')));

        return array_slice($runs, 0, $limit);
    }

    /** Экспорт сценария в ZIP. Ключи API и настройки не включаются. */
    public static function export(string $id, bool $includeSample = false): string
    {
        $dir = self::recipeDir($id);
        if (!is_dir($dir)) {
            throw new \RuntimeException("Сценарий не найден: {$id}");
        }

        $exportDir = Paths::ensure(Paths::tmpDir('exports'));
        $zipPath = $exportDir . '/' . $id . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Не удалось создать архив экспорта');
        }

        foreach (['recipe.json', 'meta.json', 'README.md', 'expected.json'] as $name) {
            if (is_file($dir . '/' . $name)) {
                $zip->addFile($dir . '/' . $name, $name);
            }
        }

        if ($includeSample) {
            foreach (glob($dir . '/sample.*') ?: [] as $sample) {
                $zip->addFile($sample, 'sample.' . pathinfo($sample, PATHINFO_EXTENSION));
            }

            // Дополнительные образцы (файл-справочник) — чтобы сценарий был полным у коллеги
            foreach (self::extraSampleFiles($id) as $alias => $file) {
                $zip->addFile($file, $alias . '.' . pathinfo($file, PATHINFO_EXTENSION));
            }
        }

        $zip->close();

        return $zipPath;
    }

    /** @return array<string, mixed> */
    public static function import(string $zipPath): array
    {
        if (!is_file($zipPath)) {
            throw new \RuntimeException('Файл сценария не найден');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Не удалось открыть архив сценария');
        }

        $recipeJson = $zip->getFromName('recipe.json');
        $metaJson = $zip->getFromName('meta.json');
        if ($recipeJson === false) {
            $zip->close();
            throw new \RuntimeException('В архиве нет файла recipe.json');
        }

        $recipe = json_decode($recipeJson, true);
        $meta = $metaJson !== false ? json_decode($metaJson, true) : [];
        if (!is_array($recipe)) {
            $zip->close();
            throw new \RuntimeException('Некорректный сценарий в архиве');
        }

        $name = (string) ($recipe['name'] ?? ($meta['name'] ?? 'Импортированный сценарий'));
        $id = self::uniqueId(self::slug($name));
        $dir = Paths::ensure(self::recipeDir($id));

        foreach (['recipe.json', 'meta.json', 'README.md', 'expected.json'] as $entry) {
            $content = $zip->getFromName($entry);
            if ($content !== false) {
                file_put_contents($dir . '/' . $entry, $content);
            }
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = (string) $zip->getNameIndex($index);
            $base = basename($entry);

            // Образцы: основной (sample.*) и дополнительные (файл-справочник)
            if (str_contains($entry, '/') || in_array($base, self::SERVICE_FILES, true)) {
                continue;
            }

            $content = $zip->getFromIndex($index);
            if ($content !== false && $content !== '') {
                file_put_contents($dir . '/' . $base, $content);
            }
        }

        $zip->close();

        $saved = self::get($id) ?? ['id' => $id, 'recipe' => $recipe, 'meta' => []];

        return $saved;
    }

    /** Пересборка индекса по содержимому папок. */
    public static function refreshIndex(): array
    {
        $entries = [];
        foreach (glob(Paths::recipesDir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $meta = self::readJson($dir . '/meta.json');
            if ($meta === null) {
                continue;
            }
            $meta['has_sample'] = glob($dir . '/sample.*') !== [];
            $entries[] = $meta;
        }

        self::writeJson(self::indexFile(), $entries);
        usort($entries, static fn (array $a, array $b) => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));

        return $entries;
    }

    public static function recipeDir(string $id): string
    {
        return Paths::recipesDir() . '/' . self::safeId($id);
    }

    public static function samplePath(string $id): ?string
    {
        $found = glob(self::recipeDir($id) . '/sample.*') ?: [];

        return $found[0] ?? null;
    }

    /**
     * Дополнительные файлы сценария (например, файл-справочник «lookup»): псевдоним => путь.
     *
     * @return array<string, string>
     */
    public static function extraSampleFiles(string $id): array
    {
        $dir = self::recipeDir($id);
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $base = basename($file);
            if (str_starts_with($base, 'sample.') || in_array($base, self::SERVICE_FILES, true)) {
                continue;
            }

            $files[(string) pathinfo($base, PATHINFO_FILENAME)] = $file;
        }

        ksort($files);

        return $files;
    }

    public static function slug(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = strtr($name, self::TRANSLIT);
        $name = preg_replace('~[^a-z0-9]+~', '-', $name) ?? '';
        $name = trim($name, '-');

        return $name !== '' ? mb_substr($name, 0, 60) : 'scenario';
    }

    private static function safeId(string $id): string
    {
        return preg_replace('~[^a-zA-Z0-9._-]~', '', $id) ?? 'scenario';
    }

    private static function uniqueId(string $base): string
    {
        $id = $base;
        $suffix = 2;
        while (is_dir(self::recipeDir($id))) {
            $id = $base . '-' . $suffix;
            $suffix++;
        }

        return $id;
    }

    private static function indexFile(): string
    {
        return Paths::recipesDir() . '/index.json';
    }

    /** @return array<int, array<string, mixed>> */
    private static function readIndex(): array
    {
        $index = self::readJson(self::indexFile());

        return is_array($index) ? $index : [];
    }

    private static function writeIndex(): void
    {
        self::refreshIndex();
    }

    /** @return array<string, mixed>|null */
    private static function readJson(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function writeJson(string $file, array $data): void
    {
        Paths::ensure(dirname($file));
        file_put_contents($file, self::encode($data));
    }

    private static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function renderReadme(array $meta, array $recipe): string
    {
        $lines = [];
        $lines[] = '# ' . ($meta['name'] ?? '');
        $lines[] = '';
        $lines[] = (string) ($meta['description'] ?? '');
        $lines[] = '';
        $lines[] = '- Создан: ' . ($meta['created_at'] ?? '');
        $lines[] = '- Обновлён: ' . ($meta['updated_at'] ?? '');
        $lines[] = '- Версия: ' . ($meta['version'] ?? 1);
        $lines[] = '- Обращается к нейросети: ' . (($meta['uses_ai'] ?? false) ? 'да' : 'нет');
        $lines[] = '- Требует интернет: ' . (($meta['uses_network'] ?? false) ? 'да' : 'нет');
        if (($meta['tags'] ?? []) !== []) {
            $lines[] = '- Метки: ' . implode(', ', $meta['tags']);
        }
        $lines[] = '';

        if (($recipe['params'] ?? []) !== []) {
            $lines[] = '## Параметры запуска';
            $lines[] = '';
            foreach ($recipe['params'] as $param) {
                $lines[] = '- ' . ($param['label'] ?? $param['name'] ?? '') . ' (по умолчанию: ' . json_encode($param['default'] ?? null, JSON_UNESCAPED_UNICODE) . ')';
            }
            $lines[] = '';
        }

        $lines[] = '## Шаги';
        $lines[] = '';
        $number = 1;
        foreach ((array) ($recipe['steps'] ?? []) as $step) {
            $op = (string) ($step['op'] ?? '');
            $title = Ops::exists($op) ? (string) (Ops::meta($op)['title'] ?? $op) : $op;
            $lines[] = $number . '. ' . $title . ' (`' . $op . '`)';
            $number++;
        }
        $lines[] = '';
        $lines[] = '---';
        $lines[] = 'Файл сформирован автоматически приложением Макросыч.';

        return implode("\n", $lines) . "\n";
    }
}
