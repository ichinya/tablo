<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** Each raw argument is classified before any scalar or object operation. */
final class TraceValueInspection
{
    public static function append(#[\SensitiveParameter] mixed $value, TraceTraversal $state): void
    {
        if (is_string($value)) {
            foreach ($state->markers as $marker) { $state->leaked = $state->leaked || str_contains($value, $marker); }
            return;
        }
        if (is_array($value)) {
            foreach (array_keys($value) as $key) { $state->pending[] = $key; self::enqueue(\Tablo\Tests\Support\FixtureValue::at($value, $key), $state); }
            return;
        }
        if (is_resource($value)) { $state->opaque['resource-state'] = ($state->opaque['resource-state'] ?? 0) + 1; return; }
        if (!is_object($value)) { return; }
        if ($value instanceof \SensitiveParameterValue) { ++$state->redacted; return; }
        if ($state->seen->offsetExists($value)) { return; }
        $state->seen->offsetSet($value);
        if ($value instanceof \Closure) { $state->opaque['closure'] = ($state->opaque['closure'] ?? 0) + 1; return; }
        TraceObjectInspection::append($value, $state);
    }

    private static function enqueue(#[\SensitiveParameter] mixed $value, TraceTraversal $state): void
    {
        $state->pending[] = $value;
    }
}
