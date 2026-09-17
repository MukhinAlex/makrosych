<?php

declare(strict_types=1);

/**
 * Исполнитель задания в отдельном процессе.
 *
 * Запускается HTTP-обработчиком: встроенный сервер PHP на Windows однопоточный,
 * поэтому тяжёлая работа вынесена в CLI-процесс. Ход выполнения пишется в
 * status.json и log.jsonl, которые опрашивает интерфейс.
 *
 * Запуск: php app/Worker/run.php --job=<идентификатор>
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Lib\Paths;
use App\Lib\Runner;

set_time_limit(0);
ini_set('memory_limit', '1024M');

$jobId = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--job=')) {
        $jobId = substr($argument, 6);
    }
}

if ($jobId === '') {
    fwrite(STDERR, "Не указан идентификатор задания (--job=...)\n");
    exit(1);
}

$jobDir = Paths::jobsDir() . '/' . $jobId;
$jobFile = $jobDir . '/job.json';
$statusFile = $jobDir . '/status.json';
$logFile = $jobDir . '/log.jsonl';

if (!is_file($jobFile)) {
    fwrite(STDERR, "Файл задания не найден: {$jobFile}\n");
    exit(1);
}

$job = json_decode((string) file_get_contents($jobFile), true);
if (!is_array($job)) {
    fwrite(STDERR, "Некорректный файл задания\n");
    exit(1);
}

$totalSteps = count((array) ($job['recipe']['steps'] ?? []));
$finished = false;

/**
 * Обновление файла состояния.
 *
 * @param array<string, mixed> $patch
 */
$writeStatus = static function (array $patch) use ($statusFile, $jobId, $totalSteps): void {
    $current = [];
    if (is_file($statusFile)) {
        $decoded = json_decode((string) file_get_contents($statusFile), true);
        if (is_array($decoded)) {
            $current = $decoded;
        }
    }

    $current = array_merge($current, $patch, [
        'job_id' => $jobId,
        'total_steps' => $totalSteps,
        'updated_at' => date('c'),
    ]);

    file_put_contents(
        $statusFile,
        json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
};

$appendLog = static function (array $entry) use ($logFile): void {
    file_put_contents(
        $logFile,
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND
    );
};

$writeStatus([
    'state' => 'running',
    'pid' => getmypid(),
    'started_at' => date('c'),
    'progress' => ['step' => 0, 'total' => $totalSteps, 'percent' => 0],
]);

register_shutdown_function(static function () use (&$finished, $writeStatus): void {
    if ($finished) {
        return;
    }

    $error = error_get_last();
    $writeStatus([
        'state' => 'error',
        'error' => $error !== null ? ($error['message'] . ' (' . $error['file'] . ':' . $error['line'] . ')') : 'Процесс завершился неожиданно',
        'finished_at' => date('c'),
    ]);
});

$currentStep = 0;

try {
    $result = Runner::execute(
        (array) ($job['recipe'] ?? []),
        ((array) ($job['options'] ?? [])) + ['job_id' => $jobId, 'recipe_id' => (string) ($job['recipe_id'] ?? '')],
        static function (array $entry) use ($appendLog, $writeStatus, &$currentStep, $totalSteps): void {
            $appendLog($entry);

            if (preg_match('~^Шаг (\d+):~u', (string) ($entry['message'] ?? ''), $matches) === 1) {
                $currentStep = (int) $matches[1];
                $percent = $totalSteps > 0 ? (int) round(($currentStep - 1) / $totalSteps * 100) : 0;
                $writeStatus([
                    'progress' => [
                        'step' => $currentStep,
                        'total' => $totalSteps,
                        'percent' => max(0, min(99, $percent)),
                        'message' => (string) $entry['message'],
                    ],
                ]);
            }
        }
    );

    $finished = true;
    $writeStatus([
        'state' => $result['ok'] ? 'done' : 'error',
        'error' => $result['error'],
        'finished_at' => date('c'),
        'progress' => ['step' => $totalSteps, 'total' => $totalSteps, 'percent' => 100],
        'summary' => $result['summary'],
        'duration' => $result['duration'],
        'out_dir' => $result['out_dir'],
        'outputs' => $result['outputs'],
    ]);

    exit($result['ok'] ? 0 : 1);
} catch (Throwable $e) {
    $finished = true;
    $appendLog(['level' => 'error', 'message' => $e->getMessage(), 'time' => date('H:i:s')]);
    $writeStatus([
        'state' => 'error',
        'error' => $e->getMessage(),
        'finished_at' => date('c'),
    ]);

    exit(1);
}
