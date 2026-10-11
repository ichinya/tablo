<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class Subprocess
{
    public static function run(array $command, TemporaryDirectory $directory, array $environment, float $timeout = 8,
        #[\SensitiveParameter] string $input = '', ?string $cwd = null): array
    {
        $stdout = $directory->path . '/command.out';
        $stderr = $directory->path . '/command.err';
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'],
            2 => ['file', $stderr, 'w']], $pipes, $cwd ?? dirname(__DIR__, 2), array_replace(getenv(), $environment));
        if (!is_resource($process)) { throw new RuntimeException('Cannot start test command'); }
        try {
            if ($input !== '') { fwrite($pipes[0], $input); }
            fclose($pipes[0]);
            $deadline = microtime(true) + $timeout;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    return ['exit_code' => $status['exitcode'], 'stdout' => file_get_contents($stdout),
                        'stderr' => file_get_contents($stderr)];
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Test command timed out: ' . file_get_contents($stderr));
        } finally {
            if (is_resource($pipes[0])) { fclose($pipes[0]); }
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
                $deadline = microtime(true) + 2;
                while (proc_get_status($process)['running'] && microtime(true) < $deadline) { usleep(20000); }
                if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
            }
            proc_close($process);
        }
    }
}
