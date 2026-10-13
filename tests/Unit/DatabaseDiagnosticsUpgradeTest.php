<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\Tests\Support\MigrationPdo;
use Testo\Assert;
use Testo\Test;



final class DatabaseDiagnosticsUpgradeTest
{


    #[Test]
    public function upgradesVersionOneDiagnosticsAtomicallyWithoutChangingExistingRows(): void
    {
        foreach ([null, 'ALTER TABLE sites ADD COLUMN health_http_status', 'PRAGMA main.user_version = 9', 'COMMIT'] as $failure) {
            $db = new MigrationPdo(':memory:');
            $db->exec(\Tablo\Tests\Support\DatabaseAssertions::schema());
            $db->exec("PRAGMA user_version = 1;
                INSERT INTO users (id,password_hash) VALUES (1,'synthetic-hash');
                INSERT INTO sites (name,url,repository,github_token,health_path,online,last_error)
                VALUES ('Existing','https://example.com/app','example/project','synthetic-ciphertext','/up',0,'Health: HTTP 503')");
            $before = \Tablo\Tests\Support\DatabaseAssertions::snapshot($db);
            $db->failBefore = $failure;
            if ($failure !== null) {
                Assert::true(str_contains(\Tablo\Tests\Support\DatabaseAssertions::errorMessage(static fn () => Database::migrate($db)) ?? '', 'injected_migration_failure'));
                Assert::same(\Tablo\Tests\Support\DatabaseAssertions::snapshot($db), $before, 'failed version 2 migration leaves version 1 intact');
                $db->failBefore = null;
            }
            Database::migrate($db);
            $after = \Tablo\Tests\Support\DatabaseAssertions::snapshot($db);
            Assert::same($after['version'], Database::CURRENT_SCHEMA_VERSION);
            foreach ($before['rows'] as $table => $rows) {
                foreach ($rows as $index => $row) {
                    Assert::same(array_intersect_key(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::at($after['rows'], $table), $index), $row), $row);
                }
            }
            Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::at($after['rows'], 'sites'), 0), 'health_error_code'), null);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::at($after['rows'], 'sites'), 0), 'health_http_status'), null);
            $db->statements = [];
            Database::migrate($db);
            Assert::same(array_values(array_filter($db->statements, static fn (string $sql): bool => preg_match('/^(BEGIN|CREATE|ALTER|DROP|INSERT|UPDATE|COMMIT|ROLLBACK)/', $sql) === 1)), []);
        }
    }
}
