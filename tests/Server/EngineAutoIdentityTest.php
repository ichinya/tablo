<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseInteger;
use Tablo\ServerSchema;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\FixtureCredentials;
use Testo\Assert;
use Testo\Test;

final class EngineAutoIdentityTest
{
    #[Test]
    public function nativeZeroNegativeNullAndDefaultFollowTheAdmittedSqlMode(): void
    {
        $fixture = new EngineFixture('constraints-auto');
        $db = $fixture->database();
        $cipher = $fixture->vault->encrypt(FixtureCredentials::value(label: 'auto-identity'));
        $sql = 'INSERT INTO git_tokens (id,name,provider,encrypted_token,created_at,updated_at) VALUES (?, ?, ?, ?, ?, ?)';
        foreach ([0, -1, null] as $index => $id) {
            ServerSchema::rows($db, $sql, [$id, 'native-' . $index, 'github', $cipher, '0001-01-01T00:00:00Z', '0001-01-01T00:00:00Z']);
        }
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM git_tokens WHERE id = 0'), 1);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM git_tokens WHERE id = -1'), 1);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM git_tokens WHERE id > 0'), 1);
        ServerSchema::rows($db, 'INSERT INTO git_tokens (name,provider,encrypted_token,created_at,updated_at) VALUES (?, ?, ?, ?, ?)',
            ['native-default', 'github', $cipher, '0001-01-01T00:00:00Z', '0001-01-01T00:00:00Z']);
        Assert::true(DatabaseInteger::positive($db->lastInsertId()) > 0);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM git_tokens'), 4);
        $fixture->close();
    }
}
