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
        $csrf = $this->web->authenticate();
        $sites = new SiteRepository($this->web->database());
        $state = new WorkerStateRepository($this->web->database());
        $child = null;
        try {
            foreach (['manual', 'cli', 'worker'] as $mode) {
                $input = array_replace(SiteInput::defaults(), ['name' => 'In flight homepage',
                    'url' => $this->server->base . '/barrier/', 'health_path' => '', 'version_path' => '', 'repository' => 'fixture/public']);
                $id = $sites->save($input);
                foreach (['entered', 'release'] as $file) { if (!(is_file($this->endpoints->path . '/' . $file))) { continue; }
 unlink($this->endpoints->path . '/' . $file);  }
                $command = InFlightCommand::forMode($mode, $this->web, $id, $csrf);
                $child = new WorkerProcess($this->endpoints, 'inflight-' . $mode, $command, [
                    'TABLO_DB' => $this->web->directory->path . '/test.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1', 'TABLO_WORKER_BASE' => $this->server->base]);
                $deadline = microtime(true) + 5;
                while (!is_file($this->endpoints->path . '/entered') && microtime(true) < $deadline) { clearstatcache(); usleep(20_000); }
                Assert::true(is_file($this->endpoints->path . '/entered'));
                $old = $sites->find($id);
                $sites->save(array_replace($input, ['url' => $this->server->base . '/app', 'health_path' => '/up', 'enabled' => 0]), $id);
                $sites->save($input, $id);
                Assert::true($sites->find($id)['config_revision'] > $old['config_revision']);
                file_put_contents($this->endpoints->path . '/release', data: 'release');
                if ($mode === 'worker') { $child->awaitOutput('Worker pass complete.'); $state->requestStop($state->generation()); }
                $result = $child->wait();
                (new RefusedCheckAssertions($this->web->database(), $sites))->homepage($result, $id, $mode);
                $sites->delete($id);
            }
        } finally {
            file_put_contents($this->endpoints->path . '/release', data: 'release');
            $child?->close();
            unset($sites, $state, $old);
        }
    }
}
