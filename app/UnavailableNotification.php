<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type State from NotificationCheckpointData
 * @phpstan-import-type Record from SqlIncidentsClockInvalidConfigRevisionEndReasonRow as Incident
 */
final class UnavailableNotification
{
    public function __construct(NotificationProjectionStore $store, private readonly NotificationEnqueue $enqueue) {}
    /**
     * @param State $state
     * @param Incident $incident
     */
    public function accepted(NotificationProjectionContext $context, array &$state, array $incident): void
    {
        $row = $context->row; $settings = $context->settings; $wall = $context->wall;
                    if ($state['health'] !== $incident['id']) {
                        $state['health'] = $incident['id']; $state['health_wall'] = null; $state['health_since'] = null; $state['health_count'] = 0;
                    }
                    if (NotificationConfirmation::confirm($state,'health',$row['checked_at'],$wall) && $settings['unavailable']
                        && $state['health_handled'] !== $incident['id']) {
                        $this->enqueue->enqueue($context,'unavailable',$incident['id'],null);
                        $state['health_handled'] = $incident['id'];
                    }
    }
}
