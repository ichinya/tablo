<?php
declare(strict_types=1);

namespace Tablo;



/** Pure login transport policy. Forwarded aliases never establish authority. */
final class ClientAddress
{
    public const MAX_BYTES = 4096;
    public const MAX_HOPS = 32;
    public const CONFIG_ERROR = 'Invalid TABLO_TRUSTED_PROXIES configuration.';
    public const HEADER_ERROR = 'Invalid trusted proxy address chain.';

    /** @var list<string> */
    private readonly array $proxies;






    public function __construct(#[\SensitiveParameter] string|false $configuration = false)
    {
        $this->proxies = ProxyConfiguration::parse($configuration);
    }

    public function resolve(array $server): string
    {
        return TrustedProxyChain::resolve($server, $this->proxies);
    }
}
