<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationConfirmation
{
    public static function confirm(array &$state, string $key, string $sample, int $wall): bool
    {
        if ($state[$key.'_since'] === null) { $state[$key.'_since']=$sample; $state[$key.'_wall']=$wall; }
        $state[$key.'_count'] = min(2,$state[$key.'_count']+1);
        return $state[$key.'_count'] >= 2 && strtotime($sample)-strtotime($state[$key.'_since']) >= 60
            && $wall-$state[$key.'_wall'] >= 60;
    }
}
