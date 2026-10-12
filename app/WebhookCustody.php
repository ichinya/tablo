<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookCustody
{
    private static array $unreleased=[];
    private static array $active=[];
    private static bool $shutdownRegistered=false;
    public static function blocked(): bool { return self::$unreleased !== []; }
    public static function active(WebhookProcess $child): void
    {
        self::$active=[$child->process,$child->pipes];
        if (!self::$shutdownRegistered) { register_shutdown_function(self::shutdown(...)); self::$shutdownRegistered=true; }
    }
    public static function detach(WebhookProcess $child): void
    {
        self::$unreleased[]=[$child->process,$child->pipes]; $child->process=null; $child->pipes=[];
    }
    public static function finish(WebhookProcess $child): void
    {
        if (is_resource($child->process)) {
            $child->status=OwnedWebhookStop::stopOwned($child->process);
            if ($child->status['running']) { self::$unreleased[]=[$child->process,$child->pipes]; }
            if (!$child->status['running']) { ResourceStreams::close($child->pipes); proc_close($child->process); }
        }
        self::$active=[];
    }
    private static function shutdown(): void
    {
        // Only our single child; no process-name or process-tree cleanup.
        foreach (self::$active === [] ? self::$unreleased : [self::$active] as [$process,$pipes]) {
            if (!is_resource($process)) { continue; }
            try {
                $status=OwnedWebhookStop::stopOwned($process);
                if (!$status['running']) {
                    \Tablo\ResourceStreams::close($pipes);
                    proc_close($process);
                }
            } catch (\Throwable $discardedCleanup) { /* No secret-bearing output during shutdown. */  unset($discardedCleanup); }
        }
    }
}
