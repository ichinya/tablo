<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

use Tablo\Tests\Support\NotificationFixture as F;

final class NotificationAssertions
{
    public static function notificationRows(F $f): array
    {
        $rows=[];
        foreach (['notification_settings','notification_slots','notification_checkpoints'] as $table) { $rows[$table]=$f->db->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(); }
        return $rows;
    }

}
