<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubClock
{
    public function __construct(private readonly ?\Closure $monotonic = null, private readonly ?\Closure $epoch = null) {}
    public function monotonic(): int { return $this->monotonic === null ? hrtime(true) : ($this->monotonic)(); }
    public function epoch(): int { return $this->epoch === null ? time() : ($this->epoch)(); }
}
