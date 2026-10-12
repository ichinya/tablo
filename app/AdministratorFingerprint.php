<?php
declare(strict_types=1);

namespace Tablo;

final class AdministratorFingerprint
{
    public static function fingerprint(#[\SensitiveParameter] string $hash): string
    {
        return hash('sha256', "tablo/admin-session/v1\0" . $hash);
    }
}
