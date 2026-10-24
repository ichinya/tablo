<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 * @phpstan-import-type Table from ServerSchema
 * @phpstan-import-type Column from ServerSchema
 * @phpstan-import-type Layout from ServerSchema
 */
final class ServerSchemaDescriptor
{
    /** @return Layout */
    public static function layout(PDO $db): array
    {
        $engine = DatabaseSession::get($db)?->configuration->engine;
        return self::forEngine($engine);
    }

    /** @internal Shared finite source for server admission and the fixed transfer projection.
     * @return Layout */
    public static function forEngine(?string $engine): array
    {
        $file = match ($engine) { 'mysql' => 'mysql84', 'mariadb' => 'mariadb114', default => throw new DatabaseFailure('configuration') };
        return self::sourceLayout(dirname(__DIR__) . '/database/' . $file . '-layout.jsonl', $engine);
    }

    /** @return Layout */
    private static function sourceLayout(#[\SensitiveParameter] string $path, string $engine): array
    {
        return server_schema_descriptor_layout(ServerSchemaRecords::read($path), $engine);
    }

    public static function checksum(PDO $db, int $version = 10): string
    {
        if (!in_array($version, [9, 10], strict: true)) { self::refuse(); }
        $tables = array_values(array_filter(self::layout($db)['tables'],
            static fn (array $table): bool => $version === 10 || $table['name'] !== 'read_api_token'));
        return self::checksumTables($tables);
    }

    /** @param list<array> $tables */
    private static function checksumTables(array $tables): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, data: '[');
        $separator = '';
        foreach ($tables as $table) {
            $encoded = JsonEncoding::encode([$table]);
            hash_update($hash, $separator);
            $limit = strlen($encoded) - 1;
            for ($offset = 1; $offset < $limit; $offset += 4096) {
                hash_update($hash, substr($encoded, $offset, min(4096, $limit - $offset)));
            }
            unset($encoded);
            $separator = ',';
        }
        hash_update($hash, data: ']');
        return hash_final($hash);
    }
    public static function sameDefault(mixed $actual, ?string $expected): bool
    {
        if ($actual === 'NULL') { $actual = null; }
        if ($expected === null) { return $actual === null; }
        if (!is_string($actual)) { return false; }
        if (str_starts_with($expected, "'")) {
            $value = substr($expected, offset: 1, length: -1);
            return in_array($actual, [$expected, $value, '(' . $expected . ')'], strict: true);
        }
        return $actual === $expected;
    }
    /** @param Column $expected */
    public static function sameMysqlDefault(mixed $actual, array $expected, string $extra): bool
    {
        return self::sameDefault($actual, $expected['default']) || server_schema_mysql_default($actual, $expected, $extra);
    }
    private static function refuse(): never { throw new DatabaseFailure('schema'); }

}

/** @internal Original whole-graph integrity policy, applied only after complete record parsing.
 * @return \Tablo\ServerSchema::Layout */
function server_schema_descriptor_layout(array $layout, string $engine): array
{
    $tables = JsonRecord::checked($layout['tables'] ?? null);
    if (($layout['format'] ?? null) !== 1 || ($layout['engine'] ?? null) !== $engine || count($tables) !== 18) { throw new DatabaseFailure('schema'); }
    foreach (array_keys($tables) as $key) {
        $table = JsonRecord::checked($tables[$key] ?? null);
        if (!is_string($table['checksum'] ?? null) || !is_string($table['create'] ?? null)
            || !hash_equals($table['checksum'], hash('sha256', $table['create']))) { throw new DatabaseFailure('schema'); }
    }
    /** @var \Tablo\ServerSchema::Layout $layout Checked-in finite graph after framing and original full-graph integrity checks. */
    return $layout;
}

/** Compare only source-proven MySQL printers for the descriptor's printable ASCII string literals.
 * @param \Tablo\ServerSchema::Column $expected */
function server_schema_mysql_default(mixed $actual, array $expected, string $extra): bool
{
    $literal = $expected['default'];
    if (!is_string($actual) || strlen($actual) > 32_768 || $literal === null || strlen($literal) > 16_384
        || !str_starts_with($literal, "'") || !str_ends_with($literal, "'")) { return false; }
    $body = substr($literal, offset: 1, length: -1);
    if (str_contains(str_replace("''", replace: '', subject: $body), "'") || preg_match('/[^\x20-\x5b\x5d-\x7e]/', $body) === 1) { return false; }
    $value = str_replace("''", replace: "'", subject: $body);
    if (preg_match('/\Avarbinary\([1-9][0-9]*\)\z/D', $expected['type']) === 1 && $extra === '') {
        return $value !== '' && preg_match('/\A0x(?:[0-9a-fA-F]{2})+\z/D', $actual) === 1 && strtolower($actual) === '0x' . bin2hex($value);
    }
    if ($expected['type'] !== 'longtext' || $extra !== 'DEFAULT_GENERATED') { return false; }
    // Item_string first escapes its payload; dd_table then adds one outer String::print layer.
    return $actual === server_schema_mysql_default_escape("_utf8mb4'" . server_schema_mysql_default_escape($value) . "'");
}

function server_schema_mysql_default_escape(string $value): string
{
    return strtr($value, ['\\' => '\\\\', "'" => "\\'", "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', chr(26) => '\\Z']);
}
