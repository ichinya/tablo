<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\TraceInspector;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;





final class GitHubTraceInspectorTest
{
    private static function capture(mixed $value): \RuntimeException { return new \RuntimeException('Safe trace control'); }
    private static function redacted(#[\SensitiveParameter] mixed $value): \RuntimeException { return new \RuntimeException('Safe redacted control'); }
    private static function leaf(): \RuntimeException { return new \RuntimeException('Safe nested control'); }
    private static function nested(mixed $value): \RuntimeException { return self::leaf(); }

    #[Test]
    public function supportedActualArgumentsCannotHidePrivateOrProtectedState(): void
    {
        $this->runControl('supported');
    }

    #[Test]
    public function opaqueObjectsAndCyclesHaveExplicitCoverageOrLoudBounds(): void
    {
        $this->runControl('opaque');
    }

    #[Test]
    public function nativeCarrierVisibilityAndUninitializedFaultRemainObservable(): void
    {
        $private = new TracePrivateState('parent');
        $protected = new TraceProtectedState('child');
        Assert::same($private->privateMarker(), 'parent');
        Assert::same($protected->privateMarker(), 'safe');
        Assert::same($protected->protectedMarker(), 'child');
        Assert::true((new \ReflectionProperty(TracePrivateState::class, 'marker'))->isPrivate());
        Assert::true((new \ReflectionProperty(TraceProtectedState::class, 'marker'))->isProtected());
        $slot = new \ReflectionProperty(TraceProtectedState::class, 'uninitialized');
        Assert::false($slot->isInitialized($protected));
        $caught = null;
        try { $slot->getValue($protected); } catch (\Throwable $error) { $caught = $error; }
        Assert::instanceOf($caught, \Error::class);
        Assert::false(TraceInspector::inspectArguments([$protected], ['absent'])['complete']);
        $positive = new TraceProtectedState('child');
        $slot->setValue($positive, 'initialized-positive');
        Assert::true($slot->isInitialized($positive));
        Assert::true(TraceInspector::inspectArguments([$positive], ['absent'])['complete']);
    }

    private function runControl(string $mode): void
    {
        $directory = new TemporaryDirectory('tablo-trace-control-');
        try {
            $result = Subprocess::run([PHP_BINARY, '-d', 'zend.exception_ignore_args=0',
                dirname(__DIR__) . '/fixtures/trace-inspector-control.php', $mode], $directory, []);
            Assert::same($result['exit_code'], 0, 'isolated actual trace control must pass');
            Assert::same($result['stdout'], "Trace control passed.\n");
            Assert::same($result['stderr'], '');
        } finally { $directory->close(); }
    }

    // Executed by the bounded child without the test runner's graph in its actual stack.
    // The inspector still reads every supplied frame and retains its original node bound.
    public function inspectSupportedActualArguments(): void
    {
        $original = ini_get('zend.exception_ignore_args');
        \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: '0');
        try {
            $marker = bin2hex(random_bytes(24));
            $private = new TracePrivateState($marker);
            $protected = new TraceProtectedState($marker);
            foreach ([$marker, ['nested' => [$marker]], [$marker => 'safe'], (object) ['value' => $marker], $private, $protected] as $value) {
                $error = self::capture($value);
                Assert::true(($error->getTrace()[0]['args'][0] ?? null) === $value, 'control is the actual unredacted argument');
                Assert::same(array_filter($error->getTrace(), static fn (array $frame): bool => str_starts_with(\Tablo\Tests\Support\FixtureValue::string($frame['class'] ?? ''), 'Testo\\')), [], 'control stack excludes the test runner, without omitting inspector frames');
                Assert::true(TraceInspector::inspect($error, [$marker])['leaked'], 'initialized supported argument state must expose the marker');
                $result = TraceInspector::inspectArguments(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($error->getTrace(), 0)), 'args')), [$marker]);
                Assert::true($result['leaked']);
                Assert::same($result['complete'], $value !== $protected, 'uninitialized slots have explicit incomplete coverage');
            }
            $inherited = new TraceProtectedState('safe');
            (new \ReflectionProperty(TracePrivateState::class, 'marker'))->setValue($inherited, $marker);
            Assert::true(TraceInspector::inspect(self::capture($inherited), [$marker])['leaked'], 'parent private slot is not lost to a child slot of the same name');
            $nested = self::nested($protected);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($nested->getTrace(), 0)), 'args'), []);
            Assert::true(($nested->getTrace()[1]['args'][0] ?? null) === $protected);
            Assert::true(TraceInspector::inspect($nested, [$marker])['leaked'], 'a marker in a later actual frame cannot be omitted');
            $error = self::redacted($private);
            Assert::true(($error->getTrace()[0]['args'][0] ?? null) instanceof \SensitiveParameterValue);
            $result = TraceInspector::inspectArguments(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($error->getTrace(), 0)), 'args')), [$marker]);
            Assert::false($result['leaked'], 'redacted object stays opaque without unwrapping');
            Assert::true($result['complete']);
            Assert::same($result['redacted'], 1);
            $previous = self::capture($protected);
            $wrapped = new \RuntimeException('Safe wrapper', previous: $previous);
            Assert::true(TraceInspector::inspect($wrapped, [$marker])['leaked'], 'previous arguments are inspected');
        } finally { \Tablo\RuntimeIni::set('zend.exception_ignore_args', $original); }
    }

    public function inspectOpaqueObjectsAndCycles(): void
    {
        $original = ini_get('zend.exception_ignore_args');
        \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: '0');
        try {
            $marker = bin2hex(random_bytes(24));
            foreach ([static fn (): string => $marker, new \DateTimeImmutable()] as $value) {
                $result = TraceInspector::inspectArguments(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at(self::capture($value)->getTrace(), 0)), 'args')), [$marker]);
                Assert::false($result['complete'], 'opaque argument state cannot count as clean');
                Assert::true($result['opaque'] !== []);
            }
            $uninitialized = new TraceProtectedState('safe');
            Assert::false(TraceInspector::inspectArguments([$uninitialized], [$marker])['complete']);
            if (method_exists(\ReflectionClass::class, 'newLazyGhost')) {
                $initialized = false;
                $reflection = new \ReflectionClass(TraceProtectedState::class);
                $lazy = \Tablo\Tests\Support\FixtureNative::object((new \ReflectionMethod(\ReflectionClass::class, 'newLazyGhost'))->invoke($reflection,
                    static function () use (&$initialized): void { $initialized = true; }));
                Assert::false(TraceInspector::inspectArguments([$lazy], [$marker])['complete']);
                Assert::false($initialized, 'inspection must not awaken an opaque lazy carrier');
            }
            $cycle = new \stdClass();
            $cycle->value = \Tablo\Tests\Support\FixtureValue::string('safe');
            $cycle->self = $cycle;
            $result = TraceInspector::inspectArguments(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at(self::capture($cycle)->getTrace(), 0)), 'args')), [$marker]);
            Assert::true($result['complete']);
            Assert::false($result['leaked']);
            $cycle->value = $marker;
            Assert::true(TraceInspector::inspect(self::capture($cycle), [$marker])['leaked']);
            $array = [];
            $array['self'] = &$array;
            foreach ([$array, array_fill(0, count: 10_001, value: 'safe')] as $value) {
                $bounded = false;
                try { TraceInspector::inspect(self::capture($value), [$marker]); }
                catch (\RuntimeException $error) { $bounded = $error->getMessage() === 'Trace inspection bound exceeded'; }
                Assert::true($bounded, 'array cycle and node exhaustion must fail loudly');
            }
            unset($value, $array, $cycle, $error);
        } finally { \Tablo\RuntimeIni::set('zend.exception_ignore_args', $original); }
    }
}
