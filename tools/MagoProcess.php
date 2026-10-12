<?php
declare(strict_types=1);

namespace Tablo\Tools;

use RuntimeException;

require_once __DIR__ . '/MagoProcessClosure.php';

/** Direct argv and complete independent streams for the small tool boundary. */
final class MagoProcess
{
    /**
     * @param list<string> $command
     * @return array{exit: int, stdout: string, stderr: string, pid: int, initial_exit: int, proc_close: int}
     */
    public static function capture(array $command, string $root): array
    {
        if ($root === '') { throw new RuntimeException('Mago workspace is required.'); }
        $stdout = tmpfile();
        $stderr = tmpfile();
        if (!is_resource($stdout) || !is_resource($stderr)) {
            self::close($stdout); self::close($stderr);
            throw new RuntimeException('Cannot reserve Mago capture streams.');
        }
        $process = null;
        $pipes = [];
        try {
            $environment = getenv();
            foreach (array_keys($environment) as $name) {
                if (!(str_starts_with(strtoupper($name), 'MAGO_'))) { continue; }
 unset($environment[$name]);
            }
            $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            $process = proc_open($command, [0 => ['file', $null, 'r'], 1 => $stdout, 2 => $stderr], $pipes,
                cwd: $root, env_vars: $environment, options: ['bypass_shell' => true]);
            if (!is_resource($process)) { throw new RuntimeException('Cannot start Mago process.'); }
            $initial = proc_get_status($process);
            $closed = proc_close($process);
            $process = null;
            $exit = self::exitCode($initial, $closed);
            if (!rewind($stdout) || !rewind($stderr)) { throw new RuntimeException('Cannot rewind Mago captures.'); }
            $out = stream_get_contents($stdout);
            $err = stream_get_contents($stderr);
            if ($out === false || $err === false) { throw new RuntimeException('Cannot read Mago captures.'); }
            return ['exit' => $exit, 'stdout' => $out, 'stderr' => $err, 'pid' => $initial['pid'],
                'initial_exit' => $initial['exitcode'], 'proc_close' => $closed];
        } finally {
            MagoProcessClosure::close($process);
            fclose($stdout); fclose($stderr);
        }
    }

    /** @param array{running: bool, exitcode: int, ...} $initial */
    private static function exitCode(array $initial, int $closed): int
    {
        if (!$initial['running'] && $initial['exitcode'] !== -1) { return $initial['exitcode']; }
        if ($closed !== -1) { return $closed; }
        throw new RuntimeException('Mago child exit unavailable.');
    }

    private static function close(mixed $stream): void
    {
        if (is_resource($stream)) { fclose($stream); }
    }

}
