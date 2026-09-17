<?php

declare(strict_types=1);

namespace App\Lib;

use App\Engine\Context;
use App\Engine\Ops;

/**
 * Исполнение сценария.
 *
 * Шаги выполняются последовательно; при ошибке шага исполнение останавливается.
 * В режиме проверки (dry_run) операции не создают файлов и не обращаются к сети —
 * формируется только предпросмотр результата.
 */
final class Runner
{
    /**
     * @param array<string, mixed> $recipe
     * @param array<string, mixed> $options  inputs, params, dry_run, job_id, recipe_id, continue_on_error
     * @return array<string, mixed>
     */
    public static function execute(array $recipe, array $options = [], ?callable $onLog = null): array
    {
        $jobId = (string) ($options['job_id'] ?? self::newJobId());
        $jobDir = Paths::ensure(Paths::jobsDir() . '/' . $jobId);
        $workDir = Paths::ensure($jobDir . '/work');
        $outDir = Paths::ensure((string) ($options['out_dir'] ?? $jobDir . '/out'));

        $recipe = RecipeValidator::normalize($recipe);

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $continueOnError = (bool) ($options['continue_on_error'] ?? false);
        $params = RecipeValidator::resolveParams($recipe, (array) ($options['params'] ?? []));
        $inputs = (array) ($options['inputs'] ?? []);

        $context = new Context([
            'dry_run' => $dryRun,
            'work_dir' => $workDir,
            'out_dir' => $outDir,
            'inputs' => $inputs,
            'params' => $params,
        ]);

        $emit = static function (array $entry) use ($onLog): void {
            if ($onLog !== null) {
                $onLog($entry);
            }
        };

        $startedAt = microtime(true);
        $failed = null;
        $steps = (array) ($recipe['steps'] ?? []);

        foreach ($steps as $index => $step) {
            $number = $index + 1;
            $step = (array) $step;
            $op = (string) ($step['op'] ?? '');

            $stepParams = $step;
            unset($stepParams['op']);
            $stepParams = self::substitute($stepParams, $params);
            $stepParams = self::resolveAliases($stepParams, $inputs);

            $label = Ops::exists($op) ? (string) (Ops::meta($op)['title'] ?? $op) : $op;
            $context->info("Шаг {$number}: {$label}");
            $emit(['level' => 'info', 'message' => "Шаг {$number}: {$label}", 'time' => date('H:i:s')]);

            $before = count($context->logs);
            try {
                Ops::run($op, $context, $stepParams);
            } catch (\Throwable $e) {
                $message = "Шаг {$number} ({$op}): " . $e->getMessage();
                $context->error($message);
                $emit(['level' => 'error', 'message' => $message, 'time' => date('H:i:s')]);
                $failed = $message;

                if (!$continueOnError) {
                    break;
                }
            }

            foreach (array_slice($context->logs, $before) as $entry) {
                $emit($entry);
            }
        }

        // Необязательная упаковка результата в архив
        $outputsSpec = is_array($recipe['outputs'] ?? null) ? $recipe['outputs'] : [];
        $zipName = $outputsSpec['zip'] ?? null;
        if ($failed === null && is_string($zipName) && $zipName !== '') {
            try {
                Ops::run('zip_output', $context, ['output' => $zipName]);
            } catch (\Throwable $e) {
                $context->warn('Не удалось создать архив: ' . $e->getMessage());
            }
        }

        $duration = round(microtime(true) - $startedAt, 2);

        if ($failed === null) {
            $context->info('Готово за ' . $duration . ' с');
        }

        $result = [
            'ok' => $failed === null,
            'error' => $failed,
            'job_id' => $jobId,
            'job_dir' => $jobDir,
            'out_dir' => $outDir,
            'dry_run' => $dryRun,
            'duration' => $duration,
            'preview' => $context->preview(),
            'summary' => $context->summary(),
            'logs' => $context->logs,
            'outputs' => self::collectOutputs($context, $outDir),
            'params' => $params,
            'finished_at' => date('c'),
        ];

        if (!$dryRun && isset($options['recipe_id']) && (string) $options['recipe_id'] !== '') {
            Store::recordRun((string) $options['recipe_id'], [
                'job_id' => $jobId,
                'ok' => $result['ok'],
                'duration' => $duration,
                'summary' => $result['summary'],
                'error' => $failed,
            ]);
        }

        return $result;
    }

    public static function newJobId(): string
    {
        return date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
    }

    /**
     * Приведение значений file/template к псевдонимам входных файлов.
     *
     * Модель часто указывает имя файла вместо псевдонима. Сопоставляем по имени,
     * а если не получилось — подставляем ожидаемый псевдоним.
     *
     * @param array<string, mixed> $stepParams
     * @param array<string, string> $inputs
     * @return array<string, mixed>
     */
    private static function resolveAliases(array $stepParams, array $inputs): array
    {
        if ($inputs === []) {
            return $stepParams;
        }

        foreach (['file', 'template'] as $key) {
            if (!isset($stepParams[$key]) || !is_string($stepParams[$key]) || $stepParams[$key] === '') {
                continue;
            }

            $value = $stepParams[$key];
            if (isset($inputs[$value])) {
                continue;
            }

            // Поиск псевдонима по имени файла
            $matched = null;
            foreach ($inputs as $alias => $path) {
                $basename = basename((string) $path);
                if ($basename === $value || str_contains($basename, $value) || str_contains($value, pathinfo($basename, PATHINFO_FILENAME))) {
                    $matched = (string) $alias;
                    break;
                }
            }

            if ($matched === null) {
                // Подставляем основной файл только вместо имени файла (модель часто пишет
                // «6.xlsx» вместо псевдонима). Если написано слово-псевдоним («lookup»),
                // подстановка запрещена: лучше понятная ошибка, чем тихо не те данные.
                $looksLikeFilename = preg_match('~[\\\\/]~', $value) === 1
                    || pathinfo($value, PATHINFO_EXTENSION) !== '';

                if (!$looksLikeFilename) {
                    continue;
                }

                $preferred = $key === 'template' ? 'template' : 'input';
                $matched = isset($inputs[$preferred]) ? $preferred : (string) array_key_first($inputs);
            }

            $stepParams[$key] = $matched;
        }

        return $stepParams;
    }

    /** Подстановка {{параметр}} в значения шага. */
    public static function substitute(mixed $value, array $params): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = self::substitute($item, $params);
            }

            return $result;
        }

        if (!is_string($value) || !str_contains($value, '{{')) {
            return $value;
        }

        $replaced = preg_replace_callback(
            '~\{\{\s*([a-zA-Z0-9_]+)\s*\}\}~',
            static fn (array $matches) => (string) ($params[$matches[1]] ?? ''),
            $value
        );

        return $replaced ?? $value;
    }

    /** @return array<int, array<string, mixed>> */
    private static function collectOutputs(Context $context, string $outDir): array
    {
        $outputs = [];
        foreach ($context->planned as $item) {
            $outputs[] = $item;
        }

        foreach ($context->files as $file) {
            if (($file['status'] ?? '') === 'ok' && isset($file['path']) && Paths::isInsideData((string) $file['path'])) {
                $outputs[] = [
                    'kind' => $file['kind'] ?? 'file',
                    'path' => Paths::relativeToData((string) $file['path']),
                    'name' => basename((string) $file['path']),
                ];
            }
        }

        return $outputs;
    }
}
