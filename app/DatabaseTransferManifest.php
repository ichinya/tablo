<?php
declare(strict_types=1);

namespace Tablo;

/**
 * A fixed logical schema, never a schema supplied by an archive.
 *
 * @phpstan-type Column array{name: string, type: string, nullable: bool}
 * @phpstan-type Table array{name: string, columns: list<Column>, primary: list<string>}
 * @phpstan-type Cache array{list<string>, list<array{string, string|list<Column>, list<string>}>}
 *
 * @phpstan-import-type Receipt from DatabaseTransferArchive
 *
 * @phpstan-type Header array{format: int, engine: string, logicalVersion: int, layout: string, columns: list<array{name: string, columns: list<Column>}>, installation: string, verifier: string, sequences: array{sites: int, git_tokens: int, check_history: int}, watermark: int, tables: list<Receipt>}
 */
final class DatabaseTransferManifest
{
    /** @var Cache|null */
    private static ?array $dataTables = null;
    public const TABLES = ['users', 'git_tokens', 'installation_settings', 'worker_runtime', 'github_cooldowns',
        'notification_settings', 'read_api_token', 'sites', 'worker_progress', 'check_history', 'incidents',
        'incident_checkpoints', 'notification_checkpoints', 'notification_slots', 'login_limits'];
    public const CHUNK_BYTES = 262_144;
    public const CHUNK_ROWS = 128;
    public const MANIFEST_BYTES = 65_536;
    public const MAX_CHUNKS = 128;
    public const MAX_ROWS = 16_384; //128 chunks *128 rows.
    public const MAX_RAW_BYTES = 25_165_824; //Base64 cannot fit more raw string bytes in128 *256KiB.

    /**
 * @return list<Table> */
    public static function tables(): array
    {
        return iterator_to_array(self::iterate(), preserve_keys: false);
    }

    /** @internal Columns may come only from capture() or the fully checked manifest() receipt.
     * @param list<array{name: string, columns: list<Column>}>|null $checkedColumns
     * @return \Generator<int, Table> */
    public static function iterate(?array $checkedColumns = null): \Generator
    {
        $cache = self::cache();
        foreach ($cache[1] as $index => $record) {
            $columns = database_transfer_manifest_expand($record[1], $cache[0], $checkedColumns, $index);
            yield ['name' => $record[0], 'columns' => $columns, 'primary' => $record[2]];
            unset($columns);
        }
    }

    /** @return Cache */
    private static function cache(): array
    {
        if (self::$dataTables !== null) { return self::$dataTables; }
        $layout = ServerSchemaDescriptor::forEngine('mysql');
        $tables = []; $pool = []; $primaries = []; $names = array_column($layout['tables'], 'name');
        foreach (self::TABLES as $name) {
            $index = array_search($name, $names, strict: true);
            $table = $index === false ? throw new DatabaseFailure('schema') : ($layout['tables'][$index] ?? throw new DatabaseFailure('schema'));
            $columns = [];
            foreach ($table['columns'] as $column) {
                if (array_key_exists('generated', $column)) { continue; }
                $columns[] = ['name' => $column['name'], 'type' => $column['type'], 'nullable' => $column['nullable']];
            }
            $primary = $table['primary']; $key = serialize($primary);
            // Primary metadata is a list of strings, so serialization is lossless at every precision.
            $primary = $primaries[$key] ?? $primary; $primaries[$key] = $primary;
            $tables[] = [$table['name'], database_transfer_manifest_pack($columns, $pool), $primary];
        }
        self::$dataTables = [$pool, $tables];
        return self::$dataTables;
    }

    /**
 * @param Table $table
 * @return list<Column> */
    public static function columns(array $table): array
    {
        return $table['columns'];
    }

    public static function layout(): string
    {
        $hash = hash_init('sha256'); hash_update($hash, data: '['); $separator = '';
        foreach (self::iterate() as $table) { hash_update($hash, $separator . JsonEncoding::encode($table)); $separator = ','; }
        hash_update($hash, data: ']');
        return hash_final($hash);
    }

