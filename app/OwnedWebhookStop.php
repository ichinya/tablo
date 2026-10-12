<?php
declare(strict_types=1);

namespace Tablo;

final class OwnedWebhookStop
{
    public static function stopOwned(#[\SensitiveParameter] mixed $process): array
    {
        try {
            $status=proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
                $grace=hrtime(true)+1_000_000_000;
                while ($status['running'] && hrtime(true)<$grace) {
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
