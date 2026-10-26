<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/**
 * One independently connected selected-engine login actor. Credentials never enter argv.
 * @phpstan-type Result array{status: string, connectionId: int}
 */
final class EngineProcess
{
    private readonly Subprocess $child;

    public static function migration(EngineFixture $fixture, TemporaryDirectory $directory, string $operation): Subprocess
    {
        if (!in_array($operation, ['migration-owner', 'migration-contender', 'exit-before-lock', 'exit-after-lock',
            'exit-after-ddl', 'exit-before-publication', 'exit-after-publication'], strict: true)) { throw new \RuntimeException('Migration actor operation refused.'); }
        return new Subprocess([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', dirname(__DIR__) . '/server-child.php', $fixture->case, $operation],
            $directory, $fixture->environment());
    }

    public function __construct(EngineFixture $fixture, TemporaryDirectory $directory, #[\SensitiveParameter] string $password, string $address)
    {
        $input = $directory->path . '/engine-login-' . bin2hex(random_bytes(12)) . '.json';
        $bytes = \Tablo\JsonEncoding::encode(['password' => $password, 'address' => $address]);
        $handle = FixtureNative::resource(fopen($input, mode: 'xb'));
        try {
            if (!chmod($input, permissions: 0o600) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException('Cannot retain private engine actor input.');
            }
        } finally { fclose($handle); }
        $this->child = new Subprocess([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0',
            dirname(__DIR__) . '/server-child.php', $fixture->case, $input], $directory,
            $fixture->environment() + ['TABLO_TEST_ENGINE_INPUT_DIRECTORY' => $directory->path]);
    }

    /** Launch every actor before waiting; callers share one readiness deadline. */
    public function ready(float $timeout): void
    {
        if ($timeout <= 0 || $timeout > 2) { throw new \RuntimeException('Engine actor readiness deadline refused.'); }
        try { $this->child->awaitOutput("ENGINE_READY\n", $timeout); }
        catch (\Throwable $error) { $this->child->close($error); throw $error; }
    }

    public function release(): void { $this->child->send("go\n"); }

    /** @return Result */
    public function finish(float $timeout = 6): array
    {
        if ($timeout <= 0 || $timeout > 6) { throw new \RuntimeException('Engine actor deadline refused.'); }
        $result = $this->child->finish($timeout);
        if ($result['exit_code'] !== 0 || $result['stderr'] !== '' || !str_starts_with($result['stdout'], "ENGINE_READY\n")) {
            throw new \RuntimeException('Selected engine actor failed or its exit is unknown.');
        }
        $record = \Tablo\JsonRecord::decode(substr($result['stdout'], offset: strlen("ENGINE_READY\n")));
        $status = \Tablo\SqlColumn::string($record, 'status');
        if (!in_array($status, ['failed', 'throttled', 'passed'], strict: true)) { throw new \RuntimeException('Engine actor result refused.'); }
        return ['status' => $status, 'connectionId' => \Tablo\SqlColumn::integer($record, 'connectionId')];
    }

    public function close(?\Throwable $primary = null): void { $this->child->close($primary); }
}
