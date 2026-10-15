<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Testo\Assert;

final class NotificationRebaseAssertions
{
    public static function allBatches(array $before, array $after): void
    {
            foreach ($after['notification_slots'] as $i=>$row) {
                $old=$before['notification_slots'][$i];
                foreach (['event_id','source_id','attempts','created_at','due_at','expires_at','coalesced'] as $key) { Assert::same($row[$key],$old[$key]); }
                Assert::same($row['private_cipher'],null);
                Assert::same($row['status'],$old['status']==='inflight'?'cancelled':'pending');
                if ($row['status']==='pending') { Assert::same($row['channel_revision'],$after['notification_settings'][0]['revision']); }
            }
            foreach ($after['notification_checkpoints'] as $i=>$row) {
                $old=$before['notification_checkpoints'][$i]; $state=json_decode($old['state'],associative: true);
                $state['channel']=$after['notification_settings'][0]['revision'];
                Assert::same(json_decode($row['state'],associative: true),$state);
                Assert::same($row['last_history_id'],$old['last_history_id']); Assert::same($row['config_revision'],$old['config_revision']);
            }

    }
}
