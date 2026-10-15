<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\CheckHistoryRepository;
use Tablo\Database;
use Tablo\GitTokenRepository;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\MigrationProcess;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class HistoryConcurrencyTest
{
    private static function child(TemporaryDirectory $directory, string $path, int $id, string $action, array $args = []): void
    {
        $result = Subprocess::run([PHP_BINARY, dirname(__DIR__) . '/fixtures/history-process.php', $path, (string) $id,
            $action, ...$args], $directory, ['TABLO_GITHUB_TOKEN_KEY' => $directory->path . '/key']);
        Assert::same($result['exit_code'], 0);
        Assert::same($result['stdout'], "History child complete.\n");
        Assert::same($result['stderr'], '');
    }

    #[Test]
    public function everyConfigCredentialRevisionAndAbaMutationRejectsAcrossSecondProcess(): void
    {
        $directory = new TemporaryDirectory('tablo-history-mutations-');
        try {
            $path = $directory->path . '/db.sqlite';
            $db = Database::connect($path);
            $vault = new TokenVault($directory->path . '/key');
            $sites = new SiteRepository($db, $vault);
            $tokens = new GitTokenRepository($db, $vault);
            $token = $tokens->save(['name' => 'Shared', 'provider' => 'github', 'token' => bin2hex(random_bytes(24))]);
            $input = array_replace(UnitFixtures::site(), ['git_token_id' => $token, 'health_json_path' => '$.status', 'health_json_expected_value' => 'ok']);
            $fields = ['name' => 'Changed', 'url' => 'https://other.example', 'repository' => 'other/project', 'branch' => 'other',
                'health_path' => '/other', 'version_path' => '/other', 'version_json_path' => '$.other', 'health_check_mode' => 'json',
                'health_json_path' => '$.other', 'health_json_operator' => '!=', 'health_json_expected_value' => 'different',
                'comparison_mode' => 'branch', 'enabled' => 0, 'sort_order' => 9];
            $actions = array_merge(array_keys($fields), ['aba', 'manual', 'remove', 'shared', 'rename', 'delete']);
            foreach ($actions as $action) {
                $id = $sites->save($input);
                $snapshot = $sites->find($id);
                $state = ['online' => 0, 'checked_at' => '2026-01-01T00:00:00Z'];
                Assert::true($sites->storeCheck($snapshot, $state));
                $checkpoint = $db->query('SELECT * FROM incident_checkpoints WHERE site_id=' . $id)->fetch();
                $incident = $db->query('SELECT * FROM incidents WHERE site_id=' . $id)->fetch();
                self::child($directory, $path, $id, array_key_exists($action, $fields) ? 'field' : $action,
                    array_key_exists($action, $fields) ? [$action, json_encode($fields[$action], JSON_THROW_ON_ERROR)] : []);
                $accepted = $action === 'rename';
                Assert::same($sites->storeCheck($snapshot, $accepted ? $state : ['checked_at' => 'invalid', 'release_error_code' => 'raw-marker']), $accepted, $action);
                Assert::same((new WorkerStateRepository($db))->settle($snapshot, $accepted
                    ? $state + ['worker_service' => ['latest_commit' => true]] : ['worker_service' => 'invalid']), $accepted, $action);
                $statement = $db->prepare('SELECT COUNT(*) FROM check_history WHERE site_id=?');
                $statement->execute([$id]);
                Assert::same((int) $statement->fetchColumn(), $action === 'delete' ? 0 : ($accepted ? 3 : 1), $action);
                $statement->closeCursor();
                Assert::same($db->query('SELECT * FROM incidents WHERE site_id=' . $id)->fetch(), $action === 'delete' ? false : $incident, $action);
                if (!$accepted) {
                    Assert::same($db->query('SELECT * FROM incident_checkpoints WHERE site_id=' . $id)->fetch(), $action === 'delete' ? false : $checkpoint, $action);
                }
                if (!$accepted) { Assert::same((int) $db->query('SELECT COUNT(*) FROM worker_progress WHERE site_id=' . $id)->fetchColumn(), 0); }
            }
        } finally { unset($statement, $snapshot, $tokens, $sites, $vault, $db); $directory->close(); }
    }

    #[Test]
    public function failedBeginDoesNotOwnWriterAndLateInsertDeletionInterleavingsStayBounded(): void
    {
        $directory = new TemporaryDirectory('tablo-history-writer-');
        $writer = null;
        try {
            $path = $directory->path . '/db.sqlite';
            $db = Database::connect($path);
            $sites = new SiteRepository($db);
            $id = $sites->save(UnitFixtures::site());
            $other = $sites->save(array_replace(UnitFixtures::site(), ['name' => 'Other']));
            $snapshot = $sites->find($id);
            $state = ['online' => 1, 'checked_at' => '2026-01-01T00:00:00Z'];
            $writer = new MigrationProcess($directory, 'writer', 'writer', $path);
            $writer->awaitSignal('writer.locked');
            $db->exec('PRAGMA busy_timeout=80');
            $error = null;
            try { $sites->storeCheck($snapshot, $state); } catch (\PDOException $caught) { $error = $caught->errorInfo[1]; }
            Assert::same($error, 5);
            Assert::same((int) $db->query('SELECT COUNT(*) FROM check_history')->fetchColumn(), 0);
            Assert::same($sites->find($id)['checked_at'], null);
            unset($caught);
            file_put_contents($directory->path . '/writer.release', 'release');
            Assert::same($writer->wait()['code'], 0);
            Assert::true($sites->storeCheck($snapshot, $state));
            $sites->storeCheck($snapshot, ['checked_at' => '2025-12-01T00:00:00Z']);
            $history = new CheckHistoryRepository($db);
            $page = $history->page($id, '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z', 1);
            self::child($directory, $path, $id, 'store', ['2025-11-01T00:00:00Z']);
            $next = $history->page($id, '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z', 1, $page['next_cursor']);
            Assert::same(array_column($next['rows'], 'id'), [2], 'late old insertion excluded by first-page ceiling');
            Assert::same($next['next_cursor'], null);
            Assert::same($history->pruneBatch('2026-01-01T00:00:00Z', $id), 2);
            self::child($directory, $path, $id, 'store', ['2026-01-01T00:00:00Z']);
            self::child($directory, $path, $id, 'store', ['2025-10-01T00:00:00Z']);
            Assert::same($history->pruneBatch('2026-01-01T00:00:00Z', $id), 1);
            Assert::same($sites->find($id)['checked_at'], '2025-10-01T00:00:00Z', 'old accepted last-state is truthful after history prune');
            self::child($directory, $path, $other, 'store', ['2025-01-01T00:00:00Z']);
            self::child($directory, $path, $id, 'delete');
            Assert::same($history->pruneBatch('2026-01-01T00:00:00Z'), 1);
            Assert::same((int) $db->query('SELECT COUNT(*) FROM check_history')->fetchColumn(), 0);
            Assert::same($sites->find($other)['checked_at'], '2025-01-01T00:00:00Z');
        } finally { $writer?->close(); unset($caught, $snapshot, $history, $sites, $db); $directory->close(); }
    }

    #[Test]
    public function publicReadAndPruneCliUseOnlyFixtureDatabaseAndFixedPrivateSafeFailures(): void
    {
        $directory = new TemporaryDirectory('tablo-history-cli-');
        try {
            $root = dirname(__DIR__, 2);
            $path = $directory->path . '/db.sqlite';
            $db = Database::connect($path);
            $sites = new SiteRepository($db);
            $id = $sites->save(UnitFixtures::site());
            $sites->storeCheck($sites->find($id), ['online' => 1, 'checked_at' => '2020-01-01T00:00:00Z']);
            $before = $sites->find($id);
            $env = ['TABLO_DB' => $path, 'TABLO_GITHUB_TOKEN_KEY' => $directory->path . '/never-created.key'];
            $read = [PHP_BINARY, $root . '/bin/history.php', (string) $id, '2019-01-01T00:00:00Z', '2021-01-01T00:00:00Z'];
            $result = Subprocess::run($read, $directory, $env);
            Assert::same($result['exit_code'], 0);
            Assert::same(count(json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR)['rows']), 1);
            Assert::same($result['stderr'], '');
            foreach ([[PHP_BINARY, $root . '/bin/history.php', '999', $read[3], $read[4]],
                [PHP_BINARY, $root . '/bin/history.php', '1', 'private-marker', $read[4]],
                [...$read, '101'], [...$read, '50', 'private-marker']] as $bad) {
                $result = Subprocess::run($bad, $directory, $env);
                Assert::same($result['exit_code'], 1);
                Assert::same($result['stdout'], '');
                Assert::true(in_array($result['stderr'], ['History site not found.' . PHP_EOL, 'History read failed. Check arguments and local storage.' . PHP_EOL], true));
            }
            foreach (['0', '01', '3651', 'private-marker'] as $bad) {
                $result = Subprocess::run([PHP_BINARY, $root . '/bin/prune-history.php'], $directory,
                    $env + ['TABLO_HISTORY_RETENTION_DAYS' => $bad]);
                Assert::same($result['exit_code'], 1);
                Assert::same($result['stderr'], 'History prune failed. Check retention and local storage.' . PHP_EOL);
                Assert::same((int) $db->query('SELECT COUNT(*) FROM check_history')->fetchColumn(), 1);
            }
            $unopened = $directory->path . '/never-created.sqlite';
            $result = Subprocess::run([PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/fixtures/history-empty-retention.php', $root . '/bin/prune-history.php'], $directory,
                array_replace($env, ['TABLO_DB' => $unopened, 'TABLO_HISTORY_RETENTION_DAYS' => '']));
            Assert::same($result['exit_code'], 1);
            Assert::false(is_file($unopened), 'invalid retention refuses before opening or migrating storage');
            $result = Subprocess::run([PHP_BINARY, $root . '/bin/prune-history.php', (string) $id], $directory,
                $env + ['TABLO_HISTORY_RETENTION_DAYS' => '30']);
            Assert::same($result['exit_code'], 0);
            Assert::same(json_decode($result['stdout'], true), ['deleted' => 1, 'capped' => false]);
            Assert::same($sites->find($id), $before);
            Assert::false(is_file($env['TABLO_GITHUB_TOKEN_KEY']), 'read/prune never instantiate vault or recreate key');
        } finally { unset($before, $sites, $db); $directory->close(); }
    }
}
