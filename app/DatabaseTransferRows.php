<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 *
 * @phpstan-import-type Table from DatabaseTransferManifest
 *
 * @phpstan-import-type Column from DatabaseTransferManifest
 *
 * @phpstan-type Values list<int|string|null>
 */
final class DatabaseTransferRows
{
    /**
 * @param \Tablo\DatabaseTransferManifest::Table $table
 * @return \Generator<int, \Tablo\DatabaseTransferRows::Values> */
    public static function read(PDO $db, array $table): \Generator
    {
        return database_transfer_rows_read($db, $table);
    }

    /** @param \Tablo\DatabaseTransferManifest::Table $table
     * @param \Closure(\Tablo\DatabaseTransferRows::Values): void $consume */
    public static function visit(PDO $db, array $table, \Closure $consume): void
    {
        // Exhaust the same reader without retaining a previous yielded row at the next fetch.
        foreach (database_transfer_rows_read($db, $table, $consume) as $unused) { unset($unused); }
    }

    /**
 * @param \Tablo\DatabaseTransferManifest::Column $column */
    public static function value(#[\SensitiveParameter] mixed $value, array $column): int|string|null
    {
        return database_transfer_value_value($value, $column);
    }

    /**
 * @param \Tablo\DatabaseTransferRows::Values $values
 * @return list<array{string, string}|array{string}> */
    public static function encode(#[\SensitiveParameter] array $values): array
    {
        return database_transfer_codec_encode($values);
    }

    /**
 * @param \Tablo\DatabaseTransferManifest::Table $table
 * @return \Tablo\DatabaseTransferRows::Values */
    public static function decode(#[\SensitiveParameter] mixed $input, array $table): array
    {
        return database_transfer_codec_decode($input, $table);
    }

    /** @param \Tablo\DatabaseTransferManifest::Table $table
     * @return \Tablo\DatabaseTransferRows::Values */
    public static function line(#[\SensitiveParameter] string $line, array $table): array
    {
        return database_transfer_codec_line($line, $table);
    }

    /** @internal
     * @param \Tablo\DatabaseTransferManifest::Table $table
     * @return \Tablo\DatabaseTransferRows::Values */
    public static function consumeLine(#[\SensitiveParameter] string &$line, array $table): array
    {
        return database_transfer_codec_consume_line($line, $table);
    }

    /**
 * @param \Tablo\DatabaseTransferManifest::Table $table
 * @param \Tablo\DatabaseTransferRows::Values $values */
    public static function insert(PDO $db, array $table, #[\SensitiveParameter] array $values): void
    {
        database_transfer_rows_insert($db, $table, $values);
    }
}

// Internal operations for this fixed transfer module.

/**
 * @param \Tablo\DatabaseTransferManifest::Table $table
 * @param (\Closure(\Tablo\DatabaseTransferRows::Values): void)|null $consume
 * @return \Generator<int, \Tablo\DatabaseTransferRows::Values> */
function database_transfer_rows_read(PDO $db, array $table, ?\Closure $consume = null): \Generator
{
    $columns = DatabaseTransferManifest::columns($table);
    $projection = database_transfer_rows_projection($columns);
    $order = implode(',', array_map(static fn (string $name): string => '`' . $name . '`', $table['primary']));
    $server = DatabaseSession::server($db);
    DatabaseTransferPreflight::rowEnvelope($db, $table);
    /** @var list<int|string> $cursor */
    $cursor = [];
    do {
        $where = $cursor === [] ? '' : ' WHERE (' . $order . ') > (' . implode(',', array_fill(0, count($cursor), value: '?')) . ')';
        $statement = SqlStatement::checked($db->prepare('SELECT ' . $projection . ' FROM `' . $table['name'] . '`' . $where . ' ORDER BY ' . $order . ' LIMIT 1'));
        try {
            foreach ($cursor as $index => $value) {
                // Every string primary key in the fixed server descriptor is VARBINARY.
                $type = $server && is_string($value) ? PDO::PARAM_LOB : SqlBindingType::forValue($value);
                $statement->bindValue($index + 1, $value, $type);
            }
            $statement->execute();
            /** @var array|false $fetched Native associative row or the distinct end marker. */
            $fetched = $statement->fetch(PDO::FETCH_ASSOC);
            if ($fetched === false) { return; }
            $row = SqlRows::required($fetched, static fn (array $value): array => $value);
            $values = [];
            foreach ($columns as $column) { $values[] = database_transfer_value_value($row[$column['name']] ?? null, $column); }
            $cursor = [];
            foreach ($table['primary'] as $name) {
                /** @var mixed $value A native primary-key scalar must be checked before keyset binding. */
                $value = $row[$name] ?? null;
                if (!is_int($value) && !is_string($value)) { throw new DatabaseFailure('schema'); }
                $cursor[] = $value;
            }
        } finally { $statement->closeCursor(); }
        unset($statement, $fetched, $row);
        if ($consume === null) { yield $values; }
        if ($consume !== null) { $consume($values); }
        unset($values);
    } while (true);
}

/** @param list<\Tablo\DatabaseTransferManifest::Column> $columns */
function database_transfer_rows_projection(array $columns): string
{
    return implode(',', array_map(static fn (array $column): string => '`' . $column['name'] . '`', $columns));
}


/**
 * @param \Tablo\DatabaseTransferManifest::Table $table
 * @param \Tablo\DatabaseTransferRows::Values $values */
function database_transfer_rows_insert(PDO $db, array $table, #[\SensitiveParameter] array $values): void
{
    $columns = DatabaseTransferManifest::columns($table);
    $names = implode(',', array_map(static fn (array $column): string => '`' . $column['name'] . '`', $columns));
    $sql = 'INSERT INTO `' . $table['name'] . '` (' . $names . ') VALUES (' . implode(',', array_fill(0, count($columns), value: '?')) . ')';
    $statement = SqlStatement::checked($db->prepare($sql));
    $server = DatabaseSession::server($db);
    try {
        foreach ($columns as $index => $column) {
            $value = $values[$index] ?? null;
            $binary = $column['type'] === 'longblob' || str_starts_with($column['type'], 'varbinary(');
            $type = $server && $binary && is_string($value) ? PDO::PARAM_LOB : SqlBindingType::forValue($value);
            $statement->bindValue($index + 1, $value, $type);
        }
        $statement->execute();
    } finally { $statement->closeCursor(); }
}


/**
 * @param \Tablo\DatabaseTransferManifest::Column $column */
function database_transfer_value_value(#[\SensitiveParameter] mixed $value, array $column): int|string|null
{
    if ($value === null) {
        if (!$column['nullable']) { throw new DatabaseFailure('schema'); }
        return null;
    }
    if ($column['type'] === 'bigint') { return DatabaseInteger::checked($value); }
    if (!is_string($value)) { throw new DatabaseFailure('schema'); }
    if ($column['type'] === 'longtext') {
        if (str_contains($value, "\0") || preg_match('//u', $value) !== 1) { throw new DatabaseFailure('schema'); }
    }
    if ($column['type'] === 'varchar(80)' && preg_match('/\A[^\x00]{1,80}\z/uD', $value) !== 1) {
        throw new DatabaseFailure('schema');
    }
    if ($column['type'] === 'varbinary(20)') {
        try { $canonical = HistoryTime::canonical($value); }
        catch (\InvalidArgumentException) { throw new DatabaseFailure('schema'); }
        if ($canonical !== $value) { throw new DatabaseFailure('schema'); }
    }
    $match = [];
    if (preg_match('/\Avarbinary\(([0-9]+)\)\z/D', $column['type'], $match) === 1 && strlen($value) > (int) ($match[1] ?? throw new DatabaseFailure('schema'))) {
        throw new DatabaseFailure('schema');
    }
    return $value;
}


/**
 * @param \Tablo\DatabaseTransferRows::Values $values
 * @return list<array{string, string}|array{string}> */
function database_transfer_codec_encode(#[\SensitiveParameter] array $values): array
{
    return array_map(static fn (int|string|null $value): array => match (true) {
        $value === null => ['null'], is_int($value) => ['integer', (string) $value], default => ['bytes', base64_encode($value)],
    }, $values);
}


/**
 * @param \Tablo\DatabaseTransferManifest::Table $table
 * @return \Tablo\DatabaseTransferRows::Values */
function database_transfer_codec_decode(#[\SensitiveParameter] mixed $input, array $table): array
{
    $columns = DatabaseTransferManifest::columns($table);
    $input = JsonRecord::checked($input);
    if (count($input) !== count($columns)) { throw new DatabaseFailure('schema'); }
    $values = []; $canonical = array_is_list($input);
    foreach ($columns as $index => $column) {
        $cell = JsonRecord::checked($input[$index] ?? null);
        $value = database_transfer_value_value(database_transfer_codec_cell($cell), $column);
        $values[] = $value;
        $canonical = database_transfer_codec_canonical_cell($cell, $value) && $canonical;
    }
    // Scalar validators prove canonical payloads; defer list/tag mismatches as before.
    $matches = database_transfer_codec_columns_list($columns) ? $canonical : database_transfer_codec_encode($values) === $input;
    if (!$matches) { throw new DatabaseFailure('schema'); }
    return $values;
}


/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @return \Tablo\DatabaseTransferRows::Values */
function database_transfer_codec_line(#[\SensitiveParameter] string $line, array $table): array
{
    $values = database_transfer_codec_decode(JsonRecord::decode($line), $table);
    // PHP may decode a numeric-key object as a list; wire equality closes that ambiguity.
    if (JsonEncoding::encode(database_transfer_codec_encode($values)) !== $line) { throw new DatabaseFailure('schema'); }
    return $values;
}

/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @return \Tablo\DatabaseTransferRows::Values */
function database_transfer_codec_consume_line(#[\SensitiveParameter] string &$line, array $table): array
{
    $values = database_transfer_codec_fast_line($line, $table);
    if ($values !== null) { $line = ''; return $values; }
    $input = JsonRecord::decode($line);
    // Speculative wire encoding must not precede the typed decoder's failure.
    try { $canonicalWire = database_transfer_codec_wire($input, $line); }
    catch (\JsonException) { $canonicalWire = false; }
    $line = '';
    $values = database_transfer_codec_decode($input, $table);
    // Successful typed decoding proves encode(values) === input, including list shapes.
    if (!$canonicalWire) { throw new DatabaseFailure('schema'); }
    return $values;
}

/** Exact canonical grammar avoids retaining the entire JSON/base64 tree beside decoded values.
 * Unrecognized or invalid input falls back with the caller's original bytes and failure precedence.
 * @param \Tablo\DatabaseTransferManifest::Table $table
 * @return \Tablo\DatabaseTransferRows::Values|null */
function database_transfer_codec_fast_line(#[\SensitiveParameter] string $line, array $table): ?array
{
    if (!database_transfer_codec_fast_columns($table)) { return null; }
    $columns = DatabaseTransferManifest::columns($table);
    $offset = 0; $values = [];
    if (!database_transfer_codec_wire_fragment('[', $line, $offset)) { return null; }
    try {
        foreach ($columns as $index => $column) {
            if (!database_transfer_codec_wire_fragment($index === 0 ? '' : ',', $line, $offset)) { return null; }
            $value = database_transfer_codec_fast_cell($line, $offset, $column);
            if ($value === false) { return null; }
            $values[] = $value;
        }
    } catch (DatabaseFailure) { return null; }
    if (!database_transfer_codec_wire_fragment(']', $line, $offset) || $offset !== strlen($line)) { return null; }
    return $values;
}

/** Public array callers retain the original fallback for unsupported descriptors. */
function database_transfer_codec_fast_columns(array $table): bool
{
    /** @var mixed $columns Eligibility must not introduce warnings before the original JSON decoder. */
    $columns = $table['columns'] ?? null;
    if (!is_array($columns) || !array_is_list($columns)) { return false; }
    /** @var mixed $column Runtime column shapes must be proved before typed scalar validation. */
    foreach ($columns as $column) {
        if (!is_array($column) || !is_string($column['name'] ?? null) || !is_string($column['type'] ?? null)
            || !is_bool($column['nullable'] ?? null)) { return false; }
    }
    return true;
}

/** @param \Tablo\DatabaseTransferManifest::Column $column */
function database_transfer_codec_fast_cell(#[\SensitiveParameter] string $line, int &$offset, array $column): int|string|null|false
{
    if (database_transfer_codec_wire_fragment('["null"]', $line, $offset)) { return database_transfer_value_value(null, $column); }
    $tag = match (true) {
        database_transfer_codec_wire_fragment('["integer","', $line, $offset) => 'integer',
        database_transfer_codec_wire_fragment('["bytes","', $line, $offset) => 'bytes', default => null,
    };
    if ($tag === null) { return false; }
    $end = strpos($line, needle: '"]', offset: $offset);
    if ($end === false) { return false; }
    // Eligibility bound only: the original decoder still decides every longer input.
    if ($tag === 'integer' && $end - $offset > 20) { return false; }
    $value = $tag === 'integer' ? DatabaseInteger::checked(substr($line, $offset, length: $end - $offset))
        : database_transfer_codec_fast_bytes($line, $offset, $end);
    if ($value === false) { return false; }
    $offset = $end + 2;
    $value = database_transfer_value_value($value, $column);
    $runtimeTag = match (true) { $value === null => 'null', is_int($value) => 'integer', default => 'bytes' };
    return $tag === $runtimeTag ? $value : false;
}

/** Decode at most4096 wire bytes plus a three-byte base64 carry at once. */
function database_transfer_codec_fast_bytes(#[\SensitiveParameter] string $line, int $start, int $end): string|false
{
    $result = ''; $carry = '';
    while ($start < $end) {
        $size = min(4096, $end - $start);
        if ($start + $size < $end && ($line[$start + $size - 1] ?? '') === '\\') { $size--; }
        $wire = substr($line, $start, $size); $start += $size;
        $piece = str_replace(search: '\\/', replace: '/', subject: $wire);
        if (strspn($piece, characters: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=') !== strlen($piece)
            || str_contains(str_replace(search: '\\/', replace: '', subject: $wire), needle: '/')) { return false; }
        $piece = $carry . $piece;
        $take = $start === $end ? strlen($piece) : strlen($piece) - strlen($piece) % 4;
        $carry = substr($piece, $take); $piece = substr($piece, offset: 0, length: $take);
        if ($start < $end && str_contains($piece, needle: '=')) { return false; }
        $decoded = base64_decode($piece, strict: true);
        if ($decoded === false || base64_encode($decoded) !== $piece) { return false; }
        $result .= $decoded;
    }
    return $carry === '' ? $result : false;
}


/**
 * @param array $cell */
function database_transfer_codec_cell(#[\SensitiveParameter] array $cell): int|string|null
{
    if ($cell === ['null']) { return null; }
    $bytes = SqlColumn::string($cell, '1');
    if (count($cell) !== 2) { throw new DatabaseFailure('schema'); }
    if (($cell[0] ?? null) === 'integer') { return DatabaseInteger::checked($bytes); }
    $decoded = database_transfer_codec_base64($bytes);
    if (($cell[0] ?? null) !== 'bytes' || $decoded === false) { throw new DatabaseFailure('schema'); }
    return $decoded;
}

/** @param array $cell */
function database_transfer_codec_canonical_cell(#[\SensitiveParameter] array $cell, #[\SensitiveParameter] int|string|null $value): bool
{
    $tag = match (true) { $value === null => 'null', is_int($value) => 'integer', default => 'bytes' };
    return array_is_list($cell) && ($cell[0] ?? null) === $tag;
}

/** @param array $columns Public array callers may supply non-list descriptors. */
function database_transfer_codec_columns_list(array $columns): bool
{
    return array_is_list($columns);
}

function database_transfer_codec_base64(#[\SensitiveParameter] string $encoded): string|false
{
    $result = ''; $offset = 0; $length = strlen($encoded);
    while ($offset < $length) {
        $piece = substr($encoded, $offset, length: 4096); $offset += strlen($piece);
        if ($offset < $length && str_contains($piece, needle: '=')) { return false; }
        $decoded = base64_decode($piece, strict: true);
        if ($decoded === false || base64_encode($decoded) !== $piece) { return false; }
        $result .= $decoded;
    }
    return $result;
}

/** @param array $input */
function database_transfer_codec_wire(#[\SensitiveParameter] array $input, #[\SensitiveParameter] string $line): bool
{
    $offset = 0;
    if (!array_is_list($input) || !database_transfer_codec_wire_fragment('[', $line, $offset)) { return false; }
    /** @var mixed $cell Decoded JSON items require the runtime cell eligibility checks below. */
    foreach ($input as $index => $cell) {
        if (!database_transfer_codec_wire_fragment($index === 0 ? '' : ',', $line, $offset)
            || !database_transfer_codec_wire_cell($cell, $line, $offset)) { return false; }
    }
    return database_transfer_codec_wire_fragment(']', $line, $offset) && $offset === strlen($line);
}

function database_transfer_codec_wire_cell(#[\SensitiveParameter] mixed $cell, #[\SensitiveParameter] string $line, int &$offset): bool
{
    if (!is_array($cell) || !array_is_list($cell)) { return false; }
    if ($cell === ['null']) { return database_transfer_codec_wire_fragment('["null"]', $line, $offset); }
    if (count($cell) !== 2) { return false; }
    /** @var mixed $tag Decoded JSON tags are validated before string encoding. */
    $tag = $cell[0] ?? null;
    /** @var mixed $value Decoded JSON payloads are validated before string encoding. */
    $value = $cell[1] ?? null;
    if (!is_string($tag) || !in_array($tag, ['integer', 'bytes'], strict: true) || !is_string($value)) { return false; }
    return database_transfer_codec_wire_fragment('[' . JsonEncoding::encode($tag) . ',', $line, $offset)
        && database_transfer_codec_wire_string($value, $line, $offset)
        && database_transfer_codec_wire_fragment(']', $line, $offset);
}

function database_transfer_codec_wire_string(#[\SensitiveParameter] string $value, #[\SensitiveParameter] string $line, int &$offset): bool
{
    if (!database_transfer_codec_wire_fragment('"', $line, $offset)) { return false; }
    if (preg_match('~[\x00-\x1f\x7f-\xff"\x5c/]~', $value) === 0
        && database_transfer_codec_wire_fragment($value, $line, $offset)) {
        return database_transfer_codec_wire_fragment('"', $line, $offset);
    }
    $start = 0; $length = strlen($value);
    while ($start < $length) {
        $piece = substr($value, $start, length: 4096); $start += strlen($piece);
        if (preg_match('/[^\x00-\x7f]/', $piece) !== 0) { return false; }
        $encoded = JsonEncoding::encode($piece);
        if (!database_transfer_codec_wire_fragment(substr($encoded, offset: 1, length: -1), $line, $offset)) { return false; }
    }
    return database_transfer_codec_wire_fragment('"', $line, $offset);
}

function database_transfer_codec_wire_fragment(#[\SensitiveParameter] string $fragment, #[\SensitiveParameter] string $line, int &$offset): bool
{
    $length = strlen($fragment); $available = strlen($line);
    if ($offset < 0 || $offset > $available || $length > $available - $offset) { return false; }
    if ($length !== 0 && substr_compare($line, $fragment, $offset, $length) !== 0) { return false; }
    $offset += $length;
    return true;
}
