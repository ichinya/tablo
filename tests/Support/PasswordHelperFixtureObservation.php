<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;
use Tablo\JsonRecord;

/** Fixture and native callback evidence have independent scopes; absence proves no child stage. */
final class PasswordHelperFixtureObservation
{
    public const STAGES = ['fixture-entry', 'before-encoding-initialization', 'after-encoding-initialization',
        'before-resolver-setup', 'after-resolver-setup', 'before-callback-setup', 'after-callback-setup',
        'before-helper-call', 'after-helper-call'];

    /** @return array{fixture: array{configured: bool, state: string}, native: array{configured: bool, state: string}, limitation: string} */
    public static function status(PasswordHelperEvidence $evidence, string $stderr): array
    {
        $configured = $evidence->case->identity['phase'] !== 'negative-control';
        $fixture = ['configured' => false, 'state' => 'not-configured'];
        $native = ['configured' => false, 'state' => 'not-configured'];
        if ($configured) {
            $fixture = ['configured' => true, 'state' => self::state($evidence->fixturePath, $stderr,
                marker: 'HELPER_FIXTURE_OBSERVER_FAILURE=append-failed')];
            $native = ['configured' => true, 'state' => self::state($evidence->nativePath, $stderr,
                marker: 'HELPER_OBSERVER_FAILURE=')];
        }
        return ['fixture' => $fixture, 'native' => $native,
            'limitation' => 'not-observed is not NOTREACHED; native callback state is separate; nested closure UNKNOWN'];
    }

    /** Only a successfully constructed test directory can own its cleanup callable. */
    public static function cleanupDirectory(?TemporaryDirectory $directory): void
    {
        if ($directory !== null) { $directory->cleanup($directory->close(...)); }
    }

    private static function state(string $path, string $stderr, string $marker): string
    {
        if (str_contains($stderr, $marker)) { return 'append-failed'; }
        clearstatcache(true, $path);
        if (!is_file($path)) { return 'not-observed'; }
        $bytes = filesize($path);
        if ($bytes === false) { throw new RuntimeException('Cannot inspect fixture observer journal'); }
        return $bytes > 0 ? 'emitted' : 'not-observed';
    }

    public static function assertCollected(PasswordHelperEvidence $evidence): void
    {
        $stderr = $evidence->path . '/final.stderr';
        $status = self::status($evidence, is_file($stderr) ? \Tablo\RequiredFile::bytes(file_get_contents($stderr)) : '');
        if ($status['fixture']['state'] === 'append-failed') {
            throw new RuntimeException('Synthetic fixture observer collector failed; original child outcome retained');
        }
        if ($status['fixture']['configured']) {
            self::assertComplete($evidence->fixturePath, self::caseMetadata(basename($evidence->path), $evidence->case->identity));
        }
    }

    /** @param array{encoding: string, scenario: string, phase: string, ...} $identity
     * @return array<string, string> */
    public static function caseMetadata(string $id, array $identity): array
    {
        return ['id' => $id, 'encoding' => $identity['encoding'], 'scenario' => $identity['scenario'],
            'phase' => $identity['phase'], 'mode' => 'Native',
            'control' => match ($identity['phase']) { 'fixture-control' => 'pre-helper-stall', default => 'none' }];
    }

    /** A nonempty prefix is insufficient when the fixed stderr failure signal is unavailable.
     * @param array<string, string> $expectedCase */
    public static function assertComplete(string $path, array $expectedCase): void
    {
        $records = self::records($path);
        $stages = match (FixtureValue::at($expectedCase, 'control')) { 'pre-helper-stall' => ['fixture-entry'], default => self::STAGES };
        $order = ['records' => count($records), 'stages' => array_column($records, 'stage'), 'sequences' => array_column($records, 'sequence')];
        if ($order !== ['records' => count($stages), 'stages' => $stages, 'sequences' => range(1, count($stages))]) {
            throw new RuntimeException('Required fixture observation not-observed or incomplete; reached stages UNKNOWN');
        }
        $cases = array_map(self::normalizedCase(...), array_column($records, 'case'));
        if ($cases !== array_fill(0, count($stages), self::normalizedCase($expectedCase))) {
            throw new RuntimeException('Invalid fixture observation case identity');
        }
    }

    private static function normalizedCase(mixed $value): array
    {
        $case = JsonRecord::checked($value);
        ksort($case);
        return $case;
    }

    /** Bounded journal read; metadata is never copied from arbitrary exceptions or inputs.
     * @return list<array<array-key, mixed>> */
    public static function records(string $path): array
    {
        if (!is_file($path)) { return []; }
        $bytes = \Tablo\RequiredFile::bytes(file_get_contents($path, length: 32_769));
        if (strlen($bytes) > 32_768 || !str_ends_with($bytes, "\n")) { throw new RuntimeException('Invalid bounded fixture observation'); }
        $records = [];
        foreach (explode("\n", rtrim($bytes, characters: "\r\n")) as $line) {
            $records[] = JsonRecord::checked(json_decode($line, associative: true, depth: 8, flags: JSON_THROW_ON_ERROR));
        }
        return $records;
    }
}
