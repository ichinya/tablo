<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

final class EndpointPrivacyChild
{
    // Raw fatal stdout/stderr are captured only in memory, then reduced to safe
    // booleans. No marker values, header arrays, frames or raw bodies reach disk.
    /** @return array{exit: int, stdout_leaked: bool, stderr_leaked: bool, stderr_bytes: int, records: list<array>} */
    public static function run(string $mode): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args=0', '-d', 'zend.exception_string_param_max_len=8192',
            '-d', 'display_errors=1', '-d', 'log_errors=0', dirname(__DIR__) . '/fixtures/EndpointNativeControl.php', $mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Cannot start bounded privacy control.'); }
        fclose(\Tablo\Tests\Support\FixturePipes::pipe($pipes, 0));
        stream_set_blocking(\Tablo\Tests\Support\FixturePipes::pipe($pipes, 1), enable: false);
        stream_set_blocking(\Tablo\Tests\Support\FixturePipes::pipe($pipes, 2), enable: false);
        $stderr = ''; $stdout = '';
        $exit = null;
        $deadline = microtime(true) + 8;
        try {
            do {
                $stdout .= \Tablo\RequiredFile::bytes(stream_get_contents(\Tablo\Tests\Support\FixturePipes::pipe($pipes, 1)));
                $stderr .= \Tablo\RequiredFile::bytes(stream_get_contents(\Tablo\Tests\Support\FixturePipes::pipe($pipes, 2)));
                $status = proc_get_status($process);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            if ($exit === null) { throw new \RuntimeException('Privacy child exceeded its bound.'); }
            $stdout .= \Tablo\RequiredFile::bytes(stream_get_contents(\Tablo\Tests\Support\FixturePipes::pipe($pipes, 1)));
            $stderr .= \Tablo\RequiredFile::bytes(stream_get_contents(\Tablo\Tests\Support\FixturePipes::pipe($pipes, 2)));
            $marker = EndpointFixture::tokenA();
            return ['exit' => $exit, 'stdout_leaked' => str_contains($stdout, $marker), 'stderr_leaked' => str_contains($stderr, $marker),
                'stderr_bytes' => strlen($stderr), 'records' => $exit === 0 ? \Tablo\SqlRows::all(\Tablo\JsonRecord::decode($stdout, 32), \Tablo\JsonRecord::checked(...)) : []];
        } finally {
            if ($exit === null && proc_get_status($process)['running']) { proc_terminate($process, signal: 9); }
            fclose(\Tablo\Tests\Support\FixturePipes::pipe($pipes, 1)); fclose(\Tablo\Tests\Support\FixturePipes::pipe($pipes, 2)); proc_close($process);
            unset($stdout, $stderr);
        }
    }
}
