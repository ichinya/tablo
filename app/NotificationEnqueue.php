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
            $parts=GetTarget::parts($site['url']);
            $private['site_origin']=$parts['scheme'].'://'.$parts['host'].((($parts['port'] ?? null) !== null) ? ':'.$parts['port'] : '');
        }
        $cipher=null;
        if ($private !== []) { $this->vault->assertAvailable(KeyRequirement::Existing); $cipher=$this->vault->encrypt(JsonEncoding::encode($private)); }
        $this->store->enqueue([$row['site_id'], $event, $key, $sourceId, $row['config_revision'], $settings['revision'],
            JsonEncoding::encode($payload), $cipher, $wall, $wall + 3600, $wall]);
    }
}
