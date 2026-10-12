<?php
declare(strict_types=1);
namespace Tablo\Tests\Http;

use Testo\Assert;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\IncidentFixtures;

final class NotificationDeliveryAssertions
{
    public function __construct(private readonly \PDO $db, private readonly SiteRepository $sites, private readonly TokenVault $vault, private readonly \Tablo\NotificationOutbox $outbox) {}

    public function delivered(array $opening, int &$wall): void
    {
        [$offset, $id, $claim] = $opening;
        $metadata = $offset !== 0;
                $wall+=60; Assert::true(IncidentFixtures::accept($this->sites,$id,1,gmdate('Y-m-d\TH:i:s\Z',1_767_225_720+$offset)));
                $recovery=$this->db->query("SELECT * FROM notification_slots WHERE event='recovery'")->fetch();
                Assert::true(is_array($recovery)); Assert::same($recovery['source_id'],$claim['slot']['source_id']);
                if ($metadata) { Assert::same(json_decode($this->vault->decrypt($recovery['private_cipher']),associative: true),['display_name'=>'Service']); }
                $recoveryClaim=$this->outbox->claim($wall); Assert::true($this->outbox->acknowledge($recoveryClaim,['code'=>'sent','http_status'=>204],$wall));

    }
}
