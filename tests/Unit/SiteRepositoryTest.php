<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SiteRepository;
use Tablo\SiteInput;

use Testo\Assert;
use Testo\Test;
use Tablo\Tests\Support\UnitFixtures;

final class SiteRepositoryTest
{

    #[Test]
    public function normalizesCrudAndGuardsStaleChecks(): void
    {
        $sample = UnitFixtures::site();
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $id = $sites->save($sample);
        $site = $sites->find($id);
        Assert::true($site['repository'] === 'ichinya/tempalog' && $site['url'] === 'https://tempalog.example/', 'normalization preserves the site URL');
        $state = ['online' => 1, 'deployed_version' => '1.3.1', 'checked_at' => gmdate('c')];
        Assert::true($sites->storeCheck($site, $state), 'check not saved');
        $changed = $sample;
        $changed['url'] = 'https://other.example';
        $changed['sort_order'] = 4;
        $sites->save($changed, $id);
        Assert::true($sites->find($id)['checked_at'] === null, 'stale check survived edit');
        Assert::true(!$sites->storeCheck($site, $state), 'old in-flight check overwrote edit');
        $first = $sites->save($sample);
        Assert::true($sites->all()[0]['id'] === $first, 'sort order ignored');
        $sites->delete($id);
        Assert::true($sites->find($id) === null && count($sites->all()) === 1, 'delete removed wrong record');
    }



    #[Test]
    public function rejectsMaliciousSiteInputs(): void
    {
        $sample = UnitFixtures::site();
        foreach ([['url' => 'file:///etc/passwd'], ['url' => 'https://user:pass@example.com'],
            ['url' => 'https://example.com/#test'], ['repository' => 'https://evil.example/a/b'],
            ['repository' => 'owner/..'], ['health_path' => '//evil.example/up'], ['health_path' => '/a\\b'],
            ['version_path' => '/version#other'], ['branch' => "main\nother"], ['comparison_mode' => 'evil'],
            ['sort_order' => '3.5'], ['name' => ['array']]] as $bad) {
            (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(static fn () => SiteInput::normalize(array_replace($sample, $bad)))))->verify('invalid input accepted: ' . json_encode($bad));
        }
    }
}
