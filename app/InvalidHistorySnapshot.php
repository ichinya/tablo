<?php
declare(strict_types=1);

namespace Tablo;

final class InvalidHistorySnapshot
{
    public static function invalid(): never
    {
        throw new \InvalidArgumentException('Invalid check history snapshot.');
    }
}
