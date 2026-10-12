<?php
declare(strict_types=1);

namespace Tablo;

final class CheckRunReason
{
    public static function reason(int $failures,array $snapshot): string
    {
        if ($failures !== 0) { return 'attention'; }
        return $snapshot === [] ? 'empty' : 'complete';
    }
}
