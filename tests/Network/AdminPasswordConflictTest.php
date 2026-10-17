<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Auth;
use Tablo\Database;
use Tablo\Tests\Support\PasswordProcess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class AdminPasswordConflictTest extends AdminPasswordFixtureCase
{
    #[Test]
    public function selectedLegacyCommandCannotOverwriteAnActualLoginUpgrade(): void
    {
        $directory = new TemporaryDirectory('tablo-password-upgrade-conflict-'); $db = null; $process = null;
        try {
            $path = $directory->path . '/fixture.sqlite'; $db = Database::connect($path);
            $legacy = password_hash(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), PASSWORD_BCRYPT, ['cost' => 4]);
            \Tablo\SqlStatement::checked($db->prepare('INSERT INTO users(id,password_hash) VALUES(1,?)'))->execute([$legacy]);
            $process = new PasswordProcess($directory, $path);
            Assert::true((new Auth($db))->login(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), 'actual-upgrade'));
            $current = \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn());
            Assert::same(password_get_info($current)['algoName'], 'argon2id');
            $process->send(\Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-secret'));
            Assert::same($process->finish()['exit_code'], 4);
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn(), $current);
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $process?->close(); $db = null; $directory->close(); }
    }


    #[Test]
    public function twoActualCommandsCannotOverwriteTheSameSnapshot(): void
    {
        $directory = new TemporaryDirectory('tablo-password-race-');
        $db = null;
        $second = null; $first = null;
        try {
            $path = $directory->path . '/fixture.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            $uri = 'file:' . str_replace('%2F', replace: '/', subject: rawurlencode(str_replace('\\', replace: '/', subject: $path)));
            $safeUri = $uri . '?mode=rw&nolock=0&immutable=false';
            foreach ([[$path, $path], [$safeUri, $safeUri], [$path, $safeUri], [$safeUri, $path]] as [$selection, $contender]) {
                $first = new PasswordProcess($directory, $selection);
                $second = new PasswordProcess($directory, $contender);
                $first->send("first-new-secret\nfirst-new-secret\n");
                $second->send("second-new-secret\nsecond-new-secret\n");
                $firstResult = $first->finish();
                $secondResult = $second->finish();
                $hash = \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users WHERE id=1'))->fetchColumn());
                Assert::same(password_verify('first-new-secret', \Tablo\Tests\Support\FixtureValue::string($hash)), $firstResult['exit_code'] === 0);
                Assert::same(password_verify('second-new-secret', \Tablo\Tests\Support\FixtureValue::string($hash)), $secondResult['exit_code'] === 0);
                $hash = null;
                $results = [$firstResult['exit_code'], $secondResult['exit_code']];
                sort($results);
                Assert::same($results, [0, 4]);
                Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM users'))->fetchColumn(), 1);
                Assert::true(!(new Auth($db))->needsSetup());
                $first->close(); $second->close(); $second = null; $first = null;
                Assert::same(\Tablo\Tests\Support\FixtureValue::at(self::command($directory, $selection, ['--password-stdin'], "later-new-secret\nlater-new-secret\n"), 'exit_code'), 0);
                Assert::true((new Auth($db))->login('later-new-secret', 'client'));
            }
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $first?->close(); $second?->close(); $db = null; $directory->close(); }
    }
}
