<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;

final class ExistingConnection
{
    public static function resolvePath(#[\SensitiveParameter] ?string $path): string
    {
        if ($path !== null) { return $path; }
        $configuredPath = getenv('TABLO_DB');
        return $configuredPath ? $configuredPath : dirname(__DIR__) . '/storage/tablo.sqlite';
    }
    public static function openExistingConnection(#[\SensitiveParameter] ?string $path): PDO
    {
        $configuration = DatabaseConfiguration::fromEnvironment();
        if ($configuration->server()) {
            if ($path !== null) { throw new DatabaseFailure('configuration'); }
            return DatabaseConnectionFactory::open($configuration);
        }
        $path = ExistingConnection::resolvePath($path);
        if ($path === '' || $path === ':memory:' || str_contains($path, "\0")) {
            throw new InstallationException('Installation unavailable.');
        }
        ExistingConnection::validateExistingUri($path);
        try {
            // URI options cannot grant CREATE when the actual open flags omit it.
            $flags = PHP_VERSION_ID >= 80_400 ? \Pdo\Sqlite::ATTR_OPEN_FLAGS : PDO::SQLITE_ATTR_OPEN_FLAGS;
            $readWrite = PHP_VERSION_ID >= 80_400 ? \Pdo\Sqlite::OPEN_READWRITE : PDO::SQLITE_OPEN_READWRITE;
            $db = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                $flags => $readWrite,
            ]);
            $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
            return $db;
        } catch (RuntimeException $error) {
            // DSNs and native diagnostics may contain private configuration.
            ExistingConnection::refuseExistingFailure($error, 'Installation unavailable.');
        }
    }
    public static function refuseExistingFailure(#[\SensitiveParameter] RuntimeException $error, string $message): never
    {
        if ($error instanceof DatabaseFailure) { throw $error; }
        if ($error instanceof PasswordRuntimeFailure) { throw new PasswordRuntimeFailure(); }
        $code = 0;
        if ($error instanceof \PDOException) {
            // PDO SQLite's native driver code is an integer; absent/null stays the prior zero fallback.
            /** @var array{0?: string, 1?: ?int, 2?: ?string}|null $errorInfo */
            $errorInfo = $error->errorInfo;
            $code = ($errorInfo[1] ?? 0) & 255;
        }
        if (in_array($code, [5, 6], strict: true)) {
            $safe = new \PDOException('Installation busy.');
            $safe->errorInfo = ['HY000', $code, null];
            throw $safe;
        }
        throw new InstallationException($message);
    }


    public static function validateExistingUri(#[\SensitiveParameter] string $path): void { ExistingDatabaseUri::validateExistingUri($path); }
}
