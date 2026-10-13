<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class SessionAutomaticTest
{
    #[Test]
    public function nativeAutomaticHandlerBoundaryOnlyMovesObservedTimeAndCannotReviveExpiredSession(): void
    {
        $directory = new TemporaryDirectory('tablo-session-automatic-');
        try {
            $result = Subprocess::run([PHP_BINARY, dirname(__DIR__) . '/fixtures/session-automatic.php', $directory->path], $directory, []);
            Assert::same($result['exit_code'], 0);
            $data = \Tablo\JsonRecord::decode($result['stdout']);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($data, 'observations'), [
                ['epoch' => 1010, 'status' => 200, 'start' => 1000, 'activity' => 1000, 'observed' => 1010],
                ['epoch' => 1050, 'status' => 200, 'start' => 1000, 'activity' => 1000, 'observed' => 1050],
                ['epoch' => 1119, 'status' => 200, 'start' => 1000, 'activity' => 1000, 'observed' => 1119],
                ['epoch' => 1120, 'status' => 401, 'authenticated' => false],
            ]);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($data, 'afterIncrease'), 401);
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $directory->close(); }
    }
}
