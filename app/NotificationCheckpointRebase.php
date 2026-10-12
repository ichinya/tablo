<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationCheckpointRebase
{
    public function __construct(private readonly \PDO $db) {}
    public function refresh(int $revision): void
    {
        // Preserve debounce/handled identities for this same logical channel, including legacy states.
        $after=0;
        do {
            $query=$this->db->prepare('SELECT site_id,state FROM notification_checkpoints WHERE site_id>? ORDER BY site_id LIMIT 32');
            try { $query->execute([$after]); $rows=$query->fetchAll(); } finally { $query->closeCursor(); }
            foreach ($rows as $row) {
                $after=$row['site_id']; $state=json_decode($row['state'],associative: true,depth: 16,flags: JSON_THROW_ON_ERROR);
                if ($state['channel']!==$revision) { continue; }
                $state['channel']=$revision+1;
                $query=$this->db->prepare('UPDATE notification_checkpoints SET state=? WHERE site_id=?');
                try { $query->execute([json_encode($state,JSON_THROW_ON_ERROR),$after]); } finally { $query->closeCursor(); }
            }
        } while (count($rows)===32);
    }

}
