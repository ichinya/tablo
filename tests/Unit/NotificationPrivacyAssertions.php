<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Tablo\Tests\Support\NotificationFixture as F;
use Testo\Assert;

/**
 * @phpstan-import-type Snapshot from \Tablo\Tests\Support\NotificationAssertions
 * @phpstan-import-type Record from \Tablo\SqlNotificationSlotsAcknowledgedAtAttemptsChannelRevisionRow as Slot
 * @phpstan-import-type Record from \Tablo\SqlNotificationSettingsActivationIdBearerCipherBlockedRow as Settings
 */
final class NotificationPrivacyAssertions
{
    /** @param Snapshot $before
     * @param Slot $slot
     * @param Settings $settings */
    public static function refreshed(F $f, array $before, array $slot, array $settings, string $mode): void
    {
                $previous=\Tablo\Tests\Support\FixtureValue::at($before['notification_settings'], 0);
                Assert::same($settings['activation_id'],$previous['activation_id']);
                Assert::same($settings['revision'],$previous['revision']+1);
                Assert::same($settings['endpoint_cipher'],$previous['endpoint_cipher']);
                $updated=\Tablo\Tests\Support\FixtureValue::at($f->slots(), 0); Assert::same($updated['event_id'],$slot['event_id']);
                Assert::same($updated['attempts'],$slot['attempts']);
                if (in_array($mode,['pending','unattempted'],strict: true)) {
                    Assert::same($updated['status'],'pending'); Assert::same($updated['channel_revision'],$settings['revision']);
                    Assert::same(json_decode(\Tablo\Tests\Support\FixtureValue::object($f->vault)->decrypt(\Tablo\Tests\Support\FixtureValue::string($updated['private_cipher'])),associative: true),['site_origin'=>'https://example.com']);
                    Assert::same($updated['due_at'],$slot['due_at']); Assert::same($updated['expires_at'],$slot['expires_at']);
                }
                if ($mode==='inflight') { Assert::same($updated['status'],'cancelled'); Assert::same($updated['private_cipher'],null); }

    }
}
