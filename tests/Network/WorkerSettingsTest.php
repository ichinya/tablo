<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\SettingsRepository;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\WorkerProcess;
use Testo\Assert;
use Testo\Test;

final class WorkerSettingsTest
{
    #[Test]
    public function concurrentPublicWritersExposeOnlyWholeCommittedIntervals(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-settings-');
        $first = $second = null;
        try {
            $path = $directory->path . '/db.sqlite';
            $db = Database::connect($path);
            $settings = new SettingsRepository($db);
            $fixture = dirname(__DIR__) . '/fixtures/settings-process.php';
            $first = new WorkerProcess($directory, 'writer1', [PHP_BINARY, $fixture, '123'], ['TABLO_DB' => $path]);
            $second = new WorkerProcess($directory, 'writer2', [PHP_BINARY, $fixture, '456'], ['TABLO_DB' => $path]);
            $reads = 0;
            while ($first->running() || $second->running()) {
                Assert::true(in_array($settings->get(), [10, 123, 456], true));
                ++$reads;
                usleep(1000);
            }
            Assert::true($reads > 0);
            Assert::same($first->wait()['exit_code'], 0);
            Assert::same($second->wait()['exit_code'], 0);
            Assert::true(in_array($settings->get(), [123, 456], true));
        } finally { $first?->close(); $second?->close(); unset($settings, $db); $directory->close(); }
    }
}
