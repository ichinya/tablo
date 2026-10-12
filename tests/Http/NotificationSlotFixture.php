<?php
declare(strict_types=1);
namespace Tablo\Tests\Http;

use Tablo\Tests\Support\IncidentFixtures;
use Tablo\SiteRepository;
use Tablo\TokenVault;

final class NotificationSlotFixture
{
    public static function create(SiteRepository $sites, TokenVault $vault, \PDO $db): int
    {
            $cipher=$vault->encrypt('{"display_name":"hidden-cipher"}');
            $insert=$db->prepare("INSERT INTO notification_slots (site_id,event,event_id,source_id,config_revision,channel_revision,payload,
                private_cipher,created_at,expires_at,due_at,status,attempts,last_code) VALUES (?,'unavailable',?,1,0,0,?,?,1000,4600,1000,?,1,?)");
            for ($n=0;$n<56;$n++) {
                $id=$sites->save(IncidentFixtures::site());
                $insert->execute([$id,'synthetic:'.$id,'{"private":"hidden-payload"}',$cipher,$n===55?'pending':'sent',$n===55?'timeout':'sent']);
            }
        $insert=null;
        return $id;
    }
}
