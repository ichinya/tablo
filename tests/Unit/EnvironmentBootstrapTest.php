<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class EnvironmentBootstrapTest
{
    #[Test]
    public function loadsRootDotenvWithQuotedPathsAndInterpolationFromAnotherDirectory(): void
    {
        $directory = $this->project();
        try {
            $result = $this->run($directory);
            Assert::same($result['exit_code'], 0, $result['stderr']);
            $data = json_decode($result['stdout'], associative: true, flags: JSON_THROW_ON_ERROR);
            Assert::same($data['path'], str_replace('\\', replace: '/', subject: $directory->path) . '/data path/custom.sqlite');
            Assert::same(realpath($data['file']), realpath($data['path']));
            Assert::same($data['value'], 'value # with = signs');
            Assert::same($data['literal'], 'D:\\data dir\\file.sqlite');
        } finally { $directory->close(); }
    }

    #[Test]
    public function preservesProcessOverridesIncludingExplicitlyEmptyValues(): void
    {
        $directory = $this->project();
        try {
            foreach ([$directory->path . '/override.sqlite', ''] as $path) {
                $result = $this->run($directory, ['TABLO_DOTENV_TEST_PROCESS' => 'keep', 'TABLO_DB' => $path,
                    'TABLO_DOTENV_TEST_EMPTY' => $path === '' ? '1' : '0']);
                Assert::same($result['exit_code'], 0, $result['stderr']);
                $data = json_decode($result['stdout'], associative: true, flags: JSON_THROW_ON_ERROR);
                Assert::same($data['path'], $path);
            }
            Assert::true(!is_file($directory->path . '/data path/custom.sqlite'));
        } finally { $directory->close(); }
    }

    #[Test]
    public function allowsAnAbsentDotenvAndRedactsInvalidFileContents(): void
    {
        $directory = $this->project();
        try {
            unlink($directory->path . '/.env');
            $result = $this->run($directory);
            Assert::same($result['exit_code'], 0, $result['stderr']);
            $data = json_decode($result['stdout'], associative: true, flags: JSON_THROW_ON_ERROR);
            Assert::same($data['path'], false);
            Assert::same($data['file'], null);
            file_put_contents($directory->path . '/.env', data: "TABLO_DB=\"fixture-private-value\\q\"\n");
            $result = $this->run($directory);
            Assert::true($result['exit_code'] !== 0);
            Assert::true(str_contains($result['stderr'] . $result['stdout'], 'Не удалось загрузить .env'));
            Assert::true(!str_contains($result['stderr'] . $result['stdout'], 'fixture-private-value'));
        } finally { $directory->close(); }
    }

    #[Test]
    public function runsTheRealCheckCommandAgainstTheDotenvDatabase(): void
    {
        $directory = $this->project();
        try {
            $result = $this->run($directory, arguments: ['check']);
            Assert::same($result['exit_code'], 0, $result['stderr']);
            Assert::same($result['stdout'], '');
            $path = $directory->path . '/data path/custom.sqlite';
            Assert::true(is_file($path));
            $database = new PDO('sqlite:' . $path);
            Assert::same((int) $database->query('SELECT COUNT(*) FROM sites')->fetchColumn(), 0);
            $database = null;
        } finally { $directory->close(); }
    }

    private function project(): TemporaryDirectory
    {
        return $this->createProject();
    }

    #[Test]
    public function externalKeySelectionKeepsDotenvProcessAndExplicitEmptyPriority(): void
    {
        $directory = $this->createProject();
        try {
            $key = str_replace('\\', '/', $directory->path) . '/dotenv-key';
            $override = str_replace('\\', '/', $directory->path) . '/process-key';
            file_put_contents($key, random_bytes(32)); file_put_contents($override, random_bytes(32));
            file_put_contents($directory->path . '/.env', 'TABLO_TOKEN_KEY_FILE="' . $key . '"' . "\n", FILE_APPEND);
            foreach (['empty' => '', 'unset' => $key, 'override' => $override] as $mode => $expected) {
                $result = $this->run($directory, ['TABLO_TEST_KEY_MODE' => $mode, 'TABLO_TOKEN_KEY_FILE' => $override,
                    'TABLO_TEST_EXPECTED_KEY_PATH' => $expected], ['external']);
                Assert::same($result['exit_code'], 0);
                $data = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
                Assert::same($data, ['matches' => true, 'legacy' => $mode === 'empty', 'roundtrip' => true], $mode . ':' . json_encode($data));
            }
        } finally { $directory->close(); }
    }

    private function createProject(): TemporaryDirectory
    {
        $directory = new TemporaryDirectory('tablo-env-');
        try {
            $root = dirname(__DIR__, levels: 2);
            foreach (['app', 'bin', 'public', 'vendor'] as $name) { mkdir($directory->path . '/' . $name); }
            copy($root . '/app/bootstrap.php', $directory->path . '/app/bootstrap.php');
            copy($root . '/bin/check.php', $directory->path . '/bin/check.php');
            $autoload = var_export($root . '/vendor/autoload.php', return: true);
            file_put_contents($directory->path . '/vendor/autoload.php', data: '<?php return require ' . $autoload . ';');
            $path = str_replace('\\', replace: '/', subject: $directory->path);
            file_put_contents($directory->path . '/.env', data: 'TABLO_DOTENV_TEST_DIR="' . $path . '"' . "\n"
                . 'TABLO_DB="${TABLO_DOTENV_TEST_DIR}/data path/custom.sqlite"' . "\n"
                . 'TABLO_DOTENV_TEST_VALUE="value # with = signs" # comment' . "\n"
                . "TABLO_DOTENV_TEST_LITERAL='D:\\data dir\\file.sqlite'\n");
            return $directory;
        } catch (\Throwable $error) {
            $directory->close();
            throw $error;
        }
    }

    private function run(TemporaryDirectory $directory, array $environment = [], array $arguments = []): array
    {
        return Subprocess::run([PHP_BINARY, dirname(__DIR__) . '/fixtures/environment-bootstrap.php', $directory->path, ...$arguments],
            $directory, array_replace(['TABLO_DOTENV_TEST_PROCESS' => 'clear', 'TABLO_DOTENV_TEST_EMPTY' => '0',
                'TABLO_DB' => $directory->path . '/unused.sqlite'], $environment));
    }
}
