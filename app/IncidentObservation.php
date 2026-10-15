<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Input array{site_id: int, config_revision: int, checked_at: string, online: ?int, health_error_code: ?string, health_http_status: ?int, ...} */
final class IncidentObservation
{
    /** @param Input $row */
    public function __construct(public readonly int $historyId,public readonly array $row,public readonly bool $older) {}
}
