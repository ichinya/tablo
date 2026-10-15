<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use PDO;
use Tablo\Auth;
use Tablo\Database;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Test;

final class PasswordHelperTest
{
    private static function assertMetadata(array $result, int $exit): void
    {
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($result, 'exit_code'), $exit, 'actual helper exit');
        $match = [];
        preg_match('/HELPER_METADATA=(.+)/', \Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at($result, 'stdout')), $match);
        $metadata = \Tablo\JsonRecord::checked(json_decode($match[1] ?? '', associative: true, flags: JSON_THROW_ON_ERROR));
        Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked($metadata), 'helperExit'), $exit, 'inner helper and wrapper exits agree');
        Assert::true(\Tablo\Tests\Support\FixtureJson::boolean($metadata, 'encodingRestored') && \Tablo\Tests\Support\FixtureJson::boolean($metadata, 'codePageRestored') && \Tablo\Tests\Support\FixtureJson::boolean($metadata, 'preambleRestored'));
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($metadata, 'prompts'), 2, 'both protected prompts requested');
    }

    #[Test]
    public function shippedHelperPreservesHostEncodingAndExactCredentials(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') { throw new SkipTest('Native Windows PowerShell host required.'); }
        $shells = [\Tablo\Tests\Support\FixtureValue::string(getenv('SystemRoot')) . '/System32/WindowsPowerShell/v1.0/powershell.exe'];
        $pwsh = getenv('TABLO_TEST_PWSH');
        if (is_string($pwsh) && $pwsh !== '') { $shells[] = $pwsh; }
        foreach ($shells as $shell) {
            foreach ([['default', 'unicode', 0], ['bom', 'unicode', 0], ['bomless', 'unicode', 0], ['cp866', 'unicode', 0],
                ['bom', 'min', 0], ['bom', 'max', 0], ['bom', 'short', 2], ['bom', 'long', 2],
                ['bom', 'startFailure', 1], ['bom', 'cancel', 1], ['bom', 'encodingFailure', 1]] as [$encoding, $scenario, $exit]) {
                $directory = new TemporaryDirectory('tablo-helper-');
                $db = null;
                try {
                    $path = $directory->path . '/fixture.sqlite';
                    $db = Database::connect($path);
                    (new Auth($db))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
                    $old = \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn());
                    $db = null;
                    $command = PasswordHelperCommand::forHost($shell, $encoding, $scenario);
                    $environment = ['TABLO_DB' => $path, 'TABLO_STORAGE_DIR' => $directory->path];
                    $result = Subprocess::run(command: $command, directory: $directory, environment: $environment, options: new \Tablo\Tests\Support\CommandOptions(timeout: 20));
                    self::assertMetadata($result, $exit);
                    $db = new PDO('sqlite:' . $path);
                    $hash = \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn());
                    if ($exit === 0) {
                        $password = match ($scenario) { 'min' => str_repeat('x', times: 12), 'max' => str_repeat('x', times: 72),
                            default => "  \u{0436}\u{4e2d}\u{1f642}-unicode-  " };
                        Assert::true(password_verify($password, \Tablo\Tests\Support\FixtureValue::string($hash)), 'exact UTF-8 and retained spaces');
                        Assert::true(!password_verify(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureValue::string($hash)));
                        Assert::true(!str_contains($result['stdout'] . $result['stderr'], $password));
                        $repeat = Subprocess::run(command: $command, directory: $directory, environment: $environment, options: new \Tablo\Tests\Support\CommandOptions(timeout: 20));
                        Assert::same($repeat['exit_code'], 2, 'same-password refusal propagates');
                        self::assertMetadata($repeat, 2);
                        Assert::true(hash_equals(\Tablo\Tests\Support\FixtureValue::string($hash), \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn())));
                        continue;
                    }
                    Assert::true(hash_equals(\Tablo\Tests\Support\FixtureValue::string($old), \Tablo\Tests\Support\FixtureValue::string($hash)), 'failure retains the original credential');
                } finally { $db = null; $directory->close(); }
            }
        }
    }
}
