<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class LintCommandTest
{
    #[Test]
    public function enforcesAllSeverityLevelsAndPreservesToolExitCodes(): void
    {
        $directory = $this->project();
        try {
            foreach ([0, 1, 2] as $exit) {
                $result = $this->run($directory, ['TABLO_LINT_TEST_EXIT' => (string) $exit]);
                Assert::same($result['exit_code'], $exit);
                Assert::same(json_decode(file_get_contents($directory->path . '/invocation.json'), associative: true, flags: JSON_THROW_ON_ERROR),
                    ['lint', '--minimum-fail-level=note']);
                Assert::true(str_contains($result['stderr'], 'fixture lint diagnostic'));
            }
        } finally { $directory->close(); }
    }

    #[Test]
    public function rejectsVersionAndConfigurationDriftBeforeLinting(): void
    {
        $directory = $this->project();
        try {
            $result = $this->run($directory, [], ['--version']);
            Assert::same($result['exit_code'], 0);
            Assert::same(trim($result['stdout']), 'mago 1.51.2');
            Assert::true(!is_file($directory->path . '/invocation.json'));
            foreach ([['TABLO_LINT_TEST_VERSION' => 'mago 1.51.1'], ['TABLO_LINT_TEST_VERSION_EXIT' => '2']] as $environment) {
                $result = $this->run($directory, $environment);
                Assert::same($result['exit_code'], 1);
                Assert::true(str_contains($result['stderr'], 'Unexpected Mago CLI version'));
            }
            file_put_contents($directory->path . '/mago.toml', data: 'version = "1.51.1"');
            $result = $this->run($directory);
            Assert::same($result['exit_code'], 1);
            Assert::true(str_contains($result['stderr'], 'configuration version must match'));
            file_put_contents($directory->path . '/mago.toml', data: 'version = 123');
            Assert::same($this->run($directory)['exit_code'], 1);
            Assert::true(!is_file($directory->path . '/invocation.json'));
        } finally { $directory->close(); }
    }

    #[Test]
    public function stopsVerificationWhenLintFailsOrTheToolCannotStart(): void
    {
        $directory = $this->project();
        try {
            copy(dirname(__DIR__, levels: 2) . '/tools/verify.php', $directory->path . '/tools/verify.php');
            $environment = ['TABLO_LINT_TEST_VERSION' => 'mago 1.51.2', 'TABLO_LINT_TEST_EXIT' => '1'];
            $result = Subprocess::run([PHP_BINARY, $directory->path . '/tools/verify.php'], $directory, $environment);
            Assert::same($result['exit_code'], 1);
            Assert::true(str_contains($result['stderr'], 'Gate failed:'));
            Assert::true(!is_file($directory->path . '/artifacts/verification.json'));
            unlink($directory->path . '/vendor/bin/mago');
            $result = $this->run($directory);
            Assert::same($result['exit_code'], 1);
        } finally { $directory->close(); }
    }

    private function project(): TemporaryDirectory
    {
        $directory = new TemporaryDirectory('tablo-lint-');
        try {
            $root = dirname(__DIR__, levels: 2);
            mkdir($directory->path . '/tools');
            mkdir($directory->path . '/vendor/bin', permissions: 0o700, recursive: true);
            copy($root . '/tools/lint.php', $directory->path . '/tools/lint.php');
            copy($root . '/tests/fixtures/lint-autoload.php', $directory->path . '/vendor/autoload.php');
            copy($root . '/tests/fixtures/lint-cli.php', $directory->path . '/vendor/bin/mago');
            file_put_contents($directory->path . '/mago.toml', data: 'version = "1.51.2"');
            return $directory;
        } catch (\Throwable $error) {
            $directory->close();
            throw $error;
        }
    }

    private function run(TemporaryDirectory $directory, array $environment = [], array $arguments = []): array
    {
        return Subprocess::run([PHP_BINARY, $directory->path . '/tools/lint.php', ...$arguments], $directory,
            array_replace(['TABLO_LINT_TEST_VERSION' => 'mago 1.51.2', 'TABLO_LINT_TEST_EXIT' => '0',
                'TABLO_LINT_TEST_VERSION_EXIT' => '0'], $environment));
    }
}
