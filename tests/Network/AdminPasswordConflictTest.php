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
                $hash = $db->query('SELECT password_hash FROM users WHERE id=1')->fetchColumn();
                Assert::same(password_verify('first-new-secret', $hash), $firstResult['exit_code'] === 0);
                Assert::same(password_verify('second-new-secret', $hash), $secondResult['exit_code'] === 0);
                $hash = null;
                $results = [$firstResult['exit_code'], $secondResult['exit_code']];
                sort($results);
                Assert::same($results, [0, 4]);
                Assert::same((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(), 1);
                Assert::true(!(new Auth($db))->needsSetup());
                $first->close(); $second->close(); $second = null; $first = null;
                Assert::same(self::command($directory, $selection, ['--password-stdin'], "later-new-secret\nlater-new-secret\n")['exit_code'], 0);
                Assert::true((new Auth($db))->login('later-new-secret', 'client'));
            }
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $first?->close(); $second?->close(); $db = null; $directory->close(); }
    }
}
