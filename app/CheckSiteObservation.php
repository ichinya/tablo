<?php
declare(strict_types=1);

namespace Tablo;

/** Observation and persistence context for a single public site projection. */
final class CheckSiteObservation
{
    public function __construct(
        #[\SensitiveParameter] public readonly array $state,
        public readonly ?bool $stored = null,
        public readonly string $persistence = 'not-attempted',
        public readonly ?int $now = null,
    ) {}
}
