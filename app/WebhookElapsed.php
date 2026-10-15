<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookElapsed
{
    public static function milliseconds(int $started): int { return (int)((hrtime(true)-$started)/1_000_000); }
}
