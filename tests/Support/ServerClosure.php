<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

final class ServerClosure
{
    /** @param resource $process
     * @return array{observedExit: ?int, procClose: int, observedPid: int, rootExitObserved: bool, signaled: bool, termSignal: ?int, platform: string, terminationRequests: list<array{signal: int, accepted: bool}>} */
    public static function close(mixed $process): array
    {
        $status = proc_get_status($process); $requests = [];
        foreach ([15, 9] as $signal) {
            if (!$status['running']) { break; }
            $requests[] = ['signal' => $signal, 'accepted' => proc_terminate($process, $signal)];
            $deadline = microtime(true) + 2;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) { break; }
                usleep(20_000);
            } while (microtime(true) < $deadline);
        }
        if ($status['running'] || $status['pid'] < 1) { throw new \RuntimeException('Direct HTTP child closure unconfirmed.'); }
        $signaled = $status['signaled'];
        $observed = $status['exitcode'] === -1 || $signaled ? null : $status['exitcode'];
        $closed = proc_close($process);
        return ['observedExit' => $observed, 'procClose' => $closed, 'observedPid' => $status['pid'], 'rootExitObserved' => true,
            'signaled' => $signaled, 'termSignal' => $signaled && $status['termsig'] > 0 ? $status['termsig'] : null,
            'platform' => PHP_OS_FAMILY, 'terminationRequests' => $requests];
    }
}
