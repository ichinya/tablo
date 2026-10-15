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
            $web = \Tablo\Tests\Support\FixtureValue::object($this->web);
            $peer = \Tablo\Tests\Support\FixtureValue::object($this->peer);
            $csrf = $web->authenticate();
            $sites = new SiteRepository($web->database());
            foreach (['manual', 'cli', 'worker'] as $mode) {
                foreach ([true, false] as $authorized) {
                    $input = [...SiteInput::defaults(), 'name' => 'Endpoint runner', 'url' => $peer->base . '/home///',
                        'repository' => 'fixture/public', 'health_path' => $authorized ? '/up' : '', 'version_path' => $authorized ? '/version' : '',
                        'endpoint_token' => $authorized ? EndpointFixture::tokenA() : ''];
                    $id = $sites->save($input);
                    $before = count($this->endpointRows());
                    $this->runEndpointCheck($mode, $id, $csrf);
                    $site = \Tablo\Tests\Support\FixtureValue::row($sites->find($id));
                    Assert::same($site['online'], 1);
                    Assert::true($site['checked_at'] !== null);
                    Assert::same($site['deployed_version'], $authorized ? '1.0.0' : null);
                    Assert::same($site['open_issues'], 2);
                    Assert::same($site['open_prs'], 2);
                    $endpoints = array_values(array_filter(array_slice($this->endpointRows(), $before), static fn (array $row): bool => !\Tablo\Tests\Support\FixtureJson::boolean($row, 'git')));
                    Assert::same(count($endpoints), $authorized ? 2 : 1);
                    foreach ($endpoints as $row) { Assert::same($row['a'], $authorized); Assert::same($row['present'], $authorized); }
                    if (!$authorized) { Assert::same(\Tablo\Tests\Support\FixtureValue::at($endpoints, 0)['path'], '/home///'); }
                    if ($mode === 'worker') {
                        Assert::same((int) \Tablo\SqlStatement::checked($web->database()->query('SELECT config_revision FROM worker_progress WHERE site_id=' . $id))->fetchColumn(), $site['config_revision']);
                        Assert::true((int) \Tablo\SqlStatement::checked($web->database()->query('SELECT fairness_turn FROM worker_runtime'))->fetchColumn() > 0);
                    }
                    $sites->delete($id);
                }
            }
            foreach ($this->endpointRows() as $row) { if (!($row['git'])) { continue; }
 Assert::false($row['a'] || $row['b']);  }
            foreach (\Tablo\Tests\Support\FixtureFiles::lines(\Tablo\Tests\Support\FixtureValue::object($web->directory)->path . '/github-calls.log.endpoint-audit', FILE_IGNORE_NEW_LINES) as $line) {
                Assert::false(\Tablo\Tests\Support\FixtureJson::boolean(\Tablo\JsonRecord::decode($line, 4), 'endpoint_leaked'));
            }
            Assert::false(str_contains($peer->diagnostics(), EndpointFixture::tokenA()));
        } finally { $sites = null; $this->stopEndpoints(); }
    }
}
