<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/** Transient, explicitly privileged observation under the documented quiescent DDL lifecycle.
 * @phpstan-import-type Objects from ServerMetadataInventory
 * @phpstan-type Observation array{server: array<string, int|string>, account: string, objects: Objects, installation: list<array>, checks: array<string, array<string, string>>}
 */
final class ServerMetadataAdmission
{
    /** @return Observation */
    public static function observe(#[\SensitiveParameter] DatabaseConfiguration $configuration, ?int $version = null): array
    {
        $db = null;
        try {
            $metadata = $configuration->metadata(); $db = ServerPdoConnection::open($metadata);
            ServerMetadataPrivileges::verify($db, $metadata);
            $objects = ServerMetadataInventory::read($db);
            $names = ServerMetadataInventory::tables($objects);
            if ($version !== null) { self::expected($db, $names, $version); }
            return ['server' => ServerMetadataIdentity::server($db), 'account' => ServerMetadataIdentity::grantee($db, $metadata), 'objects' => $objects,
                'installation' => ServerMetadataIdentity::installation($db, $names), 'checks' => server_metadata_check_observation($db, $configuration, $version)];
        } catch (\PDOException $error) { throw DatabaseFailure::fromNative($error); }
        finally { $db = null; }
    }

    /** @param Observation $observation */
    public static function bind(PDO $db, array $observation): void
    {
        $names = ServerMetadataInventory::tables($observation['objects']);
        if (ServerMetadataIdentity::server($db) !== $observation['server']
            || JsonEncoding::encode(ServerMetadataIdentity::installation($db, $names)) !== JsonEncoding::encode($observation['installation'])) {
            throw new DatabaseFailure('schema');
        }
    }

    /** @return list<string> */
    public static function objects(PDO $db, ?int $version = null): array
    {
        $session = DatabaseSession::get($db) ?? throw new DatabaseFailure('schema');
        $observation = $session->metadataObservation ?? self::observe($session->configuration, $version);
        self::bind($db, $observation);
        $names = ServerMetadataInventory::tables($observation['objects']);
        if ($version !== null) { self::expected($db, $names, $version); }
        return $names;
    }

    /** Fresh outside factory admission; the factory's one-shot observation is cleared before returning its PDO.
     * @return array<string, string> */
    public static function checks(PDO $db, string $table): array
    {
        $session = DatabaseSession::get($db) ?? throw new DatabaseFailure('schema');
        if ($session->configuration->engine !== 'mariadb' || !in_array($session->configuration->role, ['writer', 'control'], strict: true)) { throw new DatabaseFailure('schema'); }
        $observation = $session->metadataObservation ?? self::observe($session->configuration, 10);
        self::bind($db, $observation);
        return $observation['checks'][$table] ?? throw new DatabaseFailure('schema');
    }

    /** @param list<string> $names */
    private static function expected(PDO $db, array $names, int $version): void
    {
        $tables = array_values(array_filter(ServerSchema::layout($db)['tables'], static fn (array $table): bool => $version === 10 || $table['name'] !== 'read_api_token'));
        $expected = array_column($tables, 'name'); sort($expected, SORT_STRING);
        if (!in_array($version, [9, 10], strict: true) || implode("\0", $names) !== implode("\0", $expected)) { throw new DatabaseFailure('schema'); }
    }
}

/** Complete writer and control admission need privileged CHECK visibility. Migrators read their own post-DDL metadata.
 * @return array<string, array<string, string>> */
function server_metadata_check_observation(PDO $db, DatabaseConfiguration $configuration, ?int $version): array
{
    if ($configuration->engine !== 'mariadb' || !in_array($configuration->role, ['writer', 'control'], strict: true) || $version !== 10) { return []; }
    $checks = [];
    foreach (ServerSchema::layout($db)['tables'] as $table) { $checks[$table['name']] = ServerCheckAdmission::clauses($db, $table); }
    return $checks;
}
