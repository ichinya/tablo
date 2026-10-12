<?php
declare(strict_types=1);
namespace Tablo\Tests\Http;

use Testo\Assert;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\IncidentFixtures;

/**
 * @phpstan-import-type Claim from \Tablo\NotificationOutbox
 */
final class NotificationDeliveryAssertions
{
    public function __construct(private readonly \PDO $db, private readonly SiteRepository $sites, private readonly TokenVault $vault, private readonly \Tablo\NotificationOutbox $outbox) {}

    /**
     * @param array{int, int, Claim} $opening
     */
    public function delivered(array $opening, int &$wall): void
    {
        [$offset, $id, $claim] = $opening;
        $metadata = $offset !== 0;
                $wall+=60; Assert::true(IncidentFixtures::accept($this->sites,$id,1,gmdate('Y-m-d\TH:i:s\Z',1_767_225_720+$offset)));
                $recovery=\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($this->db->query("SELECT * FROM notification_slots WHERE event='recovery'"))->fetch(), \Tablo\SqlNotificationSlotsAcknowledgedAtAttemptsChannelRevisionRow::checked(...));
                Assert::true(is_array($recovery)); $recovery=\Tablo\Tests\Support\FixtureValue::fetched($recovery); Assert::same($recovery['source_id'],$claim['slot']['source_id']);
                if ($metadata) { Assert::same(json_decode($this->vault->decrypt(\Tablo\Tests\Support\FixtureValue::string($recovery['private_cipher'])),associative: true),['display_name'=>'Service']); }
                $recoveryClaim=\Tablo\Tests\Support\FixtureValue::fetched($this->outbox->claim($wall)); Assert::true($this->outbox->acknowledge($recoveryClaim,['code'=>'sent','http_status'=>204],$wall));

    }
}
