<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SchemaReference;
use Tablo\SelfUpdateRepository;
use Tablo\SelfUpdateSchema;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Testo\Assert;
use Testo\Test;

final class SelfUpdateMigrationTest
{
    #[Test]
    public function additiveTenToElevenPreservesRowsAndRollsBackAtEveryPublicationStage(): void
    {
        foreach (['CREATE TABLE self_update', 'INSERT INTO self_update', 'PRAGMA main.user_version = 11', 'COMMIT', null] as $failure) {
            $db = new MigrationPdo(':memory:'); SchemaReference::reference(10, ['endpoint' => true], $db);
            $db->exec("PRAGMA user_version=10; INSERT INTO users(id,password_hash) VALUES(1,'fixture-password-hash');
                INSERT INTO sites(name,url,repository,github_token) VALUES('preserved','https://example.test','fixture/repo','fixture-ciphertext');
                INSERT INTO read_api_token VALUES(1,'" . str_repeat('a', times: 64) . "','" . str_repeat('b', times: 64) . "','2026-10-28T00:00:00Z')");
            $before = A::snapshot($db); $db->statements = []; $db->failBefore = $failure;
            if ($failure !== null) {
                $caught = false;
                try { Database::migrate($db); } catch (\PDOException) { $caught = true; }
                Assert::true($caught); Assert::same(A::snapshot($db), $before); $db->failBefore = null;
            }
            $db->statements = []; Database::migrate($db);
            Assert::same(array_values(array_filter($db->statements, static fn (string $sql): bool => str_starts_with($sql, 'CREATE'))), [SelfUpdateSchema::sql()]);
            $after = A::snapshot($db);
            foreach (array_keys($before) as $name) {
                if (in_array($name, ['version', 'schema'], strict: true)) { continue; }
                Assert::same($after[$name] ?? null, $before[$name] ?? null);
            }
            Assert::same((new SelfUpdateRepository($db))->get()['interval_minutes'], 360);
            $db->statements = []; Database::migrate($db);
            Assert::same(array_values(array_filter($db->statements, static fn (string $sql): bool => preg_match('/^(BEGIN|CREATE|ALTER|DROP|INSERT|UPDATE|COMMIT)/', $sql) === 1)), []);
        }
    }
}
