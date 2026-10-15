<?php
declare(strict_types=1);

namespace Tablo;

final class SiteCredentialRevision
{
    public function __construct(private readonly \PDO $db,private readonly GitTokenRepository $savedTokens) {}
    public function credentialRevision(#[\SensitiveParameter] array $input, #[\SensitiveParameter] ?array $site): string
    {
        $selected = (new SelectedSiteToken($this->savedTokens))->selectedToken($input, $site);
        if ($selected !== null) {
            $statement = $this->db->prepare('SELECT encrypted_token FROM git_tokens WHERE id = ?');
            $statement->execute([$selected]);
            try {
                $revision = $statement->fetchColumn();
                if (!is_string($revision)) { throw ValidationException::field(field: 'git_token_id', message: 'Выберите сохранённый токен.'); }
                return $revision;
            } finally { $statement->closeCursor(); }
        }
        if (($input['github_token'] ?? '') !== '' || FormCheckbox::selected($input['remove_github_token'] ?? null)) { return ''; }
        return $site['github_token'] ?? '';
    }
}
