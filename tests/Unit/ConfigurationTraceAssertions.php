<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use RuntimeException;
use SensitiveParameterValue;
use Tablo\ClientAddress;
use Testo\Assert;

final class ConfigurationTraceAssertions
{
    public static function redacted(RuntimeException $caught, string $marker): void
    {
            // Inspect all propagated/previous traces only in memory. Never unwrap or print their arguments.
            $inspect = static function (mixed $value) use ($marker, &$inspect): bool {
                if ($value instanceof SensitiveParameterValue) { return false; }
                if (is_string($value)) { return str_contains($value, $marker); }
                if (is_array($value)) {
                    foreach (array_keys($value) as $key) {
                        if ($inspect($key) || $inspect(\Tablo\Tests\Support\FixtureValue::at($value, $key))) { return true; }
                    }
                }
                return false;
            };
            $redactedConstructor = false;
            for ($error = $caught; $error !== null; $error = $error->getPrevious()) {
                $trace = $error->getTrace();
                Assert::true(!$inspect($trace), 'Raw configuration retained in propagated trace');
                Assert::true(!str_contains($error->getMessage() . $error->getTraceAsString() . (string) $error, $marker),
                    'Raw configuration retained in exception string');
                foreach (\Tablo\SqlRows::all($trace, \Tablo\JsonRecord::checked(...)) as $frame) {
                    if (!(($frame['class'] ?? null) === ClientAddress::class && ($frame['function'] ?? null) === '__construct')) { continue; }

                        $redactedConstructor = ($frame['args'][0] ?? null) instanceof SensitiveParameterValue;

                }
            }
            Assert::true($redactedConstructor, 'Actual constructor argument must be SensitiveParameterValue');

    }
}
