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
        if ($status['fixture']['configured'] && self::records($evidence->fixturePath) === []) {
            throw new RuntimeException('Required fixture observation not-observed; reached stages UNKNOWN');
        }
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
