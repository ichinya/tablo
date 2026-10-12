<?php
declare(strict_types=1);
namespace Tablo;

use PDO;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class NotificationDelivery
{
    private static array $blockedLocks=[];
    private readonly TokenVault $vault;
    public function __construct(private readonly PDO $db, #[\SensitiveParameter] ?TokenVault $vault=null,
        private readonly ?WebhookSupervisor $supervisor=null) { $this->vault=$vault??TokenVault::forDatabase($db); }

    // Independent failure isolation; one unlocked network attempt after accepted commits.
    public function runOne(?\Closure $stop=null, ?int $now=null): array
    {
        $lock=new NotificationLock(); $claim=false; $outbox=new NotificationOutbox($this->db);
        try {
            $refusal = NotificationDeliveryAdmission::refuse($this->db,$this->vault,$stop);
            if ($refusal !== null) { return ['code'=>$refusal]; }
            if (!$lock->acquire($this->db)) { return ['code'=>'busy']; }
            $claim=$outbox->claim($now??time());
            if ($claim===false) { return ['code'=>'idle']; }
            $request = NotificationPrivatePayload::prepare($this->db,$this->vault,$claim);
            $result=($this->supervisor??new WebhookSupervisor())->attempt($request['endpoint'],$request['bearer'],$request['json'],$stop);
            if (!$result['stopped']) {
                self::$blockedLocks[]=$lock;
                $this->db->exec('UPDATE notification_settings SET blocked=1 WHERE id=1');
                return ['code'=>'stop-unverified'];
            }
            try { if (!$outbox->acknowledge($claim,$result,$now??time())) { return ['code'=>'stale-ack']; } }
            catch (\Throwable) { return ['code'=>'ack-storage']; }
            return array_intersect_key($result,array_flip(['code','http_status','elapsed_ms']));
        } catch (\Throwable) {
            // No notification error changes an accepted check, or reruns site work.
            if ($claim!==false) {
                try { $outbox->acknowledge($claim,['code'=>'network'],$now??time()); } catch (\Throwable) { return ['code'=>'ack-storage']; }
            }
            return ['code'=>'delivery-error'];
        } finally { if (!in_array($lock,self::$blockedLocks,strict: true)) { $lock->close(); } }
    }
}
