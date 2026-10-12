<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Claim from NotificationOutbox */
final class NotificationPrivatePayload
{
    /**
     * @param Claim $claim
     * @return array{'bearer': string, 'endpoint': string, 'json': string}
     */
    public static function prepare(\PDO $db, #[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] array $claim): array
    {
            $vault->assertAvailable(KeyRequirement::forDatabase($db));
            $endpointCipher = $claim['settings']['endpoint_cipher'];
            if ($endpointCipher === null) { throw new \TypeError('Notification endpoint is unavailable.'); }
            $endpoint=$vault->decrypt($endpointCipher);
            $bearer=$claim['settings']['bearer_cipher']===null?'':$vault->decrypt($claim['settings']['bearer_cipher']);
            $payload=JsonRecord::decode($claim['slot']['payload'], 8);
            if ($claim['slot']['private_cipher']!==null) {
                $private=JsonRecord::decode($vault->decrypt($claim['slot']['private_cipher']), 8);
                foreach(['display_name','site_origin'] as $key) { if (!((($private[$key] ?? null) !== null)&&is_string($private[$key]))) { continue; }
$payload[$key]=$private[$key]; }
            }
            return ['endpoint'=>$endpoint,'bearer'=>$bearer,'json'=>JsonEncoding::encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
    }
}
