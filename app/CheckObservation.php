<?php
declare(strict_types=1);

namespace Tablo;

final class CheckObservation
{

    public static function project(#[\SensitiveParameter] array $state, int $now): array
    {
        $checked = OutputTimestamp::timestamp($state['checked_at'] ?? null);
        if ($checked === null) { CheckScalar::invalid(); }
        return ['checked_at' => $checked, 'freshness' => OutputTimestamp::freshness($checked, $now),
            'health' => HealthOutput::project($state), 'version' => VersionOutput::project($state), 'git' => GitOutput::project($state)];
    }

}
