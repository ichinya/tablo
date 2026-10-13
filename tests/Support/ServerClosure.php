<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

final class ServerClosure
{
    /** @param resource $process
     * @return array{observedExit: ?int, procClose: int} */
    public static function close(mixed $process): array
    {
        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process);
            $deadline = microtime(true) + 2;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) { break; }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            if ($status['running']) { proc_terminate($process, signal: 9); }
        }
        $observed = $status['exitcode'] === -1 ? null : $status['exitcode'];
        return ['observedExit' => $observed, 'procClose' => proc_close($process)];
    }
}
