<?php
declare(strict_types=1);

namespace Tablo;

final class GitCredentialSnapshot
{

    public static function matches(#[\SensitiveParameter] array $before, #[\SensitiveParameter] ?array $after): bool
    {
        if ($after === null) { return false; }
        foreach (['config_revision', 'git_token_id', 'github_token', 'selected_token_snapshot'] as $field) {
            if (($after[$field] ?? null) !== ($before[$field] ?? null)) { return false; }
        }
        return true;
    }

}
