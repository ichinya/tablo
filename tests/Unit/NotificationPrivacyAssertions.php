<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Tablo\Tests\Support\NotificationFixture as F;
use Testo\Assert;

final class NotificationPrivacyAssertions
{
    public static function refreshed(F $f, array $before, array $slot, array $settings, string $mode): void
    {
                Assert::same($settings['activation_id'],$before['notification_settings'][0]['activation_id']);
                Assert::same($settings['revision'],$before['notification_settings'][0]['revision']+1);
                Assert::same($settings['endpoint_cipher'],$before['notification_settings'][0]['endpoint_cipher']);
                $updated=$f->slots()[0]; Assert::same($updated['event_id'],$slot['event_id']);
                Assert::same($updated['attempts'],$slot['attempts']);
                if (in_array($mode,['pending','unattempted'],strict: true)) {
                    Assert::same($updated['status'],'pending'); Assert::same($updated['channel_revision'],$settings['revision']);
                    Assert::same(json_decode($f->vault->decrypt($updated['private_cipher']),associative: true),['site_origin'=>'https://example.com']);
                    Assert::same($updated['due_at'],$slot['due_at']); Assert::same($updated['expires_at'],$slot['expires_at']);
                }
                if ($mode==='inflight') { Assert::same($updated['status'],'cancelled'); Assert::same($updated['private_cipher'],null); }

    }
}
