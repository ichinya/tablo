<?php
declare(strict_types=1);
namespace Tablo\Tests\Network;


use Tablo\Tests\Support\IncidentFixtures as F;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;

final class NotificationSiteFixture
{
    public function __construct(private readonly WebFixture $web) {}

    public function create(string $csrf, string $base): void
    {
            foreach(['First','Next'] as $name){Assert::same($this->web->request('/sites/new',array_replace(F::site(),['_csrf'=>$csrf,'name'=>$name,
                'url'=>$base,'repository'=>'fixture/public','health_path'=>'/up','enabled'=>'1']))['status'],303);}

    }
}
