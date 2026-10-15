<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use Tablo\Auth;
use Tablo\Database;
use Tablo\PasswordConflict;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class AdminPasswordTest
{
    #[Test]
    public function changesOnlyHashAndPreservesLimitsWithSnapshotCompatibility(): void
    {
        $db = Database::connect(':memory:');
        $auth = new Auth($db);
        $setup = $auth->setupSession(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
        Assert::same($setup, $auth->credentialFingerprint());
        Assert::same($setup, $auth->loginSession(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), 'client'));
        for ($i = 0; $i < 5; ++$i) { $auth->login('wrong', 'blocked'); }
        $auth->login('wrong', 'unrelated');
        $limits = \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM login_limits ORDER BY key'))->fetchAll(), \Tablo\SqlLoginLimitsFailuresKeyWindowStartRow::checked(...));
        $old = \Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('SELECT * FROM users'))->fetch(), \Tablo\SqlUsersCreatedAtIdPasswordHashRow::checked(...));
        $auth->changePassword(\Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret'), $setup);
        $current = \Tablo\Tests\Support\FixtureValue::string($auth->credentialFingerprint());
        Assert::true($current !== $setup);
        Assert::same(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM login_limits ORDER BY key'))->fetchAll(), \Tablo\SqlLoginLimitsFailuresKeyWindowStartRow::checked(...)), $limits);
        $row = \Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('SELECT * FROM users'))->fetch(), \Tablo\SqlUsersCreatedAtIdPasswordHashRow::checked(...));
        Assert::same(\Tablo\Tests\Support\FixtureValue::fetched($row)['id'], \Tablo\Tests\Support\FixtureValue::fetched($old)['id']);
        Assert::same(\Tablo\Tests\Support\FixtureValue::fetched($row)['created_at'], \Tablo\Tests\Support\FixtureValue::fetched($old)['created_at']);
        Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM users'))->fetchColumn(), 1);
        (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(static fn () => $auth->changePassword(\Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret'), $current))))->verify('same password accepted');
        Assert::same($auth->credentialFingerprint(), $current);
        try { $auth->changePassword('another-new-secret', 'another-new-secret', $setup); }
        catch (PasswordConflict) { $conflict = true; }
        Assert::true($conflict ?? false);
        Assert::true(!$auth->login(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), 'old-client'));
        (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(static fn () => $auth->login(\Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret'), 'blocked'))))->verify('blocked address accepted');
        Assert::true($auth->login(\Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret'), 'new-client'));
        Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT failures FROM login_limits WHERE key = ' . \Tablo\Tests\Support\FixtureValue::string($db->quote(hash('sha256', data: 'unrelated')))))->fetchColumn(), 1);
        $db->exec('UPDATE login_limits SET window_start = 0');
        Assert::true($auth->login(\Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret'), 'blocked'));
    }

    #[Test]
    public function rehashReturnsItsOwnCommittedHashSnapshot(): void
    {
        $db = Database::connect(':memory:');
        $hash = password_hash(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), PASSWORD_BCRYPT, ['cost' => 4]);
        \Tablo\SqlStatement::checked($db->prepare('INSERT INTO users (id,password_hash) VALUES(1,?)'))->execute([$hash]);
        $auth = new Auth($db);
        $before = $auth->credentialFingerprint();
        $snapshot = $auth->loginSession(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), 'rehash-client');
        Assert::true($snapshot !== $before);
        Assert::same($snapshot, $auth->credentialFingerprint());
        Assert::true($auth->login(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), 'compatibility-client'));
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
            (new Auth($db))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            $old = \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM users'))->fetchAll(), \Tablo\SqlUsersCreatedAtIdPasswordHashRow::checked(...));
            $db = null;
            foreach ([['BEGIN IMMEDIATE', false], ['COMMIT', false], ['COMMIT', true]] as [$failure, $rollback]) {
                $db = new MigrationPdo($path);
                $auth = new Auth($db);
                $snapshot = $auth->credentialFingerprint();
                $db->failBefore = $failure;
                $db->failRollback = $rollback;
                try {
                    try { $auth->changePassword(\Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret'), \Tablo\Tests\Support\FixtureValue::string($snapshot)); }
                    catch (\Throwable $caught) { $error = $caught; }
                    Assert::true($error !== null);
                    Assert::true(str_contains(\Tablo\Tests\Support\FixtureValue::object($error)->getMessage(), 'injected_migration_failure'));
                    Assert::same(in_array('ROLLBACK', $db->statements, strict: true), $failure !== 'BEGIN IMMEDIATE');
                } finally {
                    $auth = null; $error = null; $caught = null;
                    if ($rollback) {
                        // The fixture owns the deliberately stranded transaction.
                        $db->failBefore = null;
                        $db->failRollback = false;
                        $db->exec('ROLLBACK');
                    }
                    $db = null;
                }
                $reader = new PDO('sqlite:' . $path);
                Assert::same(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($reader->query('SELECT * FROM users'))->fetchAll(PDO::FETCH_ASSOC), \Tablo\SqlUsersCreatedAtIdPasswordHashRow::checked(...)), $old);
                $reader = null;
            }
        } finally { $db = null; $auth = null; $reader = null; $error = null; $caught = null; gc_collect_cycles(); $directory->close(); }
    }
}
