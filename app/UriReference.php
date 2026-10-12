<?php
declare(strict_types=1);

namespace Tablo;

final class UriReference
{
    public static function validate(#[\SensitiveParameter] array|false $parts): void
    {
            if (!is_array($parts) || (($parts['fragment'] ?? null) !== null)
                || (($parts['user'] ?? null) !== null) || (($parts['pass'] ?? null) !== null)) { throw new HttpFailure('invalid-url'); }
    }

}
