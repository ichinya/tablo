<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationEnqueue
{
    public function __construct(private readonly NotificationProjectionStore $store, #[\SensitiveParameter] private readonly TokenVault $vault) {}
    public function enqueue(NotificationProjectionContext $context, string $event, int $sourceId, ?string $comparison): void
    {
        $row = $context->row; $site = $context->site; $settings = $context->settings; $wall = $context->wall;
        $key = $settings['installation_id'].':'.$event.':'.$sourceId;
        $payload=['schema'=>'tablo.notification.v1','event_id'=>$key,'event'=>$event,'subject_id'=>$row['site_id'],'observed_at'=>$row['checked_at']];
        if ($comparison !== null) { $payload['comparison']=$comparison; }
        $private=[];
        if ($settings['include_name']) { $private['display_name']=$site['name']; }
        if ($settings['include_url']) {
            $parts=parse_url($site['url']);
            $private['site_origin']=$parts['scheme'].'://'.$parts['host'].((($parts['port'] ?? null) !== null) ? ':'.$parts['port'] : '');
        }
        $cipher=null;
        if ($private !== []) { $this->vault->assertAvailable(KeyRequirement::Existing); $cipher=$this->vault->encrypt(json_encode($private,JSON_THROW_ON_ERROR)); }
        $this->store->execute("INSERT INTO notification_slots (site_id,event,event_id,source_id,config_revision,channel_revision,payload,private_cipher,
            created_at,expires_at,due_at,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending')
            ON CONFLICT(site_id,event) DO UPDATE SET event_id=excluded.event_id,source_id=excluded.source_id,
            config_revision=excluded.config_revision,channel_revision=excluded.channel_revision,payload=excluded.payload,
            private_cipher=excluded.private_cipher,created_at=excluded.created_at,expires_at=excluded.expires_at,due_at=excluded.due_at,
            coalesced=notification_slots.coalesced+CASE WHEN notification_slots.status IN ('pending','inflight') THEN 1 ELSE 0 END,
            status='pending',attempts=0,claim=NULL,lease_until=NULL,last_code=NULL,http_status=NULL,acknowledged_at=NULL
            WHERE notification_slots.event_id <> excluded.event_id",
            [$row['site_id'],$event,$key,$sourceId,$row['config_revision'],$settings['revision'],json_encode($payload,JSON_THROW_ON_ERROR),$cipher,$wall,$wall+3600,$wall]);
    }
}
