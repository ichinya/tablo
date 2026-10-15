<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class WorkerStartupAdmissionTest
{


    #[Test]
    public function actualCliRejectsMemoryOpenFailuresMalformedStartupAndInvalidUseWithoutPrivateOutput(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-startup-');
        try {
            $entry = dirname(__DIR__, levels: 2) . '/bin/worker.php';
            foreach ([':memory:', $directory->path . '/synthetic-private-db.sqlite'] as $path) {
                if ($path !== ':memory:') {
                    $db = Database::connect($path);
                    mkdir($path . '.worker.lock');
                    unset($db);
                }
                $result = Subprocess::run([PHP_BINARY, $entry], $directory, ['TABLO_DB' => $path]);
                Assert::same($result['exit_code'], 1);
                Assert::same($result['stdout'], '');
                Assert::same($result['stderr'], "Worker failed. Check installation configuration and local storage.\n");
            }
            Assert::same(Subprocess::run([PHP_BINARY, $entry, '--invalid'], $directory, ['TABLO_DB' => ':memory:'])['exit_code'], 64);
        } finally { unset($db); $directory->close(); }
    }
}
