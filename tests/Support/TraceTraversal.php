<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;


final class TraceTraversal
{
    public \SplObjectStorage $seen;
    public int $visited = 0;
    public bool $leaked = false;
    public array $opaque = [];
    public int $redacted = 0;
    public function __construct(#[\SensitiveParameter] public array $pending, #[\SensitiveParameter] public readonly array $markers)
    { $this->seen = new \SplObjectStorage(); }
    public function inspect(): array
    {
        while ($this->pending !== []) {
            if (++$this->visited > 10_000) { throw new \RuntimeException('Trace inspection bound exceeded'); }
            $value = array_pop($this->pending);
            if (is_string($value)) {
                foreach ($this->markers as $marker) { $this->leaked = $this->leaked || str_contains($value, $marker); }
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $key => $item) { $this->pending[] = $key; $this->pending[] = $item; }
                continue;
            }
            if (is_resource($value)) { $this->opaque['resource-state'] = ($this->opaque['resource-state'] ?? 0) + 1; continue; }
            if (!is_object($value)) { continue; }
            if ($value instanceof \SensitiveParameterValue) { ++$this->redacted; continue; }
            if ($this->seen->offsetExists($value)) { continue; }
            $this->seen->offsetSet($value);
            if ($value instanceof \Closure) { $this->opaque['closure'] = ($this->opaque['closure'] ?? 0) + 1; continue; }
            TraceObjectInspection::append($value, $this);
        }
        return ['leaked' => $this->leaked, 'complete' => $this->opaque === [], 'opaque' => $this->opaque, 'redacted' => $this->redacted];
        }
}
