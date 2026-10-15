<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;

final class Database
{
    public const CURRENT_SCHEMA_VERSION = 9;


    // Prospective activation: these additive DDL statements never read current values or retained history.

    // Version 0 installations may lack these additive fields and git_tokens.


    public static function connect(#[\SensitiveParameter] ?string $path = null, #[\SensitiveParameter] ?TokenVault $vault = null): PDO
    {
        $vault ??= TokenVault::configured(); // Explicit file failure precedes any database open.
        $vault?->assertAvailable(KeyRequirement::Available);
        $path ??= ExistingConnection::resolvePath(path: null);
        if ($vault?->isExternal()) {
            ExistingConnection::validateExistingUri($path); // Preserve the reviewed literal URI/VFS admission.
        }
        // SQLite file: URIs are not filesystem paths for PHP's directory functions.
        if ($path !== ':memory:' && !str_starts_with($path, 'file:') && !is_dir(dirname($path))) {
            mkdir(dirname($path), permissions: 0o700, recursive: true);
        }
        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        if ($vault?->isExternal()) {
            ExternalKeyAuthentication::authenticateExternalKey($db, $vault);
        }
        $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
        self::migrate($db);
        if ($path !== ':memory:') {
            // Configure only accepted schemas; rejected future databases keep their journal metadata.
            self::ensureWal($db);
            \Tablo\NativeWarning::ignore(static function () use (&$path) { return chmod($path, permissions: 0o600); });
        }
        return $db;
    }





    public static function preflightExternalKey(#[\SensitiveParameter] ?string $path = null): void
    {
        $vault = TokenVault::configured();
        if ($vault === null) { throw new SharedKeyFailure('External token key configuration is required for this preflight.'); }
        $db = ExistingConnection::openExistingConnection($path);
        ExternalKeyAuthentication::authenticateExternalKey($db, $vault);
        SchemaVersion::schemaVersion($db); // Refuse a future schema; never migrate or configure WAL.
    }



    public static function openExisting(#[\SensitiveParameter] ?string $path = null): PDO
    {
        $db = ExistingConnection::openExistingConnection($path);
        try { InstalledAdministrator::installationIdentity($db); InstalledAdministrator::existingAdminHash($db); }
        catch (RuntimeException $error) { ExistingConnection::refuseExistingFailure($error, 'Installation unavailable.'); }
        return $db;
    }

    public static function openWorkerControl(#[\SensitiveParameter] ?string $path = null): PDO
    {
        $db = ExistingConnection::openExistingConnection($path);
        InstalledAdministrator::installationIdentity($db);
        if (SchemaVersion::schemaVersion($db) !== Database::CURRENT_SCHEMA_VERSION
            || array_diff(['id', 'run_id', 'stop_requested', 'fairness_turn'], array_column(BaseSchema::columns($db, 'worker_runtime'), 'name')) !== []) {
            throw new InstallationException('Worker control schema incompatible.');
        }
        return $db;
    }







    /** Identity comes from the opened connection, never from its requested DSN. */































    private static function ensureWal(PDO $db): void { WalNegotiation::ensureWal($db); }
    public static function migrate(PDO $db): void { SchemaUpgrade::migrate($db); }
}
