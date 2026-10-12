<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/**
 * Inspects supported trace argument state in memory; opaque state is never a clean verdict.
 * @phpstan-type Result array{leaked: bool, complete: bool, opaque: array<string, int>, redacted: int}
 */
final class TraceInspector
{
    /**
     * @param list<string> $markers
     * @return Result
     */
    public static function inspect(#[\SensitiveParameter] \Throwable $error, #[\SensitiveParameter] array $markers): array
    {
        $pending = [];
        do {
            if (count($pending) >= 10_000) { throw new \RuntimeException('Trace inspection bound exceeded'); }
            array_push($pending, $error->getTrace(), $error->getTraceAsString(), (string) $error);
            $error = $error->getPrevious();
        } while ($error !== null);
        return self::inspectArguments($pending, $markers);
    }

    /**
     * Completeness refers only to the supplied argument values, never to omitted frames.
     * @param array<array-key, mixed> $pending Actual raw native trace argument values, inspected individually.
     * @param list<string> $markers
     * @return Result
     */
    public static function inspectArguments(#[\SensitiveParameter] array $pending, #[\SensitiveParameter] array $markers): array
    { return (new TraceTraversal($pending, $markers))->inspect(); }
}
