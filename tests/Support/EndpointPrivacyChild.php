<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

final class EndpointPrivacyChild
{
    // Raw fatal stdout/stderr are captured only in memory, then reduced to safe
    // booleans. No marker values, header arrays, frames or raw bodies reach disk.
    public static function run(string $mode): array
    {
        $process = proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args=0', '-d', 'zend.exception_string_param_max_len=8192',
            '-d', 'display_errors=1', '-d', 'log_errors=0', dirname(__DIR__) . '/fixtures/EndpointNativeControl.php', $mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Cannot start bounded privacy control.'); }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], enable: false);
        stream_set_blocking($pipes[2], enable: false);
        $stderr = ''; $stdout = '';
        $exit = null;
        $deadline = microtime(true) + 8;
        try {
            do {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            if ($exit === null) { throw new \RuntimeException('Privacy child exceeded its bound.'); }
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $marker = EndpointFixture::tokenA();
            return ['exit' => $exit, 'stdout_leaked' => str_contains($stdout, $marker), 'stderr_leaked' => str_contains($stderr, $marker),
                'stderr_bytes' => strlen($stderr), 'records' => $exit === 0 ? json_decode($stdout, associative: true, depth: 32, flags: JSON_THROW_ON_ERROR) : []];
        } finally {
            if ($exit === null && proc_get_status($process)['running']) { proc_terminate($process, signal: 9); }
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
            unset($stdout, $stderr);
        }
    }
}