    /**
 * @return list<array{name: string, columns: list<Column>}> */
    public static function contract(): array
    {
        return database_transfer_manifest_contract(self::iterate(), self::cache());
    }

    /**
 * @param list<Receipt> $tables
 * @return Header */
    public static function capture(\PDO $db, #[\SensitiveParameter] TokenVault $vault, array $tables): array
    {
        $rows = ServerSchema::rows($db, 'SELECT installation_id FROM notification_settings WHERE id = 1');
        $installation = SqlColumn::string($rows[0] ?? [], 'installation_id');
        return ['format' => 1, 'engine' => DatabaseSession::get($db)?->configuration->engine ?? 'sqlite',
            'logicalVersion' => 10, 'layout' => self::layout(), 'columns' => self::contract(),
            'installation' => $installation, 'verifier' => $vault->serverVerifier($installation),
            'sequences' => DatabaseTransferSequence::floors($db), 'watermark' => DatabaseTransferSequence::watermark($db), 'tables' => $tables];
    }

    public static function path(#[\SensitiveParameter] string $directory, string $name): string
    {
        if ($directory === '' || str_contains($directory, "\0") || str_contains($directory, '://') || is_link($directory)) {
            throw new DatabaseFailure('configuration');
        }
        return $directory . '/' . $name;
    }
}

/** @internal Lossless native fallback preserves unsupported projected values.
 * @template T of list<array{name: mixed, type: mixed, nullable: mixed}>
 * @param T $columns
 * @param list<string> $pool
 * @return string|T */
function database_transfer_manifest_pack(array $columns, array &$pool): string|array
{
    $bytes = '';
    foreach ($columns as $column) {
        if (!is_string($column['name']) || !is_string($column['type']) || !is_bool($column['nullable'])) { return $columns; }
        foreach ([$column['name'], $column['type']] as $part) {
            $index = array_search($part, $pool, strict: true);
            if ($index === false) { $index = count($pool); if ($index > 255) { return $columns; } $pool[] = $part; }
            $bytes .= chr($index);
        }
        $bytes .= $column['nullable'] ? "\1" : "\0";
    }
    return $bytes;
}

/** @internal
 * @template T of array
 * @param string|T $record
 * @param list<string> $pool
 * @param list<array{name: string, columns: list<\Tablo\DatabaseTransferManifest::Column>}>|null $checkedColumns
 * @return T|list<\Tablo\DatabaseTransferManifest::Column> */
function database_transfer_manifest_expand(string|array $record, array $pool, ?array $checkedColumns = null, int $index = 0): array
{
    if ($checkedColumns !== null) { return $checkedColumns[$index]['columns'] ?? throw new DatabaseFailure('schema'); }
    if (is_array($record)) { return $record; }
    $columns = []; $length = strlen($record);
    for ($offset = 0; $offset < $length; $offset += 3) {
        $columns[] = ['name' => $pool[ord($record[$offset])] ?? throw new DatabaseFailure('schema'),
            'type' => $pool[ord($record[$offset + 1])] ?? throw new DatabaseFailure('schema'),
            'nullable' => $record[$offset + 2] === "\1"];
    }
    return $columns;
}

/** @internal
 * @param \Generator<int, \Tablo\DatabaseTransferManifest::Table> $tables
 * @param \Tablo\DatabaseTransferManifest::Cache $cache
 * @return list<array{name: string, columns: list<\Tablo\DatabaseTransferManifest::Column>}> */
function database_transfer_manifest_contract(\Generator $tables, array $cache): array
{
    $contract = []; $pool = [];
    foreach ($tables as $tableIndex => $table) {
        // Native fallback values, including signed zero, must keep their exact representation.
        if (is_string($cache[1][$tableIndex][1] ?? throw new DatabaseFailure('schema'))) {
            foreach ($table['columns'] as $index => $column) {
                $key = array_search($column, $pool, strict: true);
                if ($key === false) { $key = count($pool); $pool[] = $column; }
                $table['columns'][$index] = $pool[$key] ?? throw new DatabaseFailure('schema');
            }
        }
        $contract[] = ['name' => $table['name'], 'columns' => $table['columns']];
        unset($table);
    }
    return $contract;
}
