<?php
declare(strict_types=1);
namespace Tablo\Tests\Http;

use Testo\Assert;

final class NotificationRecoveryAssertions
{
    public function __construct(private readonly \PDO $db) {}

    public function retained(array $old, int $revisionIncrement): void
    {
                $new=$this->db->query('SELECT * FROM notification_settings')->fetch();
                Assert::same($new['activation_id'],$old['activation_id']); Assert::same($new['endpoint_cipher'],$old['endpoint_cipher']);
                Assert::same($new['revision'],$old['revision']+$revisionIncrement);

    }

}
