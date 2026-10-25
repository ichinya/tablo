<?php
declare(strict_types=1);

namespace Tablo;

final class EndpointCheckAccess
{
    /** @param list<string> $requestHeaders */
    private function __construct(#[\SensitiveParameter] private readonly array $requestHeaders, private readonly ?HttpFailure $failure) {}

    public static function forSite(#[\SensitiveParameter] array $site, #[\SensitiveParameter] ?TokenVault $vault): self
    {
        try {
            return self::credential($site['endpoint_token'] ?? null, $vault);
        } catch (\PDOException | DatabaseFailure | SharedKeyFailure $error) { throw $error; }
        catch (\Throwable $discardedCredential) { unset($discardedCredential); return new self(requestHeaders: [], failure: new HttpFailure('check-error')); }
    }

    private static function credential(#[\SensitiveParameter] mixed $encrypted, #[\SensitiveParameter] ?TokenVault $vault): self
    {
            if ($encrypted === null || $encrypted === '') { return new self(requestHeaders: [], failure: null); }
            if (!is_string($encrypted) || $vault === null) { throw new HttpFailure('check-error'); }
            $token = EndpointCredential::validateEndpointToken($vault->decrypt($encrypted));
            if ($token === '') { throw new HttpFailure('check-error'); }
            return new self(['Authorization: Bearer ' . $token], failure: null);
    }

    public function assertReady(): void
    {
        if ($this->failure !== null) { throw $this->failure; }
    }

    /** @return list<string> */
    public function headers(): array { return $this->requestHeaders; }

}
