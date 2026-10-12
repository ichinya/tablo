<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Snapshot from CheckHistorySnapshot
 * @phpstan-import-type Record from SqlNotificationSettingsActivationIdEnabledEndpointPresentRow as Settings
 */
final class NotificationProjectionContext
{
    /**
     * @param Snapshot $row
     * @param array{name: string, url: string, comparison_mode: string, ...} $site
     * @param Settings $settings
     */
    public function __construct(public readonly int $historyId, public readonly array $row, #[\SensitiveParameter] public readonly array $site, public readonly array $settings, public readonly int $wall) {}
}
