<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
/** @phpstan-import-type Snapshot from CheckHistorySnapshot */
final class NotificationProjector
{
    public function __construct(private readonly PDO $db, #[\SensitiveParameter] private readonly TokenVault $vault) {}

    // Called only within the genuine SiteRepository accepted-result transaction.
    /**
     * @param Snapshot $row
     * @param array{name: string, url: string, comparison_mode: string, ...} $site
     */
    public function accepted(int $historyId, array $row, #[\SensitiveParameter] array $site, int $wall): void
    {
        $store = new NotificationProjectionStore($this->db);
        $enqueue = new NotificationEnqueue($store, $this->vault);
        $settings = \Tablo\SqlRows::required($store->one('SELECT installation_id, revision, activation_id, enabled, unavailable, recovery, version_lag,
            include_name, include_url, endpoint_cipher IS NOT NULL AS endpoint_present FROM notification_settings WHERE id = 1'), \Tablo\SqlNotificationSettingsActivationIdEnabledEndpointPresentRow::checked(...));
        $context = new NotificationProjectionContext($historyId, $row, $site, $settings, $wall);
        $old = \Tablo\SqlRows::one($store->one('SELECT * FROM notification_checkpoints WHERE site_id = ?', [$row['site_id']]), \Tablo\SqlNotificationCheckpointsConfigRevisionLastHistoryIdSiteIdRow::checked(...));
        if ($old !== false && $old['last_history_id'] >= $historyId) { return; }
        $state = NotificationCheckpointState::prepare($store,$context,$old);
        $late = $row['checked_at'] < $state['watermark'] || $wall < $state['wall'];
        $state['watermark'] = max($state['watermark'],$row['checked_at']);
        $state['wall'] = max($state['wall'],$wall);
        $enabled = $settings['enabled'] && $settings['endpoint_present']
            && ($settings['unavailable'] || $settings['recovery'] || $settings['version_lag']);
        if (!$enabled || $late) {
            NotificationCheckpointState::reset($state);
        }
        if ($enabled && !$late) {
            (new HealthNotification($store, $enqueue))->accepted($context,$state);
            (new LagNotification($store, $enqueue))->accepted($context,$state);
        }
        $store->execute('INSERT INTO notification_checkpoints (site_id,last_history_id,config_revision,state) VALUES (?,?,?,?)
            ON CONFLICT(site_id) DO UPDATE SET last_history_id = excluded.last_history_id,
            config_revision = excluded.config_revision, state = excluded.state',
            [$row['site_id'],$historyId,$row['config_revision'],json_encode($state,JSON_THROW_ON_ERROR)]);
    }










    /**
     * @param Snapshot $row
     * @param array{comparison_mode: string, ...} $site
     */
    public static function comparison(array $row, #[\SensitiveParameter] array $site): string|false|null { return NotificationComparison::comparison($row, $site); }
}
