<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 * The two checked-in descriptors are the complete finite server formats, never arbitrary input.
 * @phpstan-type Column array{name: string, type: string, nullable: bool, default: ?string, auto?: bool, generated?: string}
 * @phpstan-type AdmissionTable array{name: string, columns: list<Column>, primary: list<string>, checks: list<array{name: string, expression: string}>, indexes: list<array{name: string, columns: list<string>, unique: bool}>, foreign: list<array{column: string, table: string, target: string, onDelete: string, onUpdate: string}>}
 * @phpstan-type Table array{name: string, columns: list<Column>, primary: list<string>, checks: list<array{name: string, expression: string}>, indexes: list<array{name: string, columns: list<string>, unique: bool}>, foreign: list<array{column: string, table: string, target: string, onDelete: string, onUpdate: string}>, create: string, checksum: string}
 * @phpstan-type ExpectedTable Table|AdmissionTable
 * @phpstan-type Layout array{format: int, engine: string, logicalVersion: int, syntheticPredecessor: int, collation: string, tables: list<Table>}
 */
final class ServerSchema
{
    /** @return Layout */
    public static function layout(PDO $db): array { return ServerSchemaDescriptor::layout($db); }
    public static function checksum(PDO $db, int $version = 11): string { return ServerSchemaDescriptor::checksum($db, $version); }
    public static function admit(PDO $db): void
    {
        if (DatabaseSession::get($db)?->configuration->role !== 'reader') { ServerTableAdmission::layout($db); }
        ServerSchemaState::ready($db);
    }
    public static function validateLayout(PDO $db, int $version = 11): void { ServerTableAdmission::layout($db, $version); }
    /** @param Table $table */
    public static function validateTable(PDO $db, array $table): void { ServerTableAdmission::verify($db, $table); }
    /** @return list<string> */
    public static function objects(PDO $db): array
    {
        return ServerMetadataAdmission::objects($db);
    }
    /**
     * @param list<int|string|null> $values
     * @return list<array>
     */
    public static function rows(PDO $db, string $sql, #[\SensitiveParameter] array $values = []): array
    {
        $statement = SqlStatement::checked($db->prepare($sql));
        try {
            foreach ($values as $index => $value) { $statement->bindValue($index + 1, $value, SqlBindingType::forValue($value)); }
            $statement->execute();
            return SqlRows::all($statement->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): array => $row);
        } finally { $statement->closeCursor(); }
    }
}
