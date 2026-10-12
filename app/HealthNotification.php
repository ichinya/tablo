<?php
declare(strict_types=1);

namespace Tablo;

final class HealthNotification
{
    public function __construct(private readonly NotificationProjectionStore $store, private readonly NotificationEnqueue $enqueue) {}
    public function accepted(NotificationProjectionContext $context, array &$state): void
    {
        $incident = $this->store->one('SELECT * FROM incidents WHERE site_id = ? AND (end_reason IS NULL OR recovery_history_id = ?)
                ORDER BY id DESC LIMIT 1',[$context->row['site_id'],$context->historyId]);
        if ($incident === false || $incident['id'] <= $context->settings['activation_id']) { return; }
        if ($incident['end_reason'] === 'recovered') { (new RecoveryNotification($this->store,$this->enqueue))->accepted($context,$state,$incident); return; }
        if ($context->row['online'] === 0) { (new UnavailableNotification($this->store,$this->enqueue))->accepted($context,$state,$incident); return; }
        $state['health_wall'] = null; $state['health_since'] = null; $state['health_count'] = 0;
    }
}
