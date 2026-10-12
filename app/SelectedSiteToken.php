<?php
declare(strict_types=1);

namespace Tablo;

final class SelectedSiteToken
{
    public function __construct(private readonly GitTokenRepository $savedTokens) {}
    public function selectedToken(#[\SensitiveParameter] array $input, #[\SensitiveParameter] ?array $site): ?int
    {
        $id = $input['git_token_id'] ?? ($site['git_token_id'] ?? '');
        if (!array_key_exists('git_token_id', $input) && ($input['github_token'] ?? '') !== '') { $id = ''; }
        if ($id === '' || $id === null) { return null; }
        if ((!is_string($id) && !is_int($id)) || !ctype_digit((string) $id) || (int) $id < 1) {
            throw ValidationException::field(field: 'git_token_id', message: 'Выберите сохранённый токен.');
        }
        $saved = $this->savedTokens->find((int) $id);
        if (!$saved || $saved['provider'] !== ($site['provider'] ?? 'github')) {
            throw ValidationException::field(field: 'git_token_id', message: 'Выберите сохранённый токен для этого Git-провайдера.');
        }
        return (int) $id;
    }
}
