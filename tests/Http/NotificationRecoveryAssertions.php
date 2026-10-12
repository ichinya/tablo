<?php
declare(strict_types=1);
namespace Tablo\Tests\Http;

use Testo\Assert;

/**
 * @phpstan-import-type Record from \Tablo\SqlNotificationSettingsActivationIdBearerCipherBlockedRow as Settings
 */
final class NotificationRecoveryAssertions
{
    public function __construct(private readonly \PDO $db) {}

    /**
     * @param Settings $old
     */
    public function retained(array $old, int $revisionIncrement): void
    {
                $new=\Tablo\Tests\Support\FixtureValue::fetched(\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($this->db->query('SELECT * FROM notification_settings'))->fetch(), \Tablo\SqlNotificationSettingsActivationIdBearerCipherBlockedRow::checked(...)));
                Assert::same($new['activation_id'],$old['activation_id']); Assert::same($new['endpoint_cipher'],$old['endpoint_cipher']);
                Assert::same($new['revision'],$old['revision']+$revisionIncrement);

    }

}
