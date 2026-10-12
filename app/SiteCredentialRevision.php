<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type GitCredentialRecord from SiteCredentialInput as CredentialRecord */
final class SiteCredentialRevision
{
    public function __construct(private readonly \PDO $db,private readonly GitTokenRepository $savedTokens) {}
    /** @param CredentialRecord|null $site */
    public function credentialRevision(#[\SensitiveParameter] array $input, #[\SensitiveParameter] ?array $site): string
    {
        $selected = (new SelectedSiteToken($this->savedTokens))->selectedToken($input, $site);
        if ($selected !== null) {
            $statement = \Tablo\SqlStatement::checked($this->db->prepare('SELECT encrypted_token FROM git_tokens WHERE id = ?'));
            $statement->execute([$selected]);
            try {
                // encrypted_token is a constrained NOT NULL TEXT column; no row remains false.
                /** @var string|false $revision */
                $revision = $statement->fetchColumn();
                if (!is_string($revision)) { throw ValidationException::field(field: 'git_token_id', message: 'Выберите сохранённый токен.'); }
                return $revision;
            } finally { $statement->closeCursor(); }
        }
        if (($input['github_token'] ?? '') !== '' || FormCheckbox::selected($input['remove_github_token'] ?? null)) { return ''; }
        return $site['github_token'] ?? '';
    }
}
