<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookChildInput
{
    public static function send(WebhookProcess $child,#[\SensitiveParameter] string $frame): void
    {
        // One <=2048-byte frame fits supported OS anonymous-pipe buffers.
        if (fwrite($child->pipe(0),$frame)!==strlen($frame)) { throw new WebhookFailure('child-frame'); }
        fclose($child->pipe(0)); unset($child->pipes[0]);
    }
}
