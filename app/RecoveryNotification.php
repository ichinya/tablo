<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type State from NotificationCheckpointData
 * @phpstan-import-type Record from SqlIncidentsClockInvalidConfigRevisionEndReasonRow as Incident
 */
final class RecoveryNotification
{
    public function __construct(private readonly NotificationProjectionStore $store, private readonly NotificationEnqueue $enqueue) {}
    /**
     * @param State $state
     * @param Incident $incident
     */
    public function accepted(NotificationProjectionContext $context, array &$state, array $incident): void
    {
        $row = $context->row; $settings = $context->settings; $wall = $context->wall;
                    $slot = \Tablo\SqlRows::one($this->store->one("SELECT source_id, attempts FROM notification_slots WHERE site_id = ? AND event = 'unavailable'",[$row['site_id']]), \Tablo\SqlNotificationSlotsAttemptsSourceIdRow::checked(...));
                    $attempted = $slot !== false && $slot['source_id'] === $incident['id'] && $slot['attempts'] > 0;
                    $this->store->execute("UPDATE notification_slots SET status = 'cancelled', claim = NULL, lease_until = NULL
                        WHERE site_id = ? AND event = 'unavailable' AND source_id = ? AND attempts = 0 AND status = 'pending'",[$row['site_id'],$incident['id']]);
                    if ($settings['recovery'] && (!$settings['unavailable'] || $attempted)
                        && $state['recovery_handled'] !== $incident['id']) {
                        $this->enqueue->enqueue($context,'recovery',$incident['id'],null);
                    }
                    $state['recovery_handled'] = $incident['id'];
                    $state['health_wall'] = null; $state['health_since'] = null; $state['health'] = null;
                    $state['health_count'] = 0;
    }
}
