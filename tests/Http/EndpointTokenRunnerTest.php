<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\SiteRepository;
use Tablo\SiteInput;
use Tablo\Tests\Support\EndpointCheckFixture;
use Tablo\Tests\Support\EndpointFixture;
use Testo\Assert;
use Testo\Test;

final class EndpointTokenRunnerTest
{
    use EndpointCheckFixture;

    #[Test]
    public function realManualActualCliAndPeriodicWorkerUseTheSameEndpointCredential(): void
    {
        $sites = null;
        try {
            $this->startEndpoints();
            $csrf = $this->web->authenticate();
            $sites = new SiteRepository($this->web->database());
            foreach (['manual', 'cli', 'worker'] as $mode) {
                foreach ([true, false] as $authorized) {
                    $input = [...SiteInput::defaults(), 'name' => 'Endpoint runner', 'url' => $this->peer->base . '/home///',
                        'repository' => 'fixture/public', 'health_path' => $authorized ? '/up' : '', 'version_path' => $authorized ? '/version' : '',
                        'endpoint_token' => $authorized ? EndpointFixture::tokenA() : ''];
                    $id = $sites->save($input);
                    $before = count($this->endpointRows());
                    $this->runEndpointCheck($mode, $id, $csrf);
                    $site = $sites->find($id);
                    Assert::same($site['online'], 1);
                    Assert::true($site['checked_at'] !== null);
                    Assert::same($site['deployed_version'], $authorized ? '1.0.0' : null);
                    Assert::same($site['open_issues'], 2);
                    Assert::same($site['open_prs'], 2);
                    $endpoints = array_values(array_filter(array_slice($this->endpointRows(), $before), static fn (array $row): bool => !$row['git']));
                    Assert::same(count($endpoints), $authorized ? 2 : 1);
                    foreach ($endpoints as $row) { Assert::same($row['a'], $authorized); Assert::same($row['present'], $authorized); }
                    if (!$authorized) { Assert::same($endpoints[0]['path'], '/home///'); }
                    if ($mode === 'worker') {
                        Assert::same((int) $this->web->database()->query('SELECT config_revision FROM worker_progress WHERE site_id=' . $id)->fetchColumn(), $site['config_revision']);
                        Assert::true((int) $this->web->database()->query('SELECT fairness_turn FROM worker_runtime')->fetchColumn() > 0);
                    }
                    $sites->delete($id);
                }
            }
            foreach ($this->endpointRows() as $row) { if (!($row['git'])) { continue; }
 Assert::false($row['a'] || $row['b']);  }
            foreach (file($this->web->directory->path . '/github-calls.log.endpoint-audit', FILE_IGNORE_NEW_LINES) as $line) {
                Assert::false(json_decode($line, associative: true, depth: 4, flags: JSON_THROW_ON_ERROR)['endpoint_leaked']);
            }
            Assert::false(str_contains($this->peer->diagnostics(), EndpointFixture::tokenA()));
        } finally { $sites = null; $this->stopEndpoints(); }
    }
}
