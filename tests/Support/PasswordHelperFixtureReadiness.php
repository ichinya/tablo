<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

/** Journal and marker readiness share the original monotonic prelaunch deadline. */
final class PasswordHelperFixtureReadiness
{
    public const BODY_MARKER = 'HELPER_FIXTURE_BODY=admitted';

    private readonly string $stdout;

    public function __construct(TemporaryDirectory $directory, private readonly string $marker)
    {
        $outputs = FixtureFiles::matches($directory->path . '/command-*.out');
        if (count($outputs) !== 1 || $marker === '') { throw new RuntimeException('Required unique owned fixture output is unavailable'); }
        $this->stdout = FixtureValue::at($outputs, 0);
    }

    /** @param array<string, string> $expectedCase */
    public function awaitEntry(string $path, array $expectedCase, int $startedNs, float $timeout = 20): void
    {
        $deadline = $startedNs + (int) ($timeout * 1_000_000_000);
        $state = 'not-observed'; $accepted = false; $markerObserved = false; $bodyMarkerObserved = false;
        try {
            do {
                $stdout = \Tablo\RequiredFile::bytes(file_get_contents($this->stdout));
                $markerObserved = str_contains($stdout, $this->marker);
                $bodyMarkerObserved = str_contains($stdout, self::BODY_MARKER);
                clearstatcache(true, $path);
                if (is_file($path)) {
                    $bytes = \Tablo\RequiredFile::bytes(file_get_contents($path, length: 32_769));
                    $state = 'incomplete';
                    if (str_ends_with($bytes, "\n")) {
                        PasswordHelperFixtureObservation::assertComplete($path, $expectedCase);
                        $entry = FixtureValue::at(PasswordHelperFixtureObservation::records($path), 0);
                        $pid = FixtureValue::integer(FixtureValue::at($entry, 'wrapperPid'));
                        if ($pid <= 0 || FixtureValue::at($entry, 'scope') !== 'fixture') { throw new RuntimeException('Invalid fixture readiness identity'); }
                        $state = 'emitted';
                        if ($markerObserved && FixtureValue::integer(hrtime(true)) < $deadline) { $accepted = true; return; }
                    }
                }
                usleep(20_000);
            } while (FixtureValue::integer(hrtime(true)) < $deadline);
            throw new RuntimeException('Fixture readiness failed; stage=fixture-readiness; state=' . $state .
                '; marker=' . ($markerObserved ? 'emitted' : 'not-observed') .
                '; body-marker-observed=' . ($bodyMarkerObserved ? 'true' : 'false') . '; reached stages UNKNOWN');
        } finally {
            $elapsedNs = FixtureValue::integer(hrtime(true)) - $startedNs;
            PasswordHelperEvidenceFiles::record(dirname($path) . '/fixture-readiness.json', [
                'stage' => 'fixture-readiness', 'accepted' => $accepted, 'budget_seconds' => $timeout, 'started_monotonic_ns' => $startedNs,
                'elapsed_ns' => $elapsedNs, 'elapsed_seconds' => $elapsedNs / 1_000_000_000, 'case' => $expectedCase,
                'fixture' => ['configured' => true, 'state' => $state],
                'stdout' => ['path' => $this->stdout, 'marker' => $this->marker, 'observed' => $markerObserved,
                    'body_marker_observed' => $bodyMarkerObserved],
                'limitation' => 'Readiness requires journal and existing stdout marker; body marker proves body entry only; absent stages and nested closure remain UNKNOWN']);
        }
    }
}
