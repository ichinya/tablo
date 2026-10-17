<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class PasswordProviderTest
{
    #[Test]
    public function simulatedCliProviderFailuresPreserveTheWholeSelectedInstallation(): void
    {
        $directory = new TemporaryDirectory('tablo-password-provider-cli-'); $db = null;
        try {
            $path = $directory->path . '/fixture.sqlite'; $db = \Tablo\Database::connect($path);
            \Tablo\SqlStatement::checked($db->prepare('INSERT INTO users(id,password_hash) VALUES(1,?)'))->execute([
                password_hash(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), PASSWORD_BCRYPT, ['cost' => 4]),
            ]);
            $before = \Tablo\Tests\Support\ReadApiAssertions::snapshot($db);
            foreach (['hash-throw', 'unregistered'] as $mode) {
                $hits = $directory->path . '/' . $mode . '.json';
                $result = Subprocess::run(command: [PHP_BINARY, dirname(__DIR__) . '/fixtures/password-provider-failure.php', '--password-stdin'],
                    directory: $directory, environment: ['TABLO_DB' => $path, 'TABLO_TEST_PROVIDER_CASE' => 'cli-' . $mode, 'TABLO_TEST_PROVIDER_HITS' => $hits],
                    options: new \Tablo\Tests\Support\CommandOptions(input: \Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-secret')));
                Assert::same($result['exit_code'], 1);
                Assert::true(str_contains($result['stderr'], 'Password runtime unavailable. No change committed.'));
                Assert::false(str_contains($result['stderr'], 'provider-private-marker'));
                Assert::same(\Tablo\Tests\Support\ReadApiAssertions::snapshot($db), $before);
                $record = \Tablo\JsonRecord::checked(json_decode(\Tablo\RequiredFile::bytes(file_get_contents($hits)), associative: true, flags: JSON_THROW_ON_ERROR));
                Assert::same($record['hash'] ?? 0, $mode === 'hash-throw' ? 1 : 0, 'actual expected native-hash branch');
            }
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $db = null; $directory->close(); }
    }

    #[Test]
    public function explicitSimulationsDistinguishInvalidVerificationAbsenceAndReplacementFailure(): void
    {
        $directory = new TemporaryDirectory('tablo-password-provider-');
        try {
            foreach (['no-constant', 'unregistered', 'hash-throw', 'wrong-algorithm', 'wrong-options', 'result-false', 'verify-false', 'verify-throw'] as $mode) {
                $result = Subprocess::run(command: [PHP_BINARY, '-d', 'zend.exception_ignore_args=0', dirname(__DIR__) . '/fixtures/password-provider-failure.php'],
                    directory: $directory, environment: ['TABLO_TEST_PROVIDER_CASE' => $mode, 'TABLO_DB' => $directory->path . '/fixture.sqlite']);
                Assert::same($result['exit_code'], 0, $mode . ': ' . $result['stderr']);
                $record = \Tablo\JsonRecord::checked(json_decode($result['stdout'], associative: true, flags: JSON_THROW_ON_ERROR));
                Assert::same($record['classification'] ?? null, 'simulation only');
                Assert::same($record['case'] ?? null, $mode);
                Assert::same($record['credentialUnchanged'] ?? null, true);
                Assert::same($result['stderr'], '');
            }
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $directory->close(); }
    }
}
