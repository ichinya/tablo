<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** Mutable observation shared with callbacks; reads reflect actual invocation count. */
final class FixtureCounter
{
    private int $count = 0;
    public function increment(): int { return ++$this->count; }
    public function value(): int { return $this->count; }
}
