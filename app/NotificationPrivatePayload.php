<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationPrivatePayload
{
    public static function prepare(\PDO $db, #[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] array $claim): array
    {
            $vault->assertAvailable(KeyRequirement::forDatabase($db));
            $endpoint=$vault->decrypt($claim['settings']['endpoint_cipher']);
            $bearer=$claim['settings']['bearer_cipher']===null?'':$vault->decrypt($claim['settings']['bearer_cipher']);
            $payload=json_decode($claim['slot']['payload'],associative: true,depth: 8,flags: JSON_THROW_ON_ERROR);
            if ($claim['slot']['private_cipher']!==null) {
                $private=json_decode($vault->decrypt($claim['slot']['private_cipher']),associative: true,depth: 8,flags: JSON_THROW_ON_ERROR);
                foreach(['display_name','site_origin'] as $key) { if (!((($private[$key] ?? null) !== null)&&is_string($private[$key]))) { continue; }
$payload[$key]=$private[$key]; }
            }
            return ['endpoint'=>$endpoint,'bearer'=>$bearer,'json'=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
    }
}
