<?php
declare(strict_types=1);

namespace Tablo;

final class WalAttemptResult
{
    public function __construct(public readonly ?\Throwable $error, public readonly bool $cleanupFailed, public readonly mixed $mode) {}
}
