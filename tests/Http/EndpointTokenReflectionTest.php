<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\HttpFailure;
use Tablo\SiteRepository;
use Tablo\SiteInput;
use Tablo\Tests\Support\EndpointCheckFixture;
use Tablo\Tests\Support\EndpointFixture;
use Testo\Assert;
use Testo\Test;

final class EndpointTokenReflectionTest
{
    use EndpointCheckFixture;

    #[Test]
    public function reflectedCredentialCannotReachSqliteOrDashboardThroughAnyRunner(): void
    {
        $sites = null;
        try {
            $this->startEndpoints();
            $csrf = $this->web->authenticate();
            $sites = new SiteRepository($this->web->database());
            foreach (['manual', 'cli', 'worker'] as $mode) {
                $id = $sites->save([...SiteInput::defaults(), 'name' => 'Reflected endpoint',
                    'url' => $this->peer->base, 'repository' => 'fixture/public', 'health_path' => '/up',
                    'version_path' => '/version?reflect=1', 'endpoint_token' => EndpointFixture::tokenA()]);
                $this->runEndpointCheck($mode, $id, $csrf, 1);
                $site = $sites->find($id);
                Assert::same($site['online'], 1);
                Assert::same($site['open_issues'], 2);
                Assert::true($site['deployed_version'] === null, 'reflected version is never stored');
                Assert::true($site['deployed_commit'] === null, 'reflected commit is never stored');
                Assert::same($site['last_error'], 'Version: ' . HttpFailure::MESSAGES['invalid-response']);
                $dashboard = $this->web->request('/');
                Assert::same($dashboard['status'], 200);
                Assert::false(str_contains($dashboard['body'], EndpointFixture::tokenA()));
                $sites->delete($id);
            }
        } finally { $sites = null; $this->stopEndpoints(); }
    }
}
