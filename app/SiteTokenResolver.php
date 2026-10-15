<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type GitCredentialRecord from SiteCredentialInput as CredentialRecord */
final class SiteTokenResolver
{
    public function __construct(private readonly GitTokenRepository $savedTokens, #[\SensitiveParameter] private readonly TokenVault $tokens) {}
    /** @param CredentialRecord|null $site */
    public function resolveToken(#[\SensitiveParameter] array $input, #[\SensitiveParameter] ?array $site): string
    {
        $token = TokenInput::validateToken($input['github_token'] ?? '');
        $selected = (new SelectedSiteToken($this->savedTokens))->selectedToken($input, $site);
        if ($selected !== null) {
            if ($token !== '') { throw ValidationException::field(field: 'github_token', message: 'Выберите сохранённый токен или введите свой.'); }
            return $this->savedTokens->tokenFor($selected, $site['provider'] ?? 'github');
        }
        if ($token !== '') {
            return $token;
        }
        if (!FormCheckbox::selected($input['remove_github_token'] ?? null)) {
            $token = !($site['github_token'] ?? null) ? '' : $this->tokens->decrypt($site['github_token']);
        }
        return $token;
    }
}
