<?php
declare(strict_types=1);

namespace Tablo {
    function getenv(string $name): string|false
    {
        $value = \getenv($name);
        if ($name === 'TABLO_DB' && $value === \getenv('TABLO_RACE_FILE') && is_file($value)) {
            // Owned synthetic target is removed exactly as the real resolver
            // returns it, before the actual PDO open. No substitute connection.
            unlink($value);
        }
        return $value;
    }
}
namespace { require dirname(__DIR__, 2) . '/bin/admin-password.php'; }
