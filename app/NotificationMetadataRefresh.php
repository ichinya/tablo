<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Flags from NotificationFlags */
final class NotificationMetadataRefresh
{
    public function __construct(private readonly \PDO $db, #[\SensitiveParameter] private readonly TokenVault $vault) {}
    /** @param Flags $flags */
    public function refresh(array $flags, int $revision): void
    {
        (new NotificationPendingMetadata($this->db, $this->vault))->refresh($flags, $revision);
        (new NotificationCheckpointRebase($this->db))->refresh($revision);
    }

}
