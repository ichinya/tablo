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
    private static function refuse(): never { throw new DatabaseFailure('schema'); }

}
