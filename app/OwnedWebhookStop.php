<?php
declare(strict_types=1);

namespace Tablo;

final class OwnedWebhookStop
{
    /** @return (array{'command': string, 'exitcode': int, 'pid': int, 'running': bool, 'signaled': bool, 'stopped': bool, 'stopsig': int, 'termsig': int})|(array{'exitcode': int, 'running': true, 'signaled': false, 'termsig': int}) */
    public static function stopOwned(#[\SensitiveParameter] mixed $process): array
    {
        try {
            if (!is_resource($process)) { return ['running'=>true,'exitcode'=>-1,'signaled'=>false,'termsig'=>0]; }
            $status=proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
                $grace=MonotonicTime::nanoseconds()+1_000_000_000;
                while ($status['running'] && MonotonicTime::nanoseconds()<$grace) {
                    usleep(10_000); $status=proc_get_status($process);
                }
            }
            return $status;
        } catch (\Throwable) {
            // Failed observation is uncertainty, never evidence that custody ended.
            return ['running'=>true,'exitcode'=>-1,'signaled'=>false,'termsig'=>0];
        }
    }
}
