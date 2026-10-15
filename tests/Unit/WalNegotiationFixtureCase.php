<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use Tablo\Database;




use Throwable;

abstract class WalNegotiationFixtureCase
{
    /** @param class-string $owner */
    protected static function negotiate(PDO $db, string $owner = Database::class): ?\Throwable
    {
        try { (new \ReflectionMethod($owner, 'ensureWal'))->invoke(null, $db); }
        catch (\Throwable $error) { return $error; }
        return null;
    }

}
