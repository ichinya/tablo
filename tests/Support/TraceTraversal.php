<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;


/** @phpstan-import-type Result from TraceInspector */
final class TraceTraversal
{
    /** @var \SplObjectStorage<object, null> */
    public \SplObjectStorage $seen;
    public int $visited = 0;
    public bool $leaked = false;
    /** @var array<string, int> */
    public array $opaque = [];
    public int $redacted = 0;
    /**
     * @param array<array-key, mixed> $pending Actual raw native trace arguments, never treated as domain data.
     * @param list<string> $markers
     */
    public function __construct(#[\SensitiveParameter] public array $pending, #[\SensitiveParameter] public readonly array $markers)
    {
        /** @var \SplObjectStorage<object, null> $seen */
        $seen = new \SplObjectStorage();
        $this->seen = $seen;
    }
    /** @return Result */
    public function inspect(): array
    {
        while ($this->pending !== []) {
            if (++$this->visited > 10_000) { throw new \RuntimeException('Trace inspection bound exceeded'); }
            TraceValueInspection::append(array_pop($this->pending), $this);
        }
        return ['leaked' => $this->leaked, 'complete' => $this->opaque === [], 'opaque' => $this->opaque, 'redacted' => $this->redacted];
        }
}
