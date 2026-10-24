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
 * @return \Generator<int, \Tablo\DatabaseTransferRows::Values> */
function database_transfer_rows_read(PDO $db, array $table): \Generator
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
        yield $values;
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
    $values = [];
    foreach ($columns as $index => $column) {
        $cell = JsonRecord::checked($input[$index] ?? null);
        $values[] = database_transfer_value_value(database_transfer_codec_cell($cell), $column);
    }
    // Exact canonical encoding also proves both outer and cell list shapes.
    if (database_transfer_codec_encode($values) !== $input) { throw new DatabaseFailure('schema'); }
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


/**
 * @param array $cell */
function database_transfer_codec_cell(#[\SensitiveParameter] array $cell): int|string|null
{
    if ($cell === ['null']) { return null; }
    $bytes = SqlColumn::string($cell, '1');
    if (count($cell) !== 2) { throw new DatabaseFailure('schema'); }
    if (($cell[0] ?? null) === 'integer') { return DatabaseInteger::checked($bytes); }
    $decoded = base64_decode($bytes, strict: true);
    if (($cell[0] ?? null) !== 'bytes' || $decoded === false || base64_encode($decoded) !== $bytes) { throw new DatabaseFailure('schema'); }
    return $decoded;
}
