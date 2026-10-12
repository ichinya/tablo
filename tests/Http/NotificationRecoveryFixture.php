<?php
declare(strict_types=1);
namespace Tablo\Tests\Http;

use Tablo\Tests\Support\IncidentFixtures;
use Tablo\NotificationOutbox;
use Tablo\SiteRepository;
use Testo\Assert;

/**
 * @phpstan-import-type Claim from \Tablo\NotificationOutbox
 */
final class NotificationRecoveryFixture
{
    public function __construct(private readonly SiteRepository $sites, private readonly \PDO $db) {}

    /**
     * @return array{NotificationOutbox, Claim}
     */
    public function opening(int $id, int &$wall, int $offset): array
    {
                $wall=1000+$offset; Assert::true(IncidentFixtures::accept($this->sites,$id,0,gmdate('Y-m-d\TH:i:s\Z',1_767_225_600+$offset)));
                $wall+=60; Assert::true(IncidentFixtures::accept($this->sites,$id,0,gmdate('Y-m-d\TH:i:s\Z',1_767_225_660+$offset)));
                $outbox=new NotificationOutbox($this->db); $claim=\Tablo\Tests\Support\FixtureValue::fetched($outbox->claim($wall)); Assert::true($outbox->acknowledge($claim,['code'=>'sent','http_status'=>204],$wall));
        return [$outbox, $claim];
    }
}
