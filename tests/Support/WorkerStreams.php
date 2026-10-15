<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** Complete owned file captures remain distinct from process closure and exit. */
final class WorkerStreams
{
    /** @return array{stdout: string, stderr: string} */
    public static function read(string $stdoutPath, string $stderrPath): array
    {
        $stdout = file_get_contents($stdoutPath);
        $stderr = file_get_contents($stderrPath);
        if ($stdout === false || $stderr === false) { throw new \RuntimeException('Cannot read owned worker streams.'); }
        return ['stdout' => $stdout, 'stderr' => $stderr];
    }
}
