<?php
declare(strict_types=1);

namespace Tablo;

final class SiteSnapshotId
{
    public static function read(#[\SensitiveParameter] ?array $site): ?int
    {
        if ($site === null || ($site['id'] ?? null) === null) { return null; }
        return (int) $site['id'];
    }
}
