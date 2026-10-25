<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;
use Throwable;

/** @phpstan-import-type Result from Subprocess */
final class DashboardPortObserver
{
    public const BUDGET_SECONDS = 20.0;

    /** @param list<string> $command
     * @return Result */
    public static function run(array $command, TemporaryDirectory $directory, float $budget = self::BUDGET_SECONDS): array
    {
        if (!is_finite($budget) || $budget <= 0 || $budget > self::BUDGET_SECONDS) { throw new RuntimeException('Invalid observer-only budget'); }
        $started = self::monotonic();
        $deadline = $started + $budget * 1_000_000_000;
        $child = null;
        try {
            self::timing($directory, $started, $deadline, 'launch-requested');
            $child = new Subprocess($command, $directory, environment: []);
            $child->send('');
            $remaining = ($deadline - self::monotonic()) / 1_000_000_000;
            if ($remaining <= 0) { throw new RuntimeException('Windows observer exceeded its launch-to-exit budget'); }
            $result = $child->finish($remaining);
            if (self::monotonic() > $deadline) { throw new RuntimeException('Windows observer completed after its launch-to-exit budget'); }
            self::timing($directory, $started, $deadline, 'native-completion');
            return $result;
        } catch (Throwable $primary) {
            $directory->retainFailure($primary);
            $child?->close($primary);
            try { self::timing($directory, $started, $deadline, 'failed'); }
            catch (Throwable $cleanup) { $directory->recordCleanupFailure($cleanup); }
            throw $primary;
        }
    }

    private static function monotonic(): int|float
    {
        $time = hrtime(true);
        if ($time === false) { throw new RuntimeException('Observer monotonic clock is unavailable'); }
        return $time;
    }

    private static function timing(TemporaryDirectory $directory, int|float $started, int|float $deadline, string $stage): void
    {
        $now = self::monotonic();
        $bytes = \Tablo\RequiredFile::bytes(json_encode(['stage' => $stage, 'started_ns' => $started, 'deadline_ns' => $deadline,
            'observed_ns' => $now, 'elapsed_ms' => ($now - $started) / 1_000_000, 'budget_ms' => ($deadline - $started) / 1_000_000,
            'qualification' => 'One parent monotonic acceptance deadline around existing Subprocess APIs; shared completion/closure internals unchanged; termination is never accepted as timely completion'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        if (file_put_contents($directory->path . '/budget-' . $stage . '.json', $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Cannot retain Windows observer timing');
        }
    }
}
