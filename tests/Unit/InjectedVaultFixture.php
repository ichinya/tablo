<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;

final class InjectedVaultFixture
{
    public static function populate(PDO $db, string $cipher): array
    {
                $db->exec(file_get_contents(dirname(__DIR__) . '/fixtures/pre-json-schema.sql'));
                $db->prepare('INSERT INTO sites(name,url,repository,github_token) VALUES(?,?,?,?)')
                    ->execute(['Synthetic', 'https://example.com', 'fixture/control', $cipher]);
                return $db->query('SELECT sql FROM sqlite_schema ORDER BY name')->fetchAll();
    }
}
