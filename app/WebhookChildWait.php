<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookChildWait
{
    /** @param (\Closure(): bool)|null $stop */
    public static function observe(WebhookProcess $child,?\Closure $stop,int $deadline): string
    {
        $code='child-frame';
            do {
                $child->status=proc_get_status($child->owned());
                if (!$child->status['running']) { break; }
                if (($stop !== null && $stop()) || MonotonicTime::nanoseconds()>=$deadline) { $code='timeout'; break; }
                usleep(10_000);
            } while (true);
        return $code;
    }
}
