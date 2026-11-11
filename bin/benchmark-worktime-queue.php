<?php

declare(strict_types=1);

/** Development-only benchmark. The PHPUnit fixture creates/drops its own scratch DB. */
require_once __DIR__ . '/../bootstrap/autoload.php';
$options = getopt('', ['report:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php bin/benchmark-worktime-queue.php [--report=/tmp/worktime-benchmark.json]\nRuns the synthetic 100 employee / 3 year MariaDB benchmark in a disposable scratch database. Requires development dependencies and CREATE DATABASE. No existing application database is used.\n");
    exit(0);
}
$report = $options['report'] ?? sys_get_temp_dir() . '/timeapp-worktime-queue-benchmark.json';
if (!is_string($report) || $report === '' || !is_dir(dirname($report))) {
    fwrite(STDERR, "The benchmark report needs an existing parent directory.\n");
    exit(1);
}
$command = [PHP_BINARY, base_path('vendor/bin/phpunit'), base_path('tests/Integration/WorkTimeReconciliationQueueDatabaseTest.php'), '--filter', 'testHundredEmployeeHistoryUsesBoundedDirtyWork'];
$environment = array_merge(getenv(), ['WORK_TIME_QUEUE_BENCHMARK_REPORT' => $report]);
$process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, base_path(), $environment);
if (!is_resource($process)) { fwrite(STDERR, "Benchmark runner could not be started.\n"); exit(1); }
$code = proc_close($process);
if ($code === 0 && is_file($report)) { fwrite(STDOUT, (string) file_get_contents($report) . PHP_EOL); }
exit($code);
