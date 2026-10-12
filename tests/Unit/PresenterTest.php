<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\Presenter;
use Tablo\SiteRepository;

use Testo\Assert;
use Testo\Test;
use Tablo\Tests\Support\UnitFixtures;

final class PresenterTest
{
    #[Test]
    public function presentsReleaseCommitPausedAndStaleStates(): void
    {
        $sample = UnitFixtures::site();
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $id = $sites->save($sample);
        $site = $sites->find($id);
        Assert::true(Presenter::site($site)['comparison_tone'] === 'muted', 'unknown shown current');
        $site['deployed_version'] = '1.3.2'; $site['latest_release'] = 'v1.3.2';
        Assert::true(Presenter::site($site)['comparison_tone'] === 'green', 'v prefix not normalized');
        $site['deployed_version'] = '1.3.1';
        Assert::true(Presenter::site($site)['comparison'] === 'Доступно обновление', 'older release');
        $site['deployed_version'] = '1.4.0';
        Assert::true(Presenter::site($site)['comparison'] === 'Версия новее релиза', 'newer shown update');
        $site['comparison_mode'] = 'branch'; $site['deployed_commit'] = 'a61de82'; $site['latest_commit'] = 'a61de82' . str_repeat('0', times: 33);
        Assert::true(Presenter::site($site)['comparison_tone'] === 'green', 'short SHA not accepted');
        Assert::true(Presenter::site($site)['deployed_label'] === 'a61de82', 'branch comparison displayed a release instead of SHA');
        $site['enabled'] = 0;
        Assert::true(Presenter::site($site)['status'] === 'На паузе', 'disabled not paused');
        $site['enabled'] = 1; $site['checked_at'] = gmdate('c', time() - 1800);
        Assert::true(Presenter::site($site)['stale'], 'stale data not marked');
    }
}
