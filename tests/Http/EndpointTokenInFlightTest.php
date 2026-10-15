<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\SiteRepository;
use Tablo\SiteInput;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\EndpointCheckFixture;
use Tablo\Tests\Support\EndpointFixture;
use Tablo\Tests\Support\WorkerProcess;
use Testo\Assert;
use Testo\Test;

final class EndpointTokenInFlightTest
{
    use EndpointCheckFixture;

    #[Test]
    public function realRunnersRefuseTokenAbaBetweenHttpAdmissionAndSettlement(): void
    {
        $child = null; $control = null; $sites = null;
        try {
            $this->startEndpoints();
            $web = \Tablo\Tests\Support\FixtureValue::object($this->web);
            $peer = \Tablo\Tests\Support\FixtureValue::object($this->peer);
            $endpointDirectory = \Tablo\Tests\Support\FixtureValue::object($this->endpointDirectory);
            $csrf = $web->authenticate();
            $sites = new SiteRepository($web->database());
            $control = new WorkerStateRepository($web->database());
            foreach (['manual', 'cli', 'worker'] as $mode) {
                $input = [...SiteInput::defaults(), 'name' => 'Endpoint race', 'url' => $peer->base . '/barrier/',
                    'repository' => 'fixture/public', 'health_path' => '', 'version_path' => '', 'endpoint_token' => EndpointFixture::tokenA()];
                $id = $sites->save($input);
                foreach (['entered', 'release'] as $file) { if (!(is_file($endpointDirectory->path . '/' . $file))) { continue; }
 unlink($endpointDirectory->path . '/' . $file);  }
                $command = InFlightCommand::forMode($mode, $web, $id, $csrf);
                $child = new WorkerProcess($endpointDirectory, 'endpoint-race-' . $mode, $command, [
                    'TABLO_DB' => \Tablo\Tests\Support\FixtureValue::object($web->directory)->path . '/test.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1', 'TABLO_WORKER_BASE' => $peer->base]);
                $deadline = microtime(true) + 5;
                while (!is_file($endpointDirectory->path . '/entered') && microtime(true) < $deadline) { clearstatcache(); usleep(10_000); }
                Assert::true(is_file($endpointDirectory->path . '/entered'));
                $before = $sites->find($id);
                $sites->save([...$input, 'endpoint_token' => EndpointFixture::tokenB()], $id);
                $sites->save($input, $id);
                Assert::true(\Tablo\Tests\Support\FixtureValue::row($sites->find($id))['config_revision'] > \Tablo\Tests\Support\FixtureValue::row($before)['config_revision']);
                file_put_contents($endpointDirectory->path . '/release', data: 'release');
                if ($mode === 'worker') { $child->awaitOutput('Worker pass complete.'); $control->requestStop(\Tablo\Tests\Support\FixtureValue::string($control->generation())); }
                $result = $child->wait();
                (new RefusedCheckAssertions($web->database(), $sites))->endpoint($result, $id, $mode);
                $sites->delete($id);
            }
        } finally {
            if ($this->endpointDirectory !== null) { file_put_contents($this->endpointDirectory->path . '/release', data: 'release'); }
            $child?->close(); $sites = null; $control = null; $child = null;
            $this->stopEndpoints();
        }
    }
}
