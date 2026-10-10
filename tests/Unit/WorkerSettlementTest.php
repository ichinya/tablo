<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDOException;
use Tablo\Database;
use Tablo\GitTokenRepository;
use Tablo\SiteRepository;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class WorkerSettlementTest
{
    #[Test]
    public function resultProgressAndCommitFailuresRollBackBothAndKeepCallerTransaction(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-settlement-');
        try {
            $db = Database::connect($directory->path . '/db.sqlite');
            $sites = new SiteRepository($db);
            $id = $sites->save(array_replace(UnitFixtures::site(), ['repository' => 'fixture/project']));
            $snapshot = $sites->find($id);
            $state = new WorkerStateRepository($db);
            $result = ['online' => 1, 'checked_at' => '2026-01-01T00:00:00Z',
                'worker_service' => ['latest_release' => true, 'open_issues' => true]];
            // Real deferred foreign-key violation fails COMMIT, after both writes succeed.
            $db->exec('CREATE TABLE commit_control (parent INTEGER REFERENCES sites(id) DEFERRABLE INITIALLY DEFERRED)');
            foreach ([
                'store-control' => "BEFORE UPDATE OF checked_at ON sites BEGIN SELECT RAISE(ABORT, 'store-control'); END",
                'progress-control' => "BEFORE INSERT ON worker_progress BEGIN SELECT RAISE(ABORT, 'progress-control'); END",
                'turn-control' => "BEFORE UPDATE OF fairness_turn ON worker_runtime BEGIN SELECT RAISE(ABORT, 'turn-control'); END",
                'FOREIGN KEY constraint failed' => 'AFTER UPDATE OF checked_at ON sites BEGIN INSERT INTO commit_control VALUES (-1); END',
            ] as $diagnostic => $trigger) {
                $db->exec('CREATE TRIGGER refusal ' . $trigger);
                $error = null;
                try { $state->settle($snapshot, $result); } catch (PDOException $caught) { $error = $caught; }
                Assert::instanceOf($error, PDOException::class);
                Assert::true(str_contains($error->getMessage(), $diagnostic), 'original settlement failure: ' . $diagnostic);
                Assert::false($db->inTransaction());
                Assert::same($sites->find($id)['checked_at'], null);
                Assert::same((int) $db->query('SELECT COUNT(*) FROM worker_progress')->fetchColumn(), 0);
                Assert::same((int) $db->query('SELECT fairness_turn FROM worker_runtime')->fetchColumn(), 0);
                Assert::same((int) $db->query('SELECT COUNT(*) FROM commit_control')->fetchColumn(), 0);
                unset($error, $caught);
                $db->exec('DROP TRIGGER refusal');
            }
            $db->beginTransaction();
            $db->exec('UPDATE installation_settings SET check_interval_minutes = 7');
            try { $state->settle($snapshot, $result); Assert::true(false); } catch (PDOException) {}
            Assert::true($db->inTransaction(), 'failed BEGIN leaves caller-owned transaction intact');
            Assert::same((int) $db->query('SELECT check_interval_minutes FROM installation_settings')->fetchColumn(), 7);
            $db->rollBack();
            Assert::true($state->settle($snapshot, $result));
            Assert::same($sites->find($id)['checked_at'], $result['checked_at']);
            Assert::same($state->turns($id, $snapshot['config_revision']),
                ['latest_release' => 1, 'latest_commit' => 0, 'open_issues' => 2, 'open_prs' => 0]);
            Assert::same((int) $db->query('SELECT fairness_turn FROM worker_runtime')->fetchColumn(), 2);
        } finally { unset($caught, $error, $state, $snapshot, $sites, $db); $directory->close(); }
    }

    #[Test]
    public function staleDisabledDeletedAbaAndRotatedCredentialsEarnNoCredit(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-settlement-stale-');
        try {
            $db = Database::connect($directory->path . '/db.sqlite');
            $sites = new SiteRepository($db);
            $tokens = new GitTokenRepository($db);
            $token = $tokens->save(['name' => 'Shared', 'provider' => 'github', 'token' => bin2hex(random_bytes(24))]);
            $input = array_replace(UnitFixtures::site(), ['repository' => 'fixture/project', 'git_token_id' => $token]);
            $state = new WorkerStateRepository($db);
            $result = ['checked_at' => '2026-01-01T00:00:00Z', 'worker_service' => array_fill_keys($state::FIELDS, true)];
            foreach (['edit', 'disable', 'delete', 'aba', 'rotation', 'individual', 'name', 'cipher'] as $action) {
                $id = $sites->save($input);
                $snapshot = $sites->find($id);
                match ($action) {
                    'edit' => $sites->save($input, $id),
                    'disable' => $sites->save(array_replace($input, ['enabled' => 0]), $id),
                    'delete' => $sites->delete($id),
                    'aba' => (function () use ($sites, $input, $id): void {
                        $sites->save(array_replace($input, ['enabled' => 0]), $id); $sites->save($input, $id);
                    })(),
                    'rotation' => $tokens->save(['name' => 'Shared', 'provider' => 'github', 'token' => bin2hex(random_bytes(24))], $token),
                    'individual' => $sites->save(array_replace($input, ['git_token_id' => '', 'github_token' => bin2hex(random_bytes(24))]), $id),
                    'name' => $db->exec("UPDATE sites SET name = 'changed-without-revision' WHERE id = " . $id),
                    'cipher' => $db->exec("UPDATE git_tokens SET encrypted_token = 'changed-without-revision' WHERE id = " . $token),
                };
                Assert::false($state->settle($snapshot, $result), $action);
                Assert::same((int) $db->query('SELECT COUNT(*) FROM worker_progress')->fetchColumn(), 0);
                Assert::same((int) $db->query('SELECT fairness_turn FROM worker_runtime')->fetchColumn(), 0);
            }
        } finally { unset($state, $snapshot, $tokens, $sites, $db); $directory->close(); }
    }
}
