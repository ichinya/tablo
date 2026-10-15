<?php
declare(strict_types=1);

namespace Tablo;

final class HttpSizeBudget
{
    public static function header(?array &$chain, int $length): bool
    {
        if ($chain === null) { return true; }
        $chain['header_bytes'] += $length;
        if ($chain['header_bytes'] > 65_536 || $chain['header_bytes'] + $chain['body_bytes'] > 1_048_576) {
            $chain['too_large'] = true;
            return false;
        }
        return true;
    }
    public static function body(?array &$chain, int $length): bool
    {
        if ($chain === null) { return true; }
        $chain['body_bytes'] += $length;
        if ($chain['header_bytes'] + $chain['body_bytes'] > 1_048_576) { $chain['too_large'] = true; return false; }
        return true;
    }

}
