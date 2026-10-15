<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use Tablo\TokenVault;
use Tablo\Tests\Support\TemporaryDirectory;

/** @phpstan-import-type SchemaRows from InjectedVaultFixture */
final class InjectedVaultSeed
{
    /** @return array{string, string, string, string, string, string, SchemaRows} */
    public static function create(TemporaryDirectory $directory, string $selection): array
    {
                $path = $directory->path . '/' . $selection . '.sqlite';
                $key = $directory->path . '/' . $selection . '.key';
                $wrong = $directory->path . '/' . $selection . '.wrong';
                $originalBytes = random_bytes(32);
                $wrongBytes = random_bytes(32);
                file_put_contents($key, $originalBytes);
                file_put_contents($wrong, $wrongBytes);
                $vault = new TokenVault($key, true);
                $cipher = $vault->encrypt(bin2hex(random_bytes(24)));
                $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $schema = InjectedVaultFixture::populate($db, $cipher);
                $vault = null; $db = null;
        return [$path, $key, $wrong, $originalBytes, $wrongBytes, $cipher, $schema];
    }
}
