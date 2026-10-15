<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookChildWait
{
    public static function observe(WebhookProcess $child,?\Closure $stop,int $deadline): string
    {
        $code='child-frame';
            do {
                $child->status=proc_get_status($child->process);
                if (!$child->status['running']) { break; }
                if (($stop !== null && $stop()) || hrtime(true)>=$deadline) { $code='timeout'; break; }
                usleep(10_000);
            } while (true);
        return $code;
    }
}
