<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationCipher
{

    public static function prepare(#[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] ?string $current, #[\SensitiveParameter] string $submitted, int $remove): ?string
    {
        // The form parser admits only the exact integer flag domain 0 or 1.
        if ($remove === 1) { return null; }
        if ($submitted === '') { return $current; }
        return $vault->encrypt($submitted);
    }

}
