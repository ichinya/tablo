<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type State from NotificationCheckpointData */
final class LagNotification
{
    public function __construct(private readonly NotificationProjectionStore $store, private readonly NotificationEnqueue $enqueue) {}
    /** @param State $state */
    public function accepted(NotificationProjectionContext $context, array &$state): void
    {
        $row = $context->row; $site = $context->site; $settings = $context->settings; $wall = $context->wall; $historyId = $context->historyId;
            $comparison = NotificationComparison::comparison($row,$site);
            if ($comparison === false) {
                $this->store->execute("UPDATE notification_slots SET status='cancelled',claim=NULL,lease_until=NULL
                    WHERE site_id=? AND event='version_lag' AND status IN ('pending','inflight')",[$row['site_id']]);
                $state['lag_wall'] = null; $state['lag_since'] = null; $state['lag'] = null; $state['lag_count'] = 0;
                return;
            }
            if ($comparison === null) {
                $state['lag_wall'] = null; $state['lag_since'] = null; $state['lag_count'] = 0;
                return;
            }
            if ($historyId > $settings['activation_id']) {
                $state['lag'] ??= $historyId;
                if (NotificationConfirmation::confirm($state,'lag',$row['checked_at'],$wall) && $settings['version_lag']
                    && $state['lag'] !== null && $state['lag'] > $settings['activation_id']
                    && $state['lag_handled'] !== $state['lag']) {
                    $this->enqueue->enqueue($context,'version_lag',$state['lag'],$comparison);
                    $state['lag_handled'] = $state['lag'];
                }
            }
    }
}
