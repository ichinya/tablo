<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitTokenRepository;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\IncidentFixtures as F;
use Tablo\Tests\Support\MigrationProcess;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\WorkerProcess;
use Testo\Assert;
use Testo\Test;

final class IncidentConcurrencyTest
{
    #[Test]
    public function waitingMigratorRechecksActualSchemaFiveWithoutReplayOrDuplicateDdl(): void
    {
        $directory = new TemporaryDirectory('tablo-incidents-upgrade-');
        $second = null; $first = null;
        try {
            $path = $directory->path . '/db.sqlite';
            $db = new MigrationPdo($path);
            $db->exec(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__) . '/fixtures/historical-schema/history5.sql')));
            $db->exec("INSERT INTO sites(id,name,url,repository) VALUES(1,'Historical','https://example.test/','fixture/public');
                INSERT INTO check_history(id,site_id,config_revision,checked_at,online,version_status) VALUES(1,1,0,'2020-01-01T00:00:00Z',0,'skipped')");
            unset($sites, $db);
            $first = new MigrationProcess($directory, 'first', 'migrate-first', $path);
            $first->awaitSignal('first.locked');
            $second = new MigrationProcess($directory, 'second', 'migrate-second', $path);
            $second->awaitSignal('second.before-begin');
            file_put_contents($directory->path . '/first.release', data: 'go');
            $a = $first->wait();
            $b = $second->wait();
            Assert::same($a['code'], 0);
            Assert::same($b['code'], 0);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(json_decode($a['stdout'], associative: true)), 'ddl'), 14,
                'endpoint ALTER plus four history widening CREATE/ALTER statements plus four incident and five notification CREATE statements');
            Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(json_decode($b['stdout'], associative: true)), 'ddl'), 0);
            $db = Database::connect($path);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM check_history'))->fetchColumn(), 1);
            Assert::same(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetchAll(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...)), []);
            Assert::same(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incident_checkpoints'))->fetchAll(), \Tablo\SqlIncidentCheckpointsRow::checked(...)), []);
        } finally { $second?->close(); $first?->close(); unset($sites, $db); $directory->close(); }
    }

    #[Test]
    public function independentAcceptPruneAndDeleteWritersSerializeWithoutRekeyOrResurrection(): void
    {
        $directory = new TemporaryDirectory('tablo-incidents-writers-');
        $children = [];
        try {
            $path = $directory->path . '/db.sqlite';
            $db = Database::connect($path);
            $sites = new SiteRepository($db);
            $id = $sites->save(F::site());
            $other = $sites->save(F::site());
            $script = dirname(__DIR__) . '/fixtures/incident-process.php';
            $gate = $directory->path . '/gate';
            foreach (['one', 'two'] as $name) {
                $child = new WorkerProcess($directory, $name, [PHP_BINARY, $script, $path, (string) $id, 'accept'], ['TABLO_INCIDENT_GATE' => $gate]);
                $children[] = $child;
                $child->awaitOutput('Ready');
            }
            file_put_contents($gate, data: 'go');
            foreach ($children as $child) { Assert::same($child->wait()['exit_code'], 0); }
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM check_history'))->fetchColumn(), 2);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM incidents'))->fetchColumn(), 1);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT last_history_id FROM incident_checkpoints'))->fetchColumn(), 2);
            $anchor = \Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetch(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...));
            $maintenance = [];
            foreach (['prune', 'accept'] as $action) {
                $child = new WorkerProcess($directory, $action, [PHP_BINARY, $script, $path, (string) $id, $action], []);
                $children[] = $child;
                $maintenance[] = $child;
            }
            foreach ($maintenance as $child) { Assert::same($child->wait()['exit_code'], 0); }
            Assert::same(\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetch(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...)), $anchor);
            F::accept($sites, $id, 1, '2020-01-01T00:01:00Z');
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT recovery_history_id FROM incidents'))->fetchColumn(), 4);
            F::accept($sites, $other, 0, '2020-01-01T00:00:00Z');
            $nextGate = $directory->path . '/after-delete';
            $late = new WorkerProcess($directory, 'late', [PHP_BINARY, $script, $path, (string) $id, 'accept'], ['TABLO_INCIDENT_GATE' => $nextGate]);
            $children[] = $late;
            $late->awaitOutput('Ready');
            $delete = Subprocess::run([PHP_BINARY, $script, $path, (string) $id, 'delete'], $directory, []);
            Assert::same($delete['exit_code'], 0);
            file_put_contents($nextGate, data: 'go');
            $result = $late->wait();
            (new IncidentWriterAssertions($db))->noResurrection($result, $other);
        } finally { foreach ($children as $child) { $child->close(); } unset($maintenance, $children, $child, $sites, $db); $directory->close(); }
    }

    #[Test]
    public function actualSharedCredentialRotationInterruptsOnlyAfterAcceptedNewRevisionAndRenameIsPositive(): void
    {
        $directory = new TemporaryDirectory('tablo-incidents-rotation-');
        try {
            $db = Database::connect($directory->path . '/db.sqlite');
            $vault = new TokenVault($directory->path . '/key');
            $sites = new SiteRepository($db, $vault);
            $tokens = new GitTokenRepository($db, $vault);
            $token = $tokens->save(['name' => 'Shared', 'provider' => 'github', 'token' => bin2hex(random_bytes(24))]);
            $input = array_replace(F::site(), ['git_token_id' => $token]);
            $id = $sites->save($input);
            $other = $sites->save($input);
            F::accept($sites, $id, 0, '2026-01-01T00:00:00Z');
            F::accept($sites, $other, 0, '2026-01-01T00:00:00Z');
            $snapshot = $sites->find($id);
            $tokens->save(['name' => 'Renamed', 'provider' => 'github', 'token' => ''], $token);
            Assert::true($sites->storeCheck(\Tablo\Tests\Support\FixtureValue::row($snapshot), ['online' => 0, 'checked_at' => '2026-01-01T00:00:01Z']));
            $before = \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetchAll(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...));
            $tokens->save(['name' => 'Renamed', 'provider' => 'github', 'token' => bin2hex(random_bytes(24))], $token);
            Assert::false($sites->storeCheck(\Tablo\Tests\Support\FixtureValue::row($snapshot), ['checked_at' => 'invalid']));
            Assert::same(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetchAll(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...)), $before);
            F::accept($sites, $id, 1, '2026-01-01T00:00:02Z');
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT end_reason FROM incidents WHERE site_id=1'))->fetchColumn(), 'config-changed');
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT recovered_at FROM incidents WHERE site_id=1'))->fetchColumn(), null);
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT end_reason FROM incidents WHERE site_id=2'))->fetchColumn(), null);
        } finally { unset($snapshot, $sites, $tokens, $vault, $db); $directory->close(); }
    }

    #[Test]
    public function independentWriterFailedBeginHasNoHistoryIncidentOrCheckpointAndConnectionReuses(): void
    {
        $directory = new TemporaryDirectory('tablo-incidents-begin-');
        $writer = null;
        try {
            $path = $directory->path . '/db.sqlite';
            $db = Database::connect($path);
            $sites = new SiteRepository($db);
            $id = $sites->save(F::site());
            $writer = new MigrationProcess($directory, 'writer', 'writer', $path);
            $writer->awaitSignal('writer.locked');
            $db->exec('PRAGMA busy_timeout=80');
            $before = F::rows($db);
            $error = F::error(static fn () => F::accept($sites, $id, 0, '2026-01-01T00:00:00Z'));
            Assert::same(\Tablo\Tests\Support\FixtureNative::sqliteCode(\Tablo\Tests\Support\FixtureNative::pdoException($error)), 5);
            Assert::same(F::rows($db), $before);
            unset($error);
            file_put_contents($directory->path . '/writer.release', data: 'go');
            Assert::same($writer->wait()['code'], 0);
            Assert::true(F::accept($sites, $id, 0, '2026-01-01T00:00:00Z'));
        } finally { $writer?->close(); unset($sites, $db); $directory->close(); }
    }
}
