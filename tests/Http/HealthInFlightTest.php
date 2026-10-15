<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;
use Testo\Test;
use Tablo\SiteRepository;
use Tablo\SiteInput;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\WorkerProcess;
use Tablo\Tests\Support\HealthCheckFixture;

final class HealthInFlightTest
{
    use HealthCheckFixture;

    #[Test]
    public function realInFlightHomepageResultsRefuseRestoredConfigurationInEveryRunner(): void
    {
        $web = \Tablo\Tests\Support\FixtureValue::object($this->web);
        $server = \Tablo\Tests\Support\FixtureValue::object($this->server);
        $endpoints = \Tablo\Tests\Support\FixtureValue::object($this->endpoints);
        $csrf = $web->authenticate();
        $sites = new SiteRepository($web->database());
        $state = new WorkerStateRepository($web->database());
        $child = null;
        try {
            foreach (['manual', 'cli', 'worker'] as $mode) {
                $input = array_replace(SiteInput::defaults(), ['name' => 'In flight homepage',
                    'url' => $server->base . '/barrier/', 'health_path' => '', 'version_path' => '', 'repository' => 'fixture/public']);
                $id = $sites->save($input);
                foreach (['entered', 'release'] as $file) { if (!(is_file($endpoints->path . '/' . $file))) { continue; }
 unlink($endpoints->path . '/' . $file);  }
                $command = InFlightCommand::forMode($mode, $web, $id, $csrf);
                $child = new WorkerProcess($endpoints, 'inflight-' . $mode, $command, [
                    'TABLO_DB' => \Tablo\Tests\Support\FixtureValue::object($web->directory)->path . '/test.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1', 'TABLO_WORKER_BASE' => $server->base]);
                $deadline = microtime(true) + 5;
                while (!is_file($endpoints->path . '/entered') && microtime(true) < $deadline) { clearstatcache(); usleep(20_000); }
                Assert::true(is_file($endpoints->path . '/entered'));
                $old = $sites->find($id);
                $sites->save(array_replace($input, ['url' => $server->base . '/app', 'health_path' => '/up', 'enabled' => 0]), $id);
                $sites->save($input, $id);
                Assert::true(\Tablo\Tests\Support\FixtureValue::row($sites->find($id))['config_revision'] > \Tablo\Tests\Support\FixtureValue::row($old)['config_revision']);
                file_put_contents($endpoints->path . '/release', data: 'release');
                if ($mode === 'worker') { $child->awaitOutput('Worker pass complete.'); $state->requestStop(\Tablo\Tests\Support\FixtureValue::string($state->generation())); }
                $result = $child->wait();
                (new RefusedCheckAssertions($web->database(), $sites))->homepage($result, $id, $mode);
                $sites->delete($id);
            }
        } finally {
            file_put_contents($endpoints->path . '/release', data: 'release');
            $child?->close();
            unset($sites, $state, $old);
        }
    }
}
