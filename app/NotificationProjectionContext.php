<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationProjectionContext
{
    public function __construct(public readonly int $historyId, public readonly array $row, #[\SensitiveParameter] public readonly array $site, public readonly array $settings, public readonly int $wall) {}
}
