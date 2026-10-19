<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseFailure;
use Tablo\ServerSchema;
use Tablo\Tests\Support\EngineFixture;
use Testo\Assert;
use Testo\Test;

final class EngineCheckAdmissionTest
{
    #[Test]
    public function nativeCheckEnforcementIsRequiredForAdmission(): void
    {
        $fixture = new EngineFixture('admission-enforcement');
        try {
            $db = $fixture->maintenance();
            ServerSchema::admit($db);
            $mysql = getenv('TABLO_TEST_DB_DRIVER') === 'mysql';
            if ($mysql) {
                $db->exec('ALTER TABLE users ALTER CHECK users_c1 NOT ENFORCED');
                Assert::same(ServerSchema::rows($db, "SELECT ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'users_c1'"), [['ENFORCED' => 'NO']]);
                self::refuses($fixture);
                $db->exec('ALTER TABLE users ALTER CHECK users_c1 ENFORCED');
                Assert::same(ServerSchema::rows($db, "SELECT ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'users_c1'"), [['ENFORCED' => 'YES']]);
            }
            ServerSchema::admit($db);
            $writer = $fixture->independent();
            Assert::same($writer->getAttribute(\PDO::ATTR_DRIVER_NAME), 'mysql');
            Assert::same($fixture->nativeFailure('INSERT INTO users (id,password_hash,created_at) VALUES (2,?,?)',
                ['synthetic-not-an-administrator', '0001-01-01T00:00:00Z']), $mysql ? 3819 : 4025);
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM users'), 0);
        } finally { $fixture->close(); }
    }

    private static function refuses(EngineFixture $fixture): void
    {
        $db = $fixture->maintenance();
        $before = ServerSchema::rows($db, 'SELECT * FROM tablo_database_state');
        $checks = ServerSchema::rows($db, "SELECT ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'users_c1'");
        $caught = null;
        try { $fixture->independent(); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
        Assert::same(ServerSchema::rows($db, 'SELECT * FROM tablo_database_state'), $before);
        Assert::same(ServerSchema::rows($db, "SELECT ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'users_c1'"), $checks);
    }
}
