<?php
declare(strict_types=1);

namespace Tablo;

final class CurlWebhookTransport
{
    public function __construct(#[\SensitiveParameter] private readonly \Closure $addresses, #[\SensitiveParameter] private readonly \Closure $configure) {}
    public function request(#[\SensitiveParameter] string $url, #[\SensitiveParameter] string $payload,
        string $eventId, #[\SensitiveParameter] string $bearer, int $deadline): int
    {
        try { $parts=WebhookAddress::parts($url); }
        catch (ValidationException) { throw new WebhookFailure('invalid-url'); }
        WebhookPayload::validate($payload, $eventId, $bearer);
        $host=trim($parts['host'],characters: '[]'); $port=$parts['port']??443;
        $addresses=($this->addresses)($host);
        $remaining=(int)floor(($deadline-hrtime(true))/1_000_000);
        if ($remaining<1) { throw new WebhookFailure('timeout'); }
        $address=$addresses[0];
        $pin=filter_var($host,FILTER_VALIDATE_IP)?[]:["{$host}:{$port}:".(str_contains($address,':')?"[{$address}]":$address)];
        $headers=['Content-Type: application/json','Accept: application/json','Idempotency-Key: '.$eventId];
        if ($bearer!=='') { $headers[]='Authorization: Bearer '.$bearer; }
        $curl=curl_init($url); $buffer = new WebhookResponseBudget();
        try {
            ($this->configure)($curl);
            $options = CurlWebhookOptions::build($payload, $headers, $pin, $remaining, $buffer);
            curl_setopt_array($curl, $options);
            if (curl_exec($curl)===false) {
                if ($buffer->large) { throw new WebhookFailure('size'); }
                if (CurlFraming::isMalformed($curl)) { throw new WebhookFailure('invalid-response'); }
                $error=HttpFailure::fromCurl(curl_errno($curl),(int)curl_getinfo($curl,CURLINFO_OS_ERRNO));
                throw new WebhookFailure($error->reason);
            }
            $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if ($status<100 || $status>599) { throw new WebhookFailure('invalid-response'); }
            return $status;
        } finally { unset($options, $curl); }
    }


}
