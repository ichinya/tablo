<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Health from HealthOutput
 * @phpstan-import-type Version from VersionOutput
 * @phpstan-import-type Git from GitOutput
 * @phpstan-type Observation array{checked_at: string, freshness: string, health: Health, version: Version, git: Git}
 */
final class CheckObservation
{

    /** @return Observation */
    public static function project(#[\SensitiveParameter] array $state, int $now): array
    {
        $checked = OutputTimestamp::timestamp($state['checked_at'] ?? null);
        if ($checked === null) { CheckScalar::invalid(); }
        return ['checked_at' => $checked, 'freshness' => OutputTimestamp::freshness($checked, $now),
            'health' => HealthOutput::project($state), 'version' => VersionOutput::project($state), 'git' => GitOutput::project($state)];
    }

}
