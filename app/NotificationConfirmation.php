<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type State from NotificationCheckpointData */
final class NotificationConfirmation
{
    /**
     * @param State $state
     * @param 'health'|'lag' $key
     */
    public static function confirm(array &$state, string $key, string $sample, int $wall): bool
    {
        if ($key === 'health') {
            return self::step($state['health_since'], $state['health_wall'], $state['health_count'], $sample, $wall);
        }
        return self::step($state['lag_since'], $state['lag_wall'], $state['lag_count'], $sample, $wall);
    }

    private static function step(?string &$since, ?int &$started, int &$count, string $sample, int $wall): bool
    {
        if ($since === null) { $since = $sample; $started = $wall; }
        $count = min(2, $count + 1);
        if ($started === null) { throw new \RuntimeException('Invalid notification checkpoint.'); }
        return $count >= 2 && NotificationConfirmationTime::seconds($sample)-NotificationConfirmationTime::seconds($since) >= 60
            && $wall-$started >= 60;
    }
}
