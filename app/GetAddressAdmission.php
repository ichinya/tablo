<?php
declare(strict_types=1);

namespace Tablo;

final class GetAddressAdmission
{
    public function __construct(private readonly bool $allowPrivate, #[\SensitiveParameter] private readonly \Closure $resolver) {}
    public function pin(#[\SensitiveParameter] array $parts, ?array $chain): array
    {
        $host = trim($parts['host'], characters: '[]');
        $resolve = [];
        // Explicit private-network permission retains native OS/cURL hostname
        // resolution (including hosts/localhost), on a fresh handle each hop.
        // Default public requests still validate every answer and pin one.
        if (!$this->allowPrivate) {
            $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
            if (!$addresses) {
                throw new HttpFailure('dns');
            }
            foreach ($addresses as $address) {
                if ($chain !== null ? !EndpointTarget::isPublicAddress($address)
                    : (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                    || str_starts_with(strtolower($address), '::ffff:'))) {
                    throw new HttpFailure('ssrf');
                }
            }
            if (!filter_var($host, FILTER_VALIDATE_IP)) {
                $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
                $address = $addresses[0];
                $resolve[] = "{$host}:{$port}:" . (str_contains($address, ':') ? "[{$address}]" : $address);
            }
        }
        return $resolve;
    }

}
