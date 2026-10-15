<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Testo\Assert;

/** A fresh actual runner inspects every real trace argument without accumulated runner state.
 * @phpstan-import-type Result from Subprocess */
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
            $report = \Tablo\JsonRecord::decode($result['stdout'], 32);
            $totals = \Tablo\JsonRecord::checked(FixtureValue::at($report, 'totals'));
            Assert::same(\Tablo\SqlColumn::integer($totals, 'total'), 1);
            Assert::same(\Tablo\SqlColumn::integer($totals, 'passed'), 1);
            Assert::true(\Tablo\SqlColumn::integer($totals, 'assertions') >= $minimumAssertions);
        } finally { $directory->close(); }
    }

    public static function nativeCase(string $file, string $function, string $entrypoint, int $minimumAssertions): void
    {
        $directory = new TemporaryDirectory('tablo-native-trace-isolation-');
        $command = [PHP_BINARY, $entrypoint];
        try {
            $result = Subprocess::run($command, $directory, [], new CommandOptions(timeout: 30));
            self::record($file, $function, $command, $result);
            Assert::same($result['exit_code'], 0, $result['stderr'] . $result['stdout']);
            $report = \Tablo\JsonRecord::decode($result['stdout'], 32);
            $totals = \Tablo\JsonRecord::checked(FixtureValue::at($report, 'totals'));
            Assert::same(\Tablo\SqlColumn::integer($totals, 'total'), 1);
            Assert::same(\Tablo\SqlColumn::integer($totals, 'passed'), 1);
            Assert::true(\Tablo\SqlColumn::integer($totals, 'assertions') >= $minimumAssertions);
        } finally { $directory->close(); }
    }

    /** @param list<string> $command
     * @param Result $result */
    private static function record(string $file, string $function, array $command, array $result): void
    {
        $path = getenv('TABLO_TRACE_ISOLATION_REPORT');
        if ($path === false || $path === '') { return; }
        $record = ['file' => $file, 'function' => $function, 'command' => $command,
            'test_source_sha256' => hash_file('sha256', $file),
            'bindings_sha256' => hash_file('sha256', filename: 'contracts/php-bindings.json'),
            'reviewed_dependencies_sha256' => hash_file('sha256', filename: 'contracts/reviewed-dependencies.json'),
            'stdout' => $result['stdout'], 'stderr' => $result['stderr'], 'exit' => $result['exit_code'],
            'receipt' => \Tablo\Tests\Support\SubprocessReceipt::read($result['files']['receipt'])];
        file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
}
