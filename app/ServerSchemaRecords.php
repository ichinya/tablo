<?php
declare(strict_types=1);

namespace Tablo;

/** @internal Bounded I/O for the single checked-in field-record source. */
final class ServerSchemaRecords
{
    public static function read(#[\SensitiveParameter] string $path): array
    {
        $stream = null; $layout = null; $primary = null;
        try {
            $stream = NativeWarning::ignore(static fn () => fopen($path, mode: 'rb'));
            if ($stream === false) { throw new DatabaseFailure('schema'); }
            $layout = self::parse($stream);
        } catch (\Throwable) { $primary = new DatabaseFailure('schema'); }
        finally { $closed = self::close($stream); }
        if ($primary !== null) { throw $primary; }
        if (!$closed || $layout === null) { throw new DatabaseFailure('schema'); }
        return $layout;
    }

    /** @param resource|false|null $stream */
    private static function close($stream): bool
    {
        if (!is_resource($stream)) { return true; }
        try { return NativeWarning::ignore(static fn () => fclose($stream)); }
        catch (\Throwable) { return false; }
    }

    /** @param resource $stream */
    private static function parse($stream): array
    {
        $layout = server_schema_records_header(self::record($stream));
        $layout['tables'] = [];
        for ($index = 0; $index < 18; $index++) {
            $table = [];
            foreach (['name', 'columns', 'primary', 'checks', 'indexes', 'foreign', 'create', 'checksum'] as $field) {
                $table[$field] = server_schema_records_field(self::record($stream), $field);
            }
            $layout['tables'][] = $table; unset($table);
        }
        if (NativeWarning::ignore(static fn () => fgetc($stream)) !== false || !feof($stream)) { throw new DatabaseFailure('schema'); }
        return $layout;
    }

    /** Each field retains the original root/tables/table depth; never decode an unwrapped value.
     * @param resource $stream */
    private static function record($stream): array
    {
        $line = server_schema_records_line($stream);
        return JsonRecord::checked(json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR));
    }
}

/** @internal Assemble one bounded frame using small fixed fgets requests.
 * @param resource $stream */
function server_schema_records_line($stream): string
{
    $line = '';
    while (true) {
        $chunk = NativeWarning::ignore(static fn () => fgets($stream, length: 4096));
        if ($chunk === false || $chunk === '' || strlen($chunk) > 131_072 - strlen($line)) { throw new DatabaseFailure('schema'); }
        $complete = str_ends_with($chunk, "\n");
        $line .= $chunk;
        unset($chunk);
        if ($complete) { return $line; }
    }
}

/** @internal Validate the decoded header before any graph assembly. */
function server_schema_records_header(array $header): array
{
    if (array_keys($header) !== ['records', 'layout'] || ($header['records'] ?? null) !== 1) { throw new DatabaseFailure('schema'); }
    $layout = JsonRecord::checked($header['layout'] ?? null);
    if (array_keys($layout) !== ['format', 'engine', 'logicalVersion', 'syntheticPredecessor', 'collation']) { throw new DatabaseFailure('schema'); }
    foreach (['format', 'logicalVersion', 'syntheticPredecessor'] as $field) {
        if (!is_int($layout[$field] ?? null)) { throw new DatabaseFailure('schema'); }
    }
    foreach (['engine', 'collation'] as $field) {
        if (!is_string($layout[$field] ?? null)) { throw new DatabaseFailure('schema'); }
    }
    return $layout;
}

/** @internal Preserve the complete native value, including null, after checking decoded framing. */
function server_schema_records_field(array $record, string $field): mixed
{
    if (array_keys($record) !== ['tables']) { throw new DatabaseFailure('schema'); }
    $tables = JsonRecord::checked($record['tables'] ?? null);
    if (array_keys($tables) !== [0]) { throw new DatabaseFailure('schema'); }
    $table = JsonRecord::checked($tables[0] ?? null);
    if (array_keys($table) !== [$field] || !array_key_exists($field, $table)) { throw new DatabaseFailure('schema'); }
    return $table[$field];
}
