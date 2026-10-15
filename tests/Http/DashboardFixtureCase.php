<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Lifecycle\BeforeTest;
use Testo\Lifecycle\AfterTest;
use Tablo\Tests\Support\WebFixture;

abstract class DashboardFixtureCase
{
    protected ?WebFixture $web = null;

    #[BeforeTest]
    public function start(): void { $this->web = new WebFixture(); }

    #[AfterTest]
    public function stop(): void
    {
        $this->web?->close();
        $this->web = null;
    }

    protected function site(string $csrf): array
    {
        return ['_csrf' => $csrf, 'name' => 'Fixture project', 'url' => 'https://example.com',
            'repository' => 'https://github.com/ichinya/lekalo', 'branch' => 'main', 'health_path' => '/up',
            'version_path' => '/version', 'comparison_mode' => 'release', 'enabled' => '1', 'sort_order' => '0'];
    }
}
