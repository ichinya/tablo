<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\SiteRepository;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\EndpointCheckFixture;
use Tablo\Tests\Support\EndpointFixture;
use Tablo\Tests\Support\HealthCheckDiagnostics;
use Tablo\Tests\Support\WorkerProcess;
use Testo\Assert;
use Testo\Test;

final class EndpointTokenInFlightTest
{
    use EndpointCheckFixture;

    #[Test]
    public function realRunnersRefuseTokenAbaBetweenHttpAdmissionAndSettlement(): void
    {
        $sites = $control = $child = null;
        try {
            $this->startEndpoints();
            $csrf = $this->web->authenticate();
            $sites = new SiteRepository($this->web->database());
            $control = new WorkerStateRepository($this->web->database());
            foreach (['manual', 'cli', 'worker'] as $mode) {
                $input = [...SiteRepository::defaults(), 'name' => 'Endpoint race', 'url' => $this->peer->base . '/barrier/',
                    'repository' => 'fixture/public', 'health_path' => '', 'version_path' => '', 'endpoint_token' => EndpointFixture::tokenA()];
                $id = $sites->save($input);
                foreach (['entered', 'release'] as $file) { if (is_file($this->endpointDirectory->path . '/' . $file)) { unlink($this->endpointDirectory->path . '/' . $file); } }
                $root = dirname(__DIR__, 2);
                $command = $mode === 'manual' ? [PHP_BINARY, '-r',
                    '$c=curl_init($argv[1]);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(["_csrf"=>$argv[3]]),CURLOPT_COOKIEFILE=>$argv[2],CURLOPT_PROXY=>"",CURLOPT_TIMEOUT=>10]);curl_exec($c);exit(curl_getinfo($c,CURLINFO_RESPONSE_CODE)===303?0:1);',
                    $this->web->server->base . '/sites/' . $id . '/check', $this->web->directory->path . '/cookies', $csrf]
                    : ($mode === 'cli' ? [PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/cli-github-fixture.php', $root . '/bin/check.php'] : [PHP_BINARY, $root . '/tests/fixtures/worker-process.php']);
                $child = new WorkerProcess($this->endpointDirectory, 'endpoint-race-' . $mode, $command, [
                    'TABLO_DB' => $this->web->directory->path . '/test.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1', 'TABLO_WORKER_BASE' => $this->peer->base]);
                $deadline = microtime(true) + 5;
                while (!is_file($this->endpointDirectory->path . '/entered') && microtime(true) < $deadline) { clearstatcache(); usleep(10000); }
                Assert::true(is_file($this->endpointDirectory->path . '/entered'));
                $before = $sites->find($id);
                $sites->save([...$input, 'endpoint_token' => EndpointFixture::tokenB()], $id);
                $sites->save($input, $id);
                Assert::true($sites->find($id)['config_revision'] > $before['config_revision']);
                file_put_contents($this->endpointDirectory->path . '/release', 'release');
                if ($mode === 'worker') { $child->awaitOutput('Worker pass complete.'); $control->requestStop($control->generation()); }
                $result = $child->wait();
                Assert::same($result['exit_code'], $mode === 'cli' ? 1 : 0, HealthCheckDiagnostics::capture($result, $this->web->database()));
                Assert::same(strlen($result['stderr']), 0);
                Assert::same($sites->find($id)['checked_at'], null);
                Assert::same((int) $this->web->database()->query('SELECT count(*) FROM worker_progress WHERE site_id=' . $id)->fetchColumn(), 0);
                $sites->delete($id);
            }
        } finally {
            if ($this->endpointDirectory !== null) { file_put_contents($this->endpointDirectory->path . '/release', 'release'); }
            $child?->close(); $child = $control = $sites = null;
            $this->stopEndpoints();
        }
    }
}
