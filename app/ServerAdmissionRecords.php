<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/** Invocation-owned immutable records from the complete validated finite source.
 * @phpstan-import-type AdmissionTable from ServerSchema */
final readonly class ServerAdmissionRecords
{
    /** @param list<string> $names
     * @param list<string> $records */
    private function __construct(private string $engine, private array $names, private array $records) {}

    public static function forConnection(PDO $db): self
    {
        $engine = DatabaseSession::get($db)?->configuration->engine;
        $file = match ($engine) { 'mysql' => 'mysql84', 'mariadb' => 'mariadb114', default => throw new DatabaseFailure('configuration') };
        return self::fromSource(dirname(__DIR__) . '/database/' . $file . '-layout.jsonl', $engine);
    }

    private static function fromSource(#[\SensitiveParameter] string $path, string $engine): self
    {
        if (!class_exists(ServerSchemaDescriptor::class)) { throw new DatabaseFailure('schema'); }
        $source = server_admission_records_read($path, $engine);
        return new self($engine, $source['names'], $source['records']);
    }

    public function assertEngine(?string $engine): void
    {
        if ($engine !== $this->engine) { throw new DatabaseFailure('schema'); }
    }

    /** @return list<string> */
    public function names(int $version = 11): array
    {
        return array_values(array_filter($this->names, static fn (string $name): bool => ($version >= 10 || $name !== 'read_api_token') && ($version >= 11 || $name !== 'self_update')));
    }

    /** @return \Generator<int, AdmissionTable> */
    public function tables(int $version = 11): \Generator
    {
        return server_admission_records_tables($this->records, $version);
    }
}

/** @internal Complete source and positive close precede publication of any records.
 * @return array{names: list<string>, records: list<string>} */
function server_admission_records_read(#[\SensitiveParameter] string $path, string $engine): array
{
    $stream = null; $source = null; $primary = null;
    try {
        $stream = NativeWarning::ignore(static fn () => fopen($path, mode: 'rb'));
        if ($stream === false) { throw new DatabaseFailure('schema'); }
        $source = server_admission_records_parse($stream, $engine);
    } catch (\Throwable) { $primary = new DatabaseFailure('schema'); }
    finally { $closed = server_schema_descriptor_close($stream); }
    if ($primary !== null) { throw $primary; }
    if (!$closed || $source === null || !$source['valid']) { throw new DatabaseFailure('schema'); }
    return ['names' => $source['names'], 'records' => $source['records']];
}

/** @internal Read once and retain raw wrapped JSON, never a re-encoding or a filtered source.
 * @param resource $stream
 * @return array{names: list<string>, records: list<string>, valid: bool} */
function server_admission_records_parse($stream, string $engine): array
{
    $header = server_schema_descriptor_header(server_schema_descriptor_record($stream));
    $valid = ($header['format'] ?? null) === 1 && ($header['engine'] ?? null) === $engine; unset($header);
    $names = []; $records = [];
    for ($index = 0; $index < 19; $index++) {
        $record = server_schema_descriptor_record_bytes($stream);
        $table = server_admission_records_table($record);
        $valid = server_schema_descriptor_create_valid($table) && $valid;
        /** @var mixed $name Native framing proves the key, not its scalar type. */
        $name = $table['name'] ?? null;
        if (is_string($name)) { $names[] = $name; }
        $valid = is_string($name) && $valid;
        $records[] = $record; unset($record, $table);
    }
    if (NativeWarning::ignore(static fn () => fgetc($stream)) !== false || !feof($stream)) { throw new DatabaseFailure('schema'); }
    return ['names' => $names, 'records' => $records, 'valid' => $valid];
}

/** @internal Preserve the original wrapped decoder depth, flags, field order and native values. */
function server_admission_records_table(#[\SensitiveParameter] string $record): array
{
    return server_schema_descriptor_table(JsonRecord::checked(json_decode($record, associative: true, flags: JSON_THROW_ON_ERROR)));
}

/** @internal Only immutable records published after full-source validation reach this iterator.
 * @param list<string> $records
 * @return \Generator<int, \Tablo\ServerSchema::AdmissionTable> */
function server_admission_records_tables(#[\SensitiveParameter] array $records, int $version): \Generator
{
    foreach ($records as $record) {
        /** @var \Tablo\ServerSchema::Table $table Immutable records passed the complete source checks before publication. */
        $table = server_admission_records_table($record);
        if (($version < 10 && $table['name'] === 'read_api_token') || ($version < 11 && $table['name'] === 'self_update')) { unset($table); continue; }
        $table = ServerSchemaProjection::table($table, $version);
        $projected = ['name' => $table['name'], 'columns' => $table['columns'], 'primary' => $table['primary'],
            'checks' => $table['checks'], 'indexes' => $table['indexes'], 'foreign' => $table['foreign']];
        unset($table);
        yield $projected;
        unset($projected);
    }
}
