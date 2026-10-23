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
        $file = match ($engine) { 'mysql' => 'mysql84', 'mariadb' => 'mariadb114', default => throw new DatabaseFailure('configuration') };
        $bytes = file_get_contents(dirname(__DIR__) . '/database/' . $file . '-layout.json');
        if ($bytes === false) { self::refuse(); }
        /** @var Layout $layout Checked-in source with a fixed format, not an imported schema description. */
        $layout = json_decode($bytes, associative: true, flags: JSON_THROW_ON_ERROR);
        if ($layout['format'] !== 1 || $layout['engine'] !== $engine || count($layout['tables']) !== 18) { self::refuse(); }
        foreach ($layout['tables'] as $table) {
            if (hash_equals($table['checksum'], hash('sha256', $table['create']))) { continue; }
            self::refuse();
        }
        return $layout;
    }

    public static function checksum(PDO $db, int $version = 10): string
    {
        if (!in_array($version, [9, 10], strict: true)) { self::refuse(); }
        return hash('sha256', JsonEncoding::encode(array_values(array_filter(self::layout($db)['tables'],
            static fn (array $table): bool => $version === 10 || $table['name'] !== 'read_api_token'))));
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
