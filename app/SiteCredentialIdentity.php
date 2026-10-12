<?php
declare(strict_types=1);

namespace Tablo;

final class SiteCredentialIdentity
{
    public function __construct(private readonly \PDO $db,private readonly GitTokenRepository $savedTokens, #[\SensitiveParameter] private readonly TokenVault $tokens) {}
    public function credentialScope(#[\SensitiveParameter] array $input, #[\SensitiveParameter] ?array $site): string
    {
        $selected = (new SelectedSiteToken($this->savedTokens))->selectedToken($input, $site);
        if ($selected !== null) { return 'saved:' . $selected; }
        if ((new SiteTokenResolver($this->savedTokens,$this->tokens))->resolveToken($input, $site) === '') { return 'anonymous'; }
        return (($site['id'] ?? null) !== null) ? 'site:' . (int) $site['id'] : 'authenticated';
    }
    public function equivalentCredentialScope(#[\SensitiveParameter] string $token): ?string
    {
        if ($token === '') { return null; }
        // Even expired key-derived quota rows retain the original key's custody. A manual
        // read-only preview must not orphan them or either kind of installed ciphertext.
        return $this->tokens->credentialScope($token, KeyCreation::forDatabase($this->db));
    }
}
