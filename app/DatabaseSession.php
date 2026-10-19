<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use WeakMap;

/** Policy belongs to the exact admitted PDO, including callers' transactions.
 * @phpstan-import-type Observation from ServerMetadataAdmission */
final class DatabaseSession
{
    /** @var WeakMap<PDO, self>|null */
    private static ?WeakMap $sessions = null;
    public string $installationId = '';
    public bool $keyAuthenticated = false;
    public ?TokenVault $vault = null;
    /** @var Observation|null */
    public ?array $metadataObservation = null;

    private function __construct(#[\SensitiveParameter] public readonly DatabaseConfiguration $configuration) {}

    public static function register(PDO $db, #[\SensitiveParameter] DatabaseConfiguration $configuration): self
    {
        self::$sessions ??= new WeakMap();
        if ((self::$sessions[$db] ?? null) !== null) { throw new \LogicException('Database session already admitted.'); }
        $session = new self($configuration);
        self::$sessions[$db] = $session;
        return $session;
    }

    public static function get(PDO $db): ?self
    {
        $session = self::$sessions[$db] ?? null;
        if ($session === null && $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') { throw new DatabaseFailure('configuration'); }
        return $session;
    }

    public static function server(PDO $db): bool { return self::get($db) !== null; }
    public static function collation(PDO $db): string
    {
        return self::get($db)?->configuration->engine === 'mariadb' ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin';
    }
}
