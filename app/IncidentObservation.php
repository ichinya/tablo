<?php
declare(strict_types=1);

namespace Tablo;

final class IncidentObservation
{
    public function __construct(public readonly int $historyId,public readonly array $row,public readonly bool $older) {}
}
