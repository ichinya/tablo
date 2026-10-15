<?php
declare(strict_types=1);

namespace Tablo;

final class CheckSettlementState
{
    public function __construct(public readonly bool $stored,public readonly bool $failed) {}
}
