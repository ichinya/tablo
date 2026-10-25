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
        $second = null; $first = null; $db = null;
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
            $firstObservation = \Tablo\JsonRecord::decode($a['stdout']);
            $statements = array_map(\Tablo\Tests\Support\FixtureValue::string(...), \Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($firstObservation, 'ddl_statements')));
            $idleStatements = array_values(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'ALTER TABLE installation_settings ADD COLUMN session_idle_timeout_minutes')));
            Assert::same(count($idleStatements), 1, 'exact idle ALTER executes once');
            $tokenStatements = array_values(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'CREATE TABLE read_api_token')));
            Assert::same($tokenStatements, [\Tablo\ReadApiTokenSchema::sql()], 'exact API token CREATE executes once');
            $originalStatements = array_values(array_filter($statements, static fn (string $sql): bool => !in_array($sql, [...$idleStatements, ...$tokenStatements], strict: true)));
            Assert::same(count($originalStatements), 14, 'preserve original endpoint/history/incident/notification DDL count');
            // Frozen original schema-five migration DDL content and order; idle/token additions are checked separately.
            Assert::same(array_map(static fn (string $sql): string => hash('sha256', $sql), $originalStatements), [
                '4f176a1573788304019359ba6d825cfe61587a70b7104025f73a8e6e167cc9e2',
                '699e8a6c856abf4e5f024a6cfb1a21a0b154df9399c63bc5f680acf54bff43b1',
                'e43c4807b1df077bfe5244528212bb0d0fe60393f72ab63f54910e7495af69b2',
                '6e013f9801acd3016aa987b1a03d45027e3e2ab6c560eb35730a5ec4d571d2c7',
                '60d35c6a155ab2e8b12e6022ccc46cab8d875b21e253a8fd9c581570c8bfef92',
                '630180b30de3b7c128734617813ef8983f5d6672837d151f4025d1ec6b622760',
                'fdd3a4d6c18ab48376b7f9a60514580b07f32ef81ec7279c8c5b32694c57a3ec',
                '5ac1eb592f8b35bed74319ebde91e775810ad8a4bbaf155775c1e3deefc4fc9f',
                '6b76556fb404c44bf5ce317cebd547b16f47447371bfb52c00f96eeee37a9677',
                'fd2fb056357da1a696bee5ca375c41d2eb5f328dd16e3ef18a56c2479a082e7f',
                'eb418331a4f38efa268aa37a33c640060376854e5194833a526979606f5fb1de',
                '5ee08c08cb8642c86f74aafe80e089ab56fb825877dd7596a40c7f25b1547d1b',
                '116e2fecdd1624b36957083165ef5579a82a16f08c918c0c7dacdabf330e8dc9',
                '8a4070d31881a873114b8518413e329abefa57207a9caef94c0311ac7244e2ce',
            ]);
            Assert::same(array_slice($statements, offset: -2), [...$idleStatements, \Tablo\ReadApiTokenSchema::sql()], 'idle then API token DDL follow the unchanged original sequence');
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($firstObservation, 'ddl'), 16, 'original fourteen statements plus exactly one idle ALTER and one token CREATE');
            Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(json_decode($b['stdout'], associative: true)), 'ddl'), 0);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::decode($b['stdout']), 'ddl_statements'), [], 'waiting opener replays no DDL');
            $db = Database::connect($path);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM check_history'))->fetchColumn(), 1);
            Assert::same(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetchAll(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...)), []);
            Assert::same(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incident_checkpoints'))->fetchAll(), \Tablo\SqlIncidentCheckpointsRow::checked(...)), []);
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally {
            $directory->cleanup(static function () use ($second, $first, &$db, $directory): void {
                $second?->close(); $first?->close(); $db = null; $directory->close();
            });
        }
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
