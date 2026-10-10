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
        Assert::same($result['exit_code'], $exit, 'actual helper exit');
        preg_match('/HELPER_METADATA=(.+)/', $result['stdout'], $match);
        $metadata = json_decode($match[1] ?? '', associative: true, flags: JSON_THROW_ON_ERROR);
        Assert::same($metadata['helperExit'], $exit, 'inner helper and wrapper exits agree');
        Assert::true($metadata['encodingRestored'] && $metadata['codePageRestored'] && $metadata['preambleRestored']);
        Assert::same($metadata['prompts'], 2, 'both protected prompts requested');
    }

    #[Test]
    public function shippedHelperPreservesHostEncodingAndExactCredentials(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') { throw new SkipTest('Native Windows PowerShell host required.'); }
        $shells = [getenv('SystemRoot') . '/System32/WindowsPowerShell/v1.0/powershell.exe'];
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
                    (new Auth($db))->setup('fixture-password', 'fixture-password');
                    $old = $db->query('SELECT password_hash FROM users')->fetchColumn();
                    $db = null;
                    $command = [$shell, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
                        dirname(__DIR__) . '/fixtures/password-helper-input.ps1', '-Helper',
                        dirname(__DIR__, levels: 2) . '/tools/admin-password.ps1', '-Php', PHP_BINARY,
                        '-HostEncoding', $encoding, '-Scenario', $scenario];
                    $environment = ['TABLO_DB' => $path, 'TABLO_STORAGE_DIR' => $directory->path];
                    $result = Subprocess::run($command, $directory, $environment, timeout: 20);
                    self::assertMetadata($result, $exit);
                    $db = new PDO('sqlite:' . $path);
                    $hash = $db->query('SELECT password_hash FROM users')->fetchColumn();
                    if ($exit === 0) {
                        $password = match ($scenario) { 'min' => str_repeat('x', times: 12), 'max' => str_repeat('x', times: 72),
                            default => "  \u{0436}\u{4e2d}\u{1f642}-unicode-  " };
                        Assert::true(password_verify($password, $hash), 'exact UTF-8 and retained spaces');
                        Assert::true(!password_verify('fixture-password', $hash));
                        Assert::true(!str_contains($result['stdout'] . $result['stderr'], $password));
                        $repeat = Subprocess::run($command, $directory, $environment, timeout: 20);
                        Assert::same($repeat['exit_code'], 2, 'same-password refusal propagates');
                        self::assertMetadata($repeat, 2);
                        Assert::true(hash_equals($hash, $db->query('SELECT password_hash FROM users')->fetchColumn()));
                        continue;
                    }
                    Assert::true(hash_equals($old, $hash), 'failure retains the original credential');
                } finally { $db = null; $directory->close(); }
            }
        }
    }
}
