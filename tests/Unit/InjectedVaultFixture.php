<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;

/** @phpstan-type SchemaRows list<array{sql: ?string, ...}> */
final class InjectedVaultFixture
{
    /** @return SchemaRows */
    public static function populate(PDO $db, string $cipher): array
    {
                $db->exec(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__) . '/fixtures/pre-json-schema.sql')));
                \Tablo\SqlStatement::checked($db->prepare('INSERT INTO sites(name,url,repository,github_token) VALUES(?,?,?,?)'))
                    ->execute(['Synthetic', 'https://example.com', 'fixture/control', $cipher]);
                return \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT sql FROM sqlite_schema ORDER BY name'))->fetchAll(), self::schemaRow(...));
    }

    /** @return array{sql: ?string, ...} */
    private static function schemaRow(array $row): array
    {
        $row['sql'] = \Tablo\SqlColumn::nullableString($row, 'sql');
        return $row;
    }
}
