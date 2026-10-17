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
            $key = random_bytes(32); $session = bin2hex(random_bytes(32));
            file_put_contents($directory->path . '/github-token.key', $key);
            mkdir($directory->path . '/sessions');
            file_put_contents($directory->path . '/sessions/sess_reset', $session);
            self::assertBcryptRecovery($directory);
            Assert::same(file_get_contents($directory->path . '/github-token.key'), $key);
            Assert::same(file_get_contents($directory->path . '/sessions/sess_reset'), $session);
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

    private static function assertBcryptRecovery(TemporaryDirectory $directory): void
    {
        foreach (['2y', '2a', '2b', '2x', 'malformed'] as $variant) {
            $path = $directory->path . '/' . $variant . '.sqlite'; $db = Database::connect($path);
            $unknown = bin2hex(random_bytes(24));
            $original = '$' . ($variant === 'malformed' ? '2a' : $variant) . '$' . substr(password_hash($unknown, PASSWORD_BCRYPT, ['cost' => 4]), offset: 4);
            Assert::true(password_verify($unknown, $original)); unset($unknown);
            if ($variant === 'malformed') { $original = substr($original, offset: 0, length: -1); }
            \Tablo\SqlStatement::checked($db->prepare('INSERT INTO users(id,password_hash) VALUES(1,?)'))->execute([$original]);
            $selected = \Tablo\AdministratorFingerprint::fingerprint($original); $secret = \Tablo\ReadApiToken::generate();
            \Tablo\SqlStatement::checked($db->prepare('INSERT INTO read_api_token VALUES(1,?,?,?)'))->execute([
                \Tablo\ReadApiToken::digest($secret), $selected, '2026-10-14T10:00:00Z',
            ]);
            $identity = \Tablo\Tests\Support\ReadApiAssertions::rows($db, 'SELECT id,created_at FROM users');
            $before = \Tablo\Tests\Support\ReadApiAssertions::snapshot($db); $db = null;
            $bytes = hash_file('sha256', $path);
            $result = self::command($directory, $path, ['--password-stdin'], "replacement-reset-secret\nreplacement-reset-secret\n");
            $accepted = in_array($variant, ['2y', '2a', '2b'], strict: true);
            Assert::same($result['exit_code'], $accepted ? 0 : 3, $result['stderr']);
            Assert::false(str_contains($result['stdout'] . $result['stderr'], 'replacement-reset-secret'));
            if (!$accepted) { Assert::same(hash_file('sha256', $path), $bytes, 'refusal retains every original database byte'); }
            $db = new \PDO('sqlite:' . $path, options: [\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
            $after = \Tablo\Tests\Support\ReadApiAssertions::snapshot($db);
            if ($accepted) {
                $current = \Tablo\InstalledAdministrator::existingAdminHash($db);
                Assert::same(password_get_info($current)['algoName'], 'argon2id');
                Assert::true(password_verify('replacement-reset-secret', $current));
                Assert::false(hash_equals($selected, \Tablo\Tests\Support\FixtureValue::string((new Auth($db))->credentialFingerprint())));
                Assert::false((new \Tablo\ReadApiTokenRepository($db))->authenticate($secret));
                Assert::same(\Tablo\Tests\Support\ReadApiAssertions::rows($db, 'SELECT id,created_at FROM users'), $identity);
                unset($before['users'], $after['users']);
                Assert::same(substr_count($result['stdout'], needle: 'Administrator password changed.'), 1);
                Assert::same(self::command($directory, $path, ['--password-stdin'], "replacement-reset-secret\nreplacement-reset-secret\n")['exit_code'], 2);
                Assert::same(\Tablo\InstalledAdministrator::existingAdminHash($db), $current, 'second identical request makes no second change');
            }
            Assert::same($after, $before, 'complete token and all unrelated rows retained'); $db = null;
        }
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
