<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationCheckpointState
{
    public static function prepare(NotificationProjectionStore $store, NotificationProjectionContext $context, array|false $old): array
    {
        $row = $context->row; $settings = $context->settings;
        $empty = ['channel'=> $settings['revision'], 'watermark'=>'', 'wall'=>0, 'health'=>null, 'health_since'=>null,
            'health_wall'=>null, 'health_count'=>0, 'health_handled'=>null, 'recovery_handled'=>null,
            'lag'=>null, 'lag_since'=>null, 'lag_wall'=>null, 'lag_count'=>0, 'lag_handled'=>null];
        $state = $old === false ? $empty : json_decode($old['state'],associative: true,depth: 16,flags: JSON_THROW_ON_ERROR);
        if ($old !== false && ($old['config_revision'] !== $row['config_revision'] || $state['channel'] !== $settings['revision'])) {
            $store->execute("UPDATE notification_slots SET status = 'cancelled', claim = NULL, lease_until = NULL
                WHERE site_id = ? AND status IN ('pending','inflight')",[$row['site_id']]);
            if ($old['config_revision'] !== $row['config_revision']) { $state = $empty; }
            if ($old['config_revision'] === $row['config_revision']) {
                // Reconfiguration cannot replay an unchanged handled logical transition.
                $state['channel']=$settings['revision'];
                $state['lag_wall'] = null; $state['lag_since'] = null; $state['health_wall'] = null; $state['health_since'] = null;
                $state['lag_count'] = 0; $state['health_count'] = 0;
            }
        }
        return $state;
    }
    public static function reset(array &$state): void
    {
        $state['lag_wall'] = null; $state['lag_since'] = null; $state['health_wall'] = null; $state['health_since'] = null;
        $state['lag_count'] = 0; $state['health_count'] = 0;
    }
}
