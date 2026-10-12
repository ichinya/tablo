<?php
declare(strict_types=1);

namespace Tablo {
    function getenv(string $name): string|false
    {
        $value = \getenv($name);
        if ($name === 'TABLO_DB' && $value === \getenv('TABLO_RACE_FILE') && is_file(\Tablo\Tests\Support\FixtureValue::string($value))) {
            // Owned synthetic target is removed exactly as the real resolver
            // returns it, before the actual PDO open. No substitute connection.
            unlink(\Tablo\Tests\Support\FixtureValue::string($value));
        }
        return $value;
    }
}
namespace {
    require_once __DIR__ . '/../Support/FixtureValue.php';
    require dirname(__DIR__, levels: 2) . '/bin/admin-password.php';
}
