<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Observation from CheckObservation
 * @phpstan-type Record array{id: int, status: string, reason: string, attention: ?bool, observation: ?Observation,
 * persistence: array{status: string, accepted: ?bool, previous_checked_at: ?string, previous_freshness: string, accepted_checked_at: ?string}}
 */
final class CheckSiteOutput
{
    /** @return Record */
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
        $accepted = null;
        if ($stored === true) {
            if ($observation === null) { CheckScalar::invalid(); }
            $accepted = $observation['checked_at'];
        }
        return ['id' => $id, 'status' => $status, 'reason' => $reason, 'attention' => $attention,
            'observation' => $observation, 'persistence' => ['status' => $persistence, 'accepted' => $stored,
                'previous_checked_at' => $previous, 'previous_freshness' => $previousFreshness,
                'accepted_checked_at' => $accepted]];
    }
}
