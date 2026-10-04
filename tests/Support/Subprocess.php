<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class Subprocess
{
    public static function run(array $command, TemporaryDirectory $directory, array $environment, float $timeout = 8): array
    {
        $stdout = $directory->path . '/command.out';
        $stderr = $directory->path . '/command.err';
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'],
            2 => ['file', $stderr, 'w']], $pipes, dirname(__DIR__, levels: 2), array_replace(getenv(), $environment));
        if (!is_resource($process)) { throw new RuntimeException('Cannot start test command'); }
        fclose($pipes[0]);
        try {
            $deadline = microtime(true) + $timeout;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    return ['exit_code' => $status['exitcode'], 'stdout' => file_get_contents($stdout),
                        'stderr' => file_get_contents($stderr)];
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Test command timed out: ' . file_get_contents($stderr));
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
                $deadline = microtime(true) + 2;
                while (proc_get_status($process)['running'] && microtime(true) < $deadline) { usleep(20_000); }
                if (proc_get_status($process)['running']) { proc_terminate($process, signal: 9); }
            }
            proc_close($process);
        }
    }
}
