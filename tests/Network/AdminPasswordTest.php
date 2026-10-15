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


    #[Test]
    public function rejectsHostileInvocationAndFramingWithoutWritesOrEcho(): void
    {
        $directory = new TemporaryDirectory('tablo-password-input-');
        $db = null;
        try {
            $path = $directory->path . '/fixture.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            $old = $db->query('SELECT * FROM users')->fetchAll();
            foreach ([[], ['--password=synthetic-private-argv'], ['synthetic-private-argv'], ['--password-stdin', '--password-stdin'],
                ['--show-installation', '--password-stdin'], ['--help', 'synthetic-private-argv']] as $arguments) {
                $result = self::command($directory, $path, $arguments);
                Assert::same($result['exit_code'], 2);
                Assert::true(!str_contains($result['stdout'] . $result['stderr'], 'synthetic-private-argv'));
            }
            foreach (['', "fixture-password\n", "fixture-password\nfixture-password", "fixture-password\nfixture-password\nextra\n",
                "\xEF\xBB\xBFfixture-password\nfixture-password\n", "fixture\0password\nfixture\0password\n", "fixture-password\rfixture-password\r",
                "fixture-password\nother-new-password\n", str_repeat('x', times: 11) . "\n" . str_repeat('x', times: 11) . "\n",
                str_repeat('x', times: 73) . "\n" . str_repeat('x', times: 73) . "\n", str_repeat('ж', times: 37) . "\n" . str_repeat('ж', times: 37) . "\n",
                "invalid-utf8-\xFF\ninvalid-utf8-\xFF\n", str_repeat('oversize', times: 64)] as $input) {
                $result = self::command($directory, $path, ['--password-stdin'], $input);
                Assert::same($result['exit_code'], 2, $result['stderr']);
                Assert::true(!str_contains($result['stdout'] . $result['stderr'], \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password')));
                Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
            }
            foreach ([str_repeat('x', times: 12), str_repeat('ж', times: 36), '  retained spaces  '] as $password) {
                $result = self::command($directory, $path, ['--password-stdin'], $password . "\r\n" . $password . "\r\n");
                Assert::same($result['exit_code'], 0, $result['stderr']);
                Assert::true(password_verify($password, $db->query('SELECT password_hash FROM users')->fetchColumn()));
                $hash = $db->query('SELECT password_hash FROM users')->fetchColumn();
                Assert::same(self::command($directory, $path, ['--password-stdin'], $password . "\n" . $password . "\n")['exit_code'], 2);
                Assert::same($db->query('SELECT password_hash FROM users')->fetchColumn(), $hash);
            }
            Assert::same(self::command($directory, '/missing-installation', ['--help'])['exit_code'], 0);
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
