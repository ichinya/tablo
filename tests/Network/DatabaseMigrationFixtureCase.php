<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use RuntimeException;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\MigrationProcess;

abstract class DatabaseMigrationFixtureCase
{

    protected static function legacy(string $path, \Tablo\Tests\Support\SchemaFixture $fixture = \Tablo\Tests\Support\SchemaFixture::Historic): void
    {
        $db = new MigrationPdo($path);
        $db->exec(\Tablo\Tests\Support\DatabaseAssertions::schema($fixture));
        if ($fixture === \Tablo\Tests\Support\SchemaFixture::VersionOne) { $db->exec('PRAGMA user_version = 1'); }
        $db->exec("INSERT INTO users (id,password_hash) VALUES (1,'synthetic-hash');
            INSERT INTO sites (name,url,repository,github_token) VALUES ('Existing','https://example.com','example/project','synthetic-ciphertext')");
    }

    protected static function result(MigrationProcess $process): array
    {
        $result = $process->wait();
        if ($result['code'] !== 0) { throw new RuntimeException('Migration process failed: ' . $result['stderr'] . $result['stdout']); }
        return json_decode($result['stdout'], associative: true, depth: 16, flags: JSON_THROW_ON_ERROR);
    }
}
