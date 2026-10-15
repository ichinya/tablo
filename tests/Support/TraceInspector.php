<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** Inspects supported trace argument state in memory; opaque state is never a clean verdict. */
final class TraceInspector
{
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

    /** Completeness refers only to the supplied argument values, never to omitted frames. */
    public static function inspectArguments(#[\SensitiveParameter] array $pending, #[\SensitiveParameter] array $markers): array
    {
        $seen = new \SplObjectStorage();
        $visited = 0;
        $leaked = false;
        $opaque = [];
        $redacted = 0;
        while ($pending !== []) {
            if (++$visited > 10_000) { throw new \RuntimeException('Trace inspection bound exceeded'); }
            $value = array_pop($pending);
            if (is_string($value)) {
                foreach ($markers as $marker) { $leaked = $leaked || str_contains($value, $marker); }
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $key => $item) { $pending[] = $key; $pending[] = $item; }
                continue;
            }
            if (is_resource($value)) { $opaque['resource-state'] = ($opaque['resource-state'] ?? 0) + 1; continue; }
            if (!is_object($value)) { continue; }
            if ($value instanceof \SensitiveParameterValue) { ++$redacted; continue; }
            if ($seen->offsetExists($value)) { continue; }
            $seen->offsetSet($value);
            if ($value instanceof \Closure) { $opaque['closure'] = ($opaque['closure'] ?? 0) + 1; continue; }
            $class = new \ReflectionObject($value);
            do {
                if ($class->isInternal() && $class->getName() !== \stdClass::class) {
                    $opaque['internal-state'] = ($opaque['internal-state'] ?? 0) + 1;
                }
                foreach ($class->getProperties() as $property) {
                    if (!$property->isStatic() && method_exists($property, 'isVirtual') && $property->isVirtual()) {
                        $opaque['virtual-property'] = ($opaque['virtual-property'] ?? 0) + 1;
                    }
                }
                $class = $class->getParentClass();
            } while ($class !== false);
            // Raw initialized instance slots include inherited private/protected and dynamic properties.
            // This invokes no accessors, debug hooks, closures or credential methods.
            $pending[] = get_mangled_object_vars($value);
        }
        return ['leaked' => $leaked, 'complete' => $opaque === [], 'opaque' => $opaque, 'redacted' => $redacted];
    }
}
