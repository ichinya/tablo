<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;


final class TraceObjectInspection
{
    public static function append(object $value, TraceTraversal $state): void
    {
            $class = new \ReflectionObject($value);
            if (method_exists($class, 'isUninitializedLazyObject') && $class->isUninitializedLazyObject($value)) {
                $state->opaque['lazy-object'] = ($state->opaque['lazy-object'] ?? 0) + 1;
                return; // Never initialize a lazy carrier merely to inspect a trace.
            }
            do {
                if ($class->isInternal() && $class->getName() !== \stdClass::class) {
                    $state->opaque['internal-state'] = ($state->opaque['internal-state'] ?? 0) + 1;
                }
                foreach ($class->getProperties() as $property) {
                    if (!$property->isStatic() && !$property->isInitialized($value)) {
                        $state->opaque['uninitialized-property'] = ($state->opaque['uninitialized-property'] ?? 0) + 1;
                    }
                    if (!$property->isStatic() && method_exists($property, 'isVirtual') && $property->isVirtual()) {
                        $state->opaque['virtual-property'] = ($state->opaque['virtual-property'] ?? 0) + 1;
                    }
                }
                $class = $class->getParentClass();
            } while ($class !== false);
            // Raw initialized instance slots include inherited private/protected and dynamic properties.
            // This invokes no accessors, debug hooks, closures or credential methods.
            $state->pending[] = get_mangled_object_vars($value);
    }
}
