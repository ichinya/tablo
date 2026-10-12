<?php
declare(strict_types=1);

namespace Tablo;

final class HttpSizeBudget
{
    /**
     * @param array{deadline: int, body_bytes: int, header_bytes: int, too_large: bool}|null $chain
     */
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
    /**
     * @param array{deadline: int, body_bytes: int, header_bytes: int, too_large: bool}|null $chain
     */
    public static function body(?array &$chain, int $length): bool
    {
        if ($chain === null) { return true; }
        $chain['body_bytes'] += $length;
        if ($chain['header_bytes'] + $chain['body_bytes'] > 1_048_576) { $chain['too_large'] = true; return false; }
        return true;
    }

}
