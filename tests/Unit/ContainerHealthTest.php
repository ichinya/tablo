<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\ContainerHealth;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class ContainerHealthTest
{
    #[Test]
    public function loadsProductionDefinitionsWithoutOpeningConfiguredStorage(): void
    {
        $directory = new TemporaryDirectory('tablo-container-health-unit-');
        $previous = getenv('TABLO_DB');
        try {
            putenv('TABLO_DB=' . $directory->path . '/missing/tablo.sqlite');
            Assert::true(ContainerHealth::available());
            Assert::same(scandir($directory->path), ['.', '..']);
        } finally {
            $previous === false ? putenv('TABLO_DB') : putenv('TABLO_DB=' . $previous);
            $directory->close();
        }
    }
}
