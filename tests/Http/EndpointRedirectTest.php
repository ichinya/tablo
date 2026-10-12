<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\SiteRepository;
use Tablo\SiteInput;
use Tablo\Tests\Support\HealthCheckFixture;
use Testo\Assert;
use Testo\Test;

final class EndpointRedirectTest
{
    use HealthCheckFixture;

    #[Test]
    public function manualCliAndWorkerPersistFinalResultsAndUnknownChainFailures(): void
    {
        $csrf = \Tablo\Tests\Support\FixtureValue::object($this->web)->authenticate();
        $db = \Tablo\Tests\Support\FixtureValue::object($this->web)->database(); $sites = new SiteRepository($db);
        try {
            $input = [...SiteInput::defaults(), 'name' => 'Redirect endpoint', 'url' => \Tablo\Tests\Support\FixtureValue::object($this->server)->base,
                'repository' => 'fixture/public', 'health_path' => '/redirect-health', 'version_path' => '/redirect-version'];
            $id = $sites->save($input);
            Assert::same($id, 1);
            foreach (['manual', 'cli', 'worker'] as $mode) {
                $sites->save($input, $id);
                $this->check($mode, $csrf, 0);
                $row = \Tablo\Tests\Support\FixtureValue::row($sites->find($id));
                Assert::same($row['online'], 1); Assert::same($row['health_http_status'], 200);
                Assert::same($row['deployed_version'], '1.3.1'); Assert::same($row['health_error_code'], null);
                $sites->save([...$input, 'health_path' => '/redirect-missing'], $id);
                $this->check($mode, $csrf, 1);
                $row = \Tablo\Tests\Support\FixtureValue::row($sites->find($id));
                Assert::same($row['online'], null); Assert::same($row['health_http_status'], null);
                Assert::same($row['health_error_code'], 'redirect-location-missing');
                Assert::same($row['deployed_version'], '1.3.1');
            }
        } finally { unset($sites, $db); }
    }
}
