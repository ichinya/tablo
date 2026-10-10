<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\TraceInspector;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

class TracePrivateState
{
    public function __construct(private string $marker) {}
}

final class TraceProtectedState extends TracePrivateState
{
    public string $uninitialized;
    public function __construct(protected readonly string $marker) { parent::__construct('safe'); }
}

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
        ini_set('zend.exception_ignore_args', '0');
        try {
            $marker = bin2hex(random_bytes(24));
            $private = new TracePrivateState($marker);
            $protected = new TraceProtectedState($marker);
            foreach ([$marker, ['nested' => [$marker]], [$marker => 'safe'], (object) ['value' => $marker], $private, $protected] as $value) {
                $error = self::capture($value);
                Assert::true(($error->getTrace()[0]['args'][0] ?? null) === $value, 'control is the actual unredacted argument');
                Assert::same(array_filter($error->getTrace(), static fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'Testo\\')), [], 'control stack excludes the test runner, without omitting inspector frames');
                Assert::true(TraceInspector::inspect($error, [$marker])['leaked'], 'initialized supported argument state must expose the marker');
                $result = TraceInspector::inspectArguments($error->getTrace()[0]['args'], [$marker]);
                Assert::true($result['leaked']);
                Assert::true($result['complete'], 'this frame has only supported argument state');
            }
            $inherited = new TraceProtectedState('safe');
            (new \ReflectionProperty(TracePrivateState::class, 'marker'))->setValue($inherited, $marker);
            Assert::true(TraceInspector::inspect(self::capture($inherited), [$marker])['leaked'], 'parent private slot is not lost to a child slot of the same name');
            $nested = self::nested($protected);
            Assert::same($nested->getTrace()[0]['args'], []);
            Assert::true(($nested->getTrace()[1]['args'][0] ?? null) === $protected);
            Assert::true(TraceInspector::inspect($nested, [$marker])['leaked'], 'a marker in a later actual frame cannot be omitted');
            $error = self::redacted($private);
            Assert::true(($error->getTrace()[0]['args'][0] ?? null) instanceof \SensitiveParameterValue);
            $result = TraceInspector::inspectArguments($error->getTrace()[0]['args'], [$marker]);
            Assert::false($result['leaked'], 'redacted object stays opaque without unwrapping');
            Assert::true($result['complete']);
            Assert::same($result['redacted'], 1);
            $previous = self::capture($protected);
            $wrapped = new \RuntimeException('Safe wrapper', previous: $previous);
            Assert::true(TraceInspector::inspect($wrapped, [$marker])['leaked'], 'previous arguments are inspected');
        } finally { ini_set('zend.exception_ignore_args', $original); }
    }

    public function inspectOpaqueObjectsAndCycles(): void
    {
        $original = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $marker = bin2hex(random_bytes(24));
            foreach ([static fn (): string => $marker, new \DateTimeImmutable()] as $value) {
                $result = TraceInspector::inspectArguments(self::capture($value)->getTrace()[0]['args'], [$marker]);
                Assert::false($result['complete'], 'opaque argument state cannot count as clean');
                Assert::true($result['opaque'] !== []);
            }
            $cycle = (object) ['value' => 'safe'];
            $cycle->self = $cycle;
            $result = TraceInspector::inspectArguments(self::capture($cycle)->getTrace()[0]['args'], [$marker]);
            Assert::true($result['complete']);
            Assert::false($result['leaked']);
            $cycle->value = $marker;
            Assert::true(TraceInspector::inspect(self::capture($cycle), [$marker])['leaked']);
            $array = [];
            $array['self'] = &$array;
            foreach ([$array, array_fill(0, 10_001, 'safe')] as $value) {
                $bounded = false;
                try { TraceInspector::inspect(self::capture($value), [$marker]); }
                catch (\RuntimeException $error) { $bounded = $error->getMessage() === 'Trace inspection bound exceeded'; }
                Assert::true($bounded, 'array cycle and node exhaustion must fail loudly');
            }
            unset($value, $array, $cycle, $error);
        } finally { ini_set('zend.exception_ignore_args', $original); }
    }
}
