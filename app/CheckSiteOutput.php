<?php
declare(strict_types=1);

namespace Tablo;

final class CheckSiteOutput
{
    public static function site(#[\SensitiveParameter] array $site, string $status, string $reason, ?bool $attention,
        #[\SensitiveParameter] ?CheckSiteObservation $context = null): array
    {
        $state = $context?->state;
        $stored = $context?->stored;
        $persistence = $context->persistence ?? 'not-attempted';
        $now = $context->now ?? time();
        $id = CheckScalar::integer($site['id'] ?? null, 1);
        if ($id === null) { CheckScalar::invalid(); }
        $previous = OutputTimestamp::timestamp($site['checked_at'] ?? null);
        $previousFreshness = OutputTimestamp::previous($site['checked_at'] ?? null, $previous, $now);
        $observation = $state === null ? null : CheckObservation::project($state, $now);
        return ['id' => $id, 'status' => $status, 'reason' => $reason, 'attention' => $attention,
            'observation' => $observation, 'persistence' => ['status' => $persistence, 'accepted' => $stored,
                'previous_checked_at' => $previous, 'previous_freshness' => $previousFreshness,
                'accepted_checked_at' => $stored === true ? $observation['checked_at'] : null]];
    }
}
