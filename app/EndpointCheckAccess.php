<?php
declare(strict_types=1);

namespace Tablo;

final class EndpointCheckAccess
{
    private function __construct(#[\SensitiveParameter] private readonly array $requestHeaders, private readonly ?HttpFailure $failure) {}

    public static function forSite(#[\SensitiveParameter] array $site, #[\SensitiveParameter] ?TokenVault $vault): self
    {
        try {
            $encrypted = $site['endpoint_token'] ?? null;
            if ($encrypted === null || $encrypted === '') { return new self(requestHeaders: [], failure: null); }
            if (!is_string($encrypted) || $vault === null) { throw new HttpFailure('check-error'); }
            $token = EndpointCredential::validateEndpointToken($vault->decrypt($encrypted));
            if ($token === '') { throw new HttpFailure('check-error'); }
            return new self(['Authorization: Bearer ' . $token], failure: null);
        } catch (\PDOException | SharedKeyFailure $error) { throw $error; }
        catch (\Throwable $discardedCredential) { unset($discardedCredential); return new self(requestHeaders: [], failure: new HttpFailure('check-error')); }
    }

    public function assertReady(): void
    {
        if ($this->failure !== null) { throw $this->failure; }
    }

    public function headers(): array { return $this->requestHeaders; }

}
