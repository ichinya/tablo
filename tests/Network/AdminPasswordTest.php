<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Auth;
use Tablo\Database;
use Tablo\Tests\Support\PasswordProcess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class AdminPasswordTest extends AdminPasswordFixtureCase
{


    private static function assertFramingRefusals(TemporaryDirectory $directory, string $path, \PDO $db, array $old): void
    {
        foreach (['', "fixture-password\n", "fixture-password\nfixture-password", "fixture-password\nfixture-password\nextra\n",
            "\xEF\xBB\xBFfixture-password\nfixture-password\n", "fixture\0password\nfixture\0password\n", "fixture-password\rfixture-password\r",
            "fixture-password\nother-new-password\n", str_repeat('x', times: 11) . "\n" . str_repeat('x', times: 11) . "\n",
            str_repeat('x', times: 1025) . "\n" . str_repeat('x', times: 1025) . "\n", str_repeat('ж', times: 513) . "\n" . str_repeat('ж', times: 513) . "\n",
            str_repeat('x', times: 1025) . "\nvalid-password\n", 'valid-password' . "\n" . str_repeat('x', times: 1025) . "\n",
            "invalid-utf8-\xFF\ninvalid-utf8-\xFF\n", str_repeat('x', times: 2053)] as $input) {
            $result = self::command($directory, $path, ['--password-stdin'], $input);
            Assert::same($result['exit_code'], 2, \Tablo\Tests\Support\FixtureValue::string($result['stderr']));
            Assert::true(!str_contains($result['stdout'] . $result['stderr'], \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password')));
            Assert::same(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM users'))->fetchAll(), \Tablo\SqlUsersCreatedAtIdPasswordHashRow::checked(...)), $old);
        }
    }

    #[Test]
    public function rejectsHostileInvocationAndFramingWithoutWritesOrEcho(): void
    {
        $directory = new TemporaryDirectory('tablo-password-input-');
        $db = null;
        try {
            $path = $directory->path . '/fixture.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            $old = \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM users'))->fetchAll(), \Tablo\SqlUsersCreatedAtIdPasswordHashRow::checked(...));
            foreach ([[], ['--password=synthetic-private-argv'], ['synthetic-private-argv'], ['--password-stdin', '--password-stdin'],
                ['--show-installation', '--password-stdin'], ['--help', 'synthetic-private-argv']] as $arguments) {
                $result = self::command($directory, $path, $arguments);
                Assert::same($result['exit_code'], 2);
                Assert::true(!str_contains($result['stdout'] . $result['stderr'], 'synthetic-private-argv'));
            }
            self::assertFramingRefusals($directory, $path, $db, $old);
            foreach ([str_repeat('x', times: 12), str_repeat('ж', times: 36), str_repeat('x', times: 73), str_repeat('x', times: 1023), str_repeat('ж', times: 512), str_repeat('x', times: 1024), '  retained spaces  '] as $password) {
                $result = self::command($directory, $path, ['--password-stdin'], $password . "\r\n" . $password . "\r\n");
                Assert::same($result['exit_code'], 0, \Tablo\Tests\Support\FixtureValue::string($result['stderr']));
                Assert::true(password_verify($password, \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn())));
                $hash = \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn());
                Assert::same(\Tablo\Tests\Support\FixtureValue::at(self::command($directory, $path, ['--password-stdin'], $password . "\n" . $password . "\n"), 'exit_code'), 2);
                Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn(), $hash);
            }
            $lfMaximum = str_repeat('y', times: 1024);
            Assert::same(self::command($directory, $path, ['--password-stdin'], $lfMaximum . "\n" . $lfMaximum . "\n")['exit_code'], 0, '2050-byte LF payload succeeds');
            $lfHash = \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn());
            Assert::true(password_verify($lfMaximum, $lfHash));
            Assert::false(password_verify(str_repeat('y', times: 1023) . 'x', $lfHash));
            Assert::same(\Tablo\Tests\Support\FixtureValue::at(self::command($directory, '/missing-installation', ['--help']), 'exit_code'), 0);
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $db = null; $directory->close(); }
    }



    #[Test]
    public function actualEofCancellationRetainsOldLoginAndRefusesNewLogin(): void
    {
        $directory = new TemporaryDirectory('tablo-password-eof-');
        $process = null; $db = null;
        try {
            $path = $directory->path . '/fixture.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            $process = new PasswordProcess($directory, $path);
            $process->send('');
            Assert::same($process->finish()['exit_code'], 2, 'EOF cancellation');
            Assert::true((new Auth($db))->login(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), 'old-client'));
            Assert::true(!(new Auth($db))->login(\Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret'), 'new-client'));
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally {
            $process?->close();
            if ($db?->inTransaction()) { $db->rollBack(); }
            $db = null; $directory->close();
        }
    }
}
