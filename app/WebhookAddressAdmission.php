<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookAddressAdmission
{
    /**
     * @param \Closure(string): list<string> $resolver
     * @return non-empty-list<string>
     */
    public static function addresses(string $host, #[\SensitiveParameter] \Closure $resolver): array
    {
        $addresses=filter_var($host,FILTER_VALIDATE_IP)?[$host]:$resolver($host);
        if ($addresses===[]) { throw new WebhookFailure('dns'); }
        foreach ($addresses as $address) { if (!WebhookAddress::globallyRoutable($address)) { throw new WebhookFailure('ssrf'); } }
        return $addresses;
    }
}
