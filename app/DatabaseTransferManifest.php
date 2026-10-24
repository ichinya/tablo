<?php
declare(strict_types=1);

namespace Tablo;

/**
 * A fixed logical schema, never a schema supplied by an archive.
 *
 * @phpstan-type Column array{name: string, type: string, nullable: bool}
 * @phpstan-type Table array{name: string, columns: list<Column>, primary: list<string>}
 * @phpstan-import-type Layout from ServerSchema
 *
 * @phpstan-import-type Receipt from DatabaseTransferArchive
 *
 * @phpstan-type Header array{format: int, engine: string, logicalVersion: int, layout: string, columns: list<array{name: string, columns: list<Column>}>, installation: string, verifier: string, sequences: array{sites: int, git_tokens: int, check_history: int}, watermark: int, tables: list<Receipt>}
 */
final class DatabaseTransferManifest
{
    /** @var list<Table>|null */
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
        if (self::$dataTables !== null) { return self::$dataTables; }
        database_transfer_manifest_phase(40);
        $bytes = RequiredFile::bytes(file_get_contents(dirname(__DIR__) . '/database/mysql84-layout.json'));
        /** @var Layout $layout Checked-in descriptor, not archive-controlled SQL. */
        $layout = json_decode($bytes, associative: true, flags: JSON_THROW_ON_ERROR);
        database_transfer_manifest_phase(41);
        $tables = [];
        foreach (self::TABLES as $name) {
            $matches = array_values(array_filter($layout['tables'], static fn (array $table): bool => $table['name'] === $name));
            $table = $matches[0] ?? throw new DatabaseFailure('schema');
            $columns = [];
            foreach ($table['columns'] as $column) {
                if (array_key_exists('generated', $column)) { continue; }
                $columns[] = ['name' => $column['name'], 'type' => $column['type'], 'nullable' => $column['nullable']];
            }
            $tables[] = ['name' => $table['name'], 'columns' => $columns, 'primary' => $table['primary']];
        }
        self::$dataTables = $tables;
        database_transfer_manifest_phase(42);
        return $tables;
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
        return hash('sha256', JsonEncoding::encode(array_map(static fn (array $table): array =>
            ['name' => $table['name'], 'columns' => self::columns($table), 'primary' => $table['primary']], self::tables())));
    }

    /**
 * @return list<array{name: string, columns: list<Column>}> */
    public static function contract(): array
    {
        return array_map(static fn (array $table): array => ['name' => $table['name'], 'columns' => self::columns($table)], self::tables());
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

function database_transfer_manifest_phase(int $phase): void
{
    if (function_exists('Tablo\\database_transfer_phase_mark')) { database_transfer_phase_mark($phase); }
}
