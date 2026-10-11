<?php
declare(strict_types=1);
namespace Tablo;

use PDO;

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
            if ($this->db->inTransaction()) { return ['code'=>'storage-busy']; }
            $settings=(new NotificationSettings($this->db,$this->vault))->get();
            if ($settings['blocked']) { return ['code'=>'stop-unverified']; }
            if (!$settings['enabled'] || !$settings['endpoint_present']
                || !($settings['unavailable']||$settings['recovery']||$settings['version_lag'])) { return ['code'=>'off']; }
            if ($stop!==null && $stop()) { return ['code'=>'stopped']; }
            if (!$lock->acquire($this->db)) { return ['code'=>'busy']; }
            $claim=$outbox->claim($now??time());
            if ($claim===false) { return ['code'=>'idle']; }
            $this->vault->assertAvailable(SiteRepository::hasEstablishedKeyState($this->db));
            $endpoint=$this->vault->decrypt($claim['settings']['endpoint_cipher']);
            $bearer=$claim['settings']['bearer_cipher']===null?'':$this->vault->decrypt($claim['settings']['bearer_cipher']);
            $payload=json_decode($claim['slot']['payload'],true,8,JSON_THROW_ON_ERROR);
            if ($claim['slot']['private_cipher']!==null) {
                $private=json_decode($this->vault->decrypt($claim['slot']['private_cipher']),true,8,JSON_THROW_ON_ERROR);
                foreach(['display_name','site_origin'] as $key) { if(isset($private[$key])&&is_string($private[$key])){$payload[$key]=$private[$key];} }
            }
            $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $result=($this->supervisor??new WebhookSupervisor())->attempt($endpoint,$bearer,$json,$stop);
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
        } finally { if (!in_array($lock,self::$blockedLocks,true)) { $lock->close(); } }
    }
}
