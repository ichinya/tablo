<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Testo\Assert;

/** A fresh actual runner inspects every real trace argument without accumulated runner state. */
final class TraceTestIsolation
{
    public static function run(string $file, string $function, int $minimumAssertions): void
    {
        $directory = new TemporaryDirectory('tablo-trace-isolation-');
        $command = [PHP_BINARY, 'vendor/bin/testo', 'run', '--path', $file, '--filter', $function, '--json'];
        try {
            $result = Subprocess::run($command, $directory, ['TABLO_TEST_ISOLATED_TRACE' => $function],
                new CommandOptions(timeout: 30));
            self::record($file, $function, $command, $result);
            Assert::same($result['exit_code'], 0, $result['stderr']);
            $report = json_decode($result['stdout'], associative: true, depth: 32, flags: JSON_THROW_ON_ERROR);
            Assert::same($report['totals']['total'], 1);
            Assert::same($report['totals']['passed'], 1);
            Assert::true($report['totals']['assertions'] >= $minimumAssertions);
        } finally { $directory->close(); }
    }

    private static function record(string $file, string $function, array $command, array $result): void
    {
        $path = getenv('TABLO_TRACE_ISOLATION_REPORT');
        if ($path === false || $path === '') { return; }
        $record = ['file' => $file, 'function' => $function, 'command' => $command,
            'test_source_sha256' => hash_file('sha256', $file),
            'bindings_sha256' => hash_file('sha256', filename: 'contracts/php-bindings.json'),
            'reviewed_dependencies_sha256' => hash_file('sha256', filename: 'contracts/reviewed-dependencies.json'),
            'stdout' => $result['stdout'], 'stderr' => $result['stderr'], 'exit' => $result['exit_code'],
            'receipt' => json_decode(file_get_contents($result['files']['receipt']), associative: true, depth: 64, flags: JSON_THROW_ON_ERROR)];
        file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
}
