<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Testo\Assert;

/** @phpstan-import-type Snapshot from \Tablo\Tests\Support\NotificationAssertions */
final class NotificationRebaseAssertions
{
    /** @param Snapshot $before
     * @param Snapshot $after */
    public static function allBatches(array $before, array $after): void
    {
            foreach ($after['notification_slots'] as $i=>$row) {
                $old=\Tablo\Tests\Support\FixtureValue::at($before['notification_slots'], $i);
                foreach (['event_id','source_id','attempts','created_at','due_at','expires_at','coalesced'] as $key) { Assert::same(\Tablo\Tests\Support\FixtureValue::at($row, $key),\Tablo\Tests\Support\FixtureValue::at($old, $key)); }
                Assert::same($row['private_cipher'],null);
                Assert::same($row['status'],$old['status']==='inflight'?'cancelled':'pending');
                if ($row['status']==='pending') { Assert::same($row['channel_revision'],\Tablo\Tests\Support\FixtureValue::at($after['notification_settings'], 0)['revision']); }
            }
            foreach ($after['notification_checkpoints'] as $i=>$row) {
                $old=\Tablo\Tests\Support\FixtureValue::at($before['notification_checkpoints'], $i); $state=\Tablo\NotificationCheckpointData::channel($old['state']);
                $state['channel']=\Tablo\Tests\Support\FixtureValue::at($after['notification_settings'], 0)['revision'];
                Assert::same(\Tablo\NotificationCheckpointData::channel($row['state']),$state);
                Assert::same($row['last_history_id'],$old['last_history_id']); Assert::same($row['config_revision'],$old['config_revision']);
            }

    }
}
