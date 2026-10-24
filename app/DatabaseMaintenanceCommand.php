<?php
declare(strict_types=1);

namespace Tablo;

final class DatabaseMaintenanceCommand
{
    /** @param list<string> $arguments */
    public static function run(array $arguments): int
    {
        if (PHP_SAPI !== 'cli' || !in_array($arguments[0] ?? null, ['--init', '--migrate', '--status'], strict: true)
            || (count($arguments) !== 1 && !(count($arguments) === 3 && $arguments[0] === '--init' && $arguments[1] === '--receipt'
                && $arguments[2] !== '' && !str_starts_with($arguments[2], '--')))) {
            throw new \InvalidArgumentException('Usage: php bin/database.php --init --receipt <private-file>|--migrate|--status');
        }
        $configuration = DatabaseConfiguration::fromEnvironment('migrator');
        if (!$configuration->server()) {
            $db = $arguments[0] === '--status' ? ExistingConnection::openExistingConnection(null) : Database::connect();
            fwrite(STDOUT, JsonEncoding::encode(['engine' => 'sqlite', 'logical_version' => SchemaVersion::schemaVersion($db)]) . "\n");
            return 0;
        }
        $vault = TokenVault::configured();
        if ($vault === null) { throw new DatabaseFailure('key'); }
        $db = DatabaseConnectionFactory::open($configuration, mode: 'maintenance');
        $migration = new ServerMigration($db, $vault);
        if ($arguments[0] === '--init') { $migration->initialize($arguments[2] ?? throw new DatabaseFailure('configuration')); }
        if ($arguments[0] === '--migrate') { $migration->migrate(); }
        fwrite(STDOUT, JsonEncoding::encode(['engine' => $configuration->engine] + $migration->status()) . "\n");
        return 0;
    }
}
