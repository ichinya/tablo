<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use Tablo\Auth;
use Tablo\Database;
use Tablo\PasswordConflict;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class AdminPasswordTest
{
    #[Test]
    public function changesOnlyHashAndPreservesLimitsWithSnapshotCompatibility(): void
    {
        $db = Database::connect(':memory:');
        $auth = new Auth($db);
        $setup = $auth->setupSession('fixture-password', 'fixture-password');
        Assert::same($setup, $auth->credentialFingerprint());
        Assert::same($setup, $auth->loginSession('fixture-password', 'client'));
        for ($i = 0; $i < 5; ++$i) { $auth->login('wrong', 'blocked'); }
        $auth->login('wrong', 'unrelated');
        $limits = $db->query('SELECT * FROM login_limits ORDER BY key')->fetchAll();
        $old = $db->query('SELECT * FROM users')->fetch();
        $auth->changePassword('replacement-secret', 'replacement-secret', $setup);
        $current = $auth->credentialFingerprint();
        Assert::true($current !== $setup);
        Assert::same($db->query('SELECT * FROM login_limits ORDER BY key')->fetchAll(), $limits);
        $row = $db->query('SELECT * FROM users')->fetch();
        Assert::same($row['id'], $old['id']);
        Assert::same($row['created_at'], $old['created_at']);
        Assert::same((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(), 1);
        UnitFixtures::rejects(fn () => $auth->changePassword('replacement-secret', 'replacement-secret', $current), 'same password accepted');
        Assert::same($auth->credentialFingerprint(), $current);
        try { $auth->changePassword('another-new-secret', 'another-new-secret', $setup); }
        catch (PasswordConflict) { $conflict = true; }
        Assert::true($conflict ?? false);
        Assert::true(!$auth->login('fixture-password', 'old-client'));
        UnitFixtures::rejects(fn () => $auth->login('replacement-secret', 'blocked'), 'blocked address accepted');
        Assert::true($auth->login('replacement-secret', 'new-client'));
        Assert::same($db->query('SELECT failures FROM login_limits WHERE key = ' . $db->quote(hash('sha256', 'unrelated')))->fetchColumn(), 1);
        $db->exec('UPDATE login_limits SET window_start = 0');
        Assert::true($auth->login('replacement-secret', 'blocked'));
    }

    #[Test]
    public function rehashReturnsItsOwnCommittedHashSnapshot(): void
    {
        $db = Database::connect(':memory:');
        $hash = password_hash('fixture-password', PASSWORD_BCRYPT, ['cost' => 4]);
        $db->prepare('INSERT INTO users (id,password_hash) VALUES(1,?)')->execute([$hash]);
        $auth = new Auth($db);
        $before = $auth->credentialFingerprint();
        $snapshot = $auth->loginSession('fixture-password', 'rehash-client');
        Assert::true($snapshot !== $before);
        Assert::same($snapshot, $auth->credentialFingerprint());
        Assert::true($auth->login('fixture-password', 'compatibility-client'));
    }

    #[Test]
    public function ownsOnlySuccessfulBeginAndPreservesFailureWhenRollbackFails(): void
    {
        $directory = new TemporaryDirectory('tablo-password-failure-');
        $db = null;
        $auth = null;
        $error = null;
        try {
            $path = $directory->path . '/fixture.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup('fixture-password', 'fixture-password');
            $old = $db->query('SELECT * FROM users')->fetchAll();
            $db = null;
            foreach ([['BEGIN IMMEDIATE', false], ['COMMIT', false], ['COMMIT', true]] as [$failure, $rollback]) {
                $db = new MigrationPdo($path);
                $auth = new Auth($db);
                $snapshot = $auth->credentialFingerprint();
                $db->failBefore = $failure;
                $db->failRollback = $rollback;
                try {
                    try { $auth->changePassword('replacement-secret', 'replacement-secret', $snapshot); }
                    catch (\Throwable $caught) { $error = $caught; }
                    Assert::true($error !== null);
                    Assert::true(str_contains($error->getMessage(), 'injected_migration_failure'));
                    Assert::same(in_array('ROLLBACK', $db->statements, true), $failure !== 'BEGIN IMMEDIATE');
                } finally {
                    $caught = $error = $auth = null;
                    if ($rollback) {
                        // The fixture owns the deliberately stranded transaction.
                        $db->failBefore = null;
                        $db->failRollback = false;
                        $db->exec('ROLLBACK');
                    }
                    $db = null;
                }
                $reader = new PDO('sqlite:' . $path);
                Assert::same($reader->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC), $old);
                $reader = null;
            }
        } finally { $caught = $error = $reader = $auth = $db = null; gc_collect_cycles(); $directory->close(); }
    }
}
