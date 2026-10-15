<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\GitTokenRepository;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;



final class DatabaseHistoricalImportTest
{








    #[Test]
    public function importsHistoricalAndMixedUnversionedSchemasWithoutChangingSecrets(): void
    {
        $schemas = HistoricalSchemas::all();
        foreach ($schemas as $schema) {
            $directory = new TemporaryDirectory('tablo-schema-upgrade-');
            try {
                $path = $directory->path . '/test.sqlite';
                $old = new MigrationPdo($path);
                $old->exec(\Tablo\Tests\Support\FixtureValue::string($schema));
                $hash = password_hash('synthetic-admin-password', PASSWORD_BCRYPT, ['cost' => 4]);
                \Tablo\SqlStatement::checked($old->prepare('INSERT INTO users (id, password_hash) VALUES (1, ?)'))->execute([$hash]);
                $old->exec("INSERT INTO login_limits VALUES ('synthetic-address', 2, 123)");
                $vault = new TokenVault($directory->path . '/key');
                $cipher = $vault->encrypt('synthetic-individual-token');
                $fields = HistoricalSiteSeed::fields();
                $individual = str_contains(\Tablo\Tests\Support\FixtureValue::string($schema), 'github_token TEXT');
                $saved = str_contains(\Tablo\Tests\Support\FixtureValue::string($schema), 'CREATE TABLE IF NOT EXISTS git_tokens');
                if ($individual) { $fields['github_token'] = $cipher; }
                \Tablo\SqlStatement::checked($old->prepare('INSERT INTO sites (' . implode(',', array_keys($fields)) . ') VALUES ('
                    . implode(',', array_fill(0, count($fields), value: '?')) . ')'))->execute(array_values($fields));
                if ($saved) {
                    $tokens = new GitTokenRepository($old, $vault);
                    $token = $tokens->save(['name' => 'Saved', 'provider' => 'github', 'token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-shared-token')]);
                    \Tablo\SqlStatement::checked($old->prepare("INSERT INTO sites (name,url,repository,git_token_id) VALUES ('Shared','https://example.com','example/shared',?)"))
                        ->execute([$token]);
                    unset($tokens);
                }
                $before = \Tablo\Tests\Support\DatabaseAssertions::snapshot($old);
                $keyHash = hash_file('sha256', $directory->path . '/key');
                unset($old);
                $db = Database::connect($path);
                Database::migrate($db);
                $after = \Tablo\Tests\Support\DatabaseAssertions::snapshot($db);
                Assert::same($after['version'], Database::CURRENT_SCHEMA_VERSION);
                HistoricalRowsAssertions::retained($before, $after);
                Assert::true(password_verify('synthetic-admin-password', \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn())));
                Assert::same(hash_file('sha256', $directory->path . '/key'), $keyHash);
                $sites = new SiteRepository($db, $vault);
                $site = $sites->find(1);
                Assert::same(\Tablo\Tests\Support\FixtureValue::row($site)['health_check_mode'], 'http');
                Assert::same(\Tablo\Tests\Support\FixtureValue::row($site)['health_json_operator'], '==');
                foreach (['version_json_path', 'health_json_path', 'health_json_expected_value'] as $column) {
                    Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($site), $column), '');
                }
                Assert::same($sites->tokenFor($site), $individual ? 'synthetic-individual-token' : '');
                if ($saved) { Assert::same($sites->tokenFor($sites->find(2)), \Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-shared-token')); }
                Assert::same(\Tablo\SqlStatement::checked($db->query('PRAGMA foreign_key_check'))->fetchAll(), []);
                unset($db, $sites, $vault);
            } finally {
                unset($db, $old, $sites, $tokens, $vault);
                $directory->close();
            }
        }
    }
}
