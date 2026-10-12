<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Flags from NotificationFlags */
final class NotificationPendingMetadata
{
    public function __construct(private readonly \PDO $db, #[\SensitiveParameter] private readonly TokenVault $vault) {}
    /** @param Flags $flags */
    public function refresh(array $flags, int $revision): void
    {

        // Never rebase an old inflight claim: the next revision must reject its ACK.
        $this->db->exec("UPDATE notification_slots SET status='cancelled',claim=NULL,lease_until=NULL,
            private_cipher=NULL WHERE status='inflight'");
        do {
            $query=\Tablo\SqlStatement::checked($this->db->prepare("SELECT n.site_id,n.event,n.config_revision,s.name,s.url,s.enabled,
                s.config_revision AS current_revision FROM notification_slots n JOIN sites s ON s.id=n.site_id
                WHERE n.status='pending' AND n.channel_revision=? ORDER BY n.site_id,n.event LIMIT 32"));
            try { $query->execute([$revision]); $rows=\Tablo\SqlRows::all($query->fetchAll(), \Tablo\SqlJoinedRow::checked(...)); } finally { $query->closeCursor(); }
            foreach ($rows as $row) {
                $valid=$row['enabled'] && $row['config_revision']===$row['current_revision'];
                $private=[];
                if ($valid && $flags['include_name']) { $private['display_name']=$row['name']; }
                if ($valid && $flags['include_url']) {
                    $parts=GetTarget::parts($row['url']);
                    $private['site_origin']=$parts['scheme'].'://'.$parts['host'].((($parts['port'] ?? null) !== null)?':'.$parts['port']:'');
                }
                $cipher=$private===[]?null:$this->vault->encrypt(JsonEncoding::encode($private));
                $query=\Tablo\SqlStatement::checked($this->db->prepare('UPDATE notification_slots SET channel_revision=?,private_cipher=?,status=? WHERE site_id=? AND event=?'));
                try { $query->execute([$revision+1,$cipher,$valid?'pending':'cancelled',$row['site_id'],$row['event']]); }
                finally { $query->closeCursor(); }
            }
        } while (count($rows)===32);
    }

}
