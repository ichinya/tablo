<?php
declare(strict_types=1);

namespace Tablo;

final class SavedGitTokenUpdate
{
    public function __construct(private readonly \PDO $db) {}
    public function update(string $name,string $provider,#[\SensitiveParameter] ?string $encrypted,int $id): void
    {
                $this->db->prepare('UPDATE git_tokens SET name = ?, provider = ?, encrypted_token = COALESCE(?, encrypted_token),
                    updated_at = strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\') WHERE id = ?')->execute([$name, $provider, $encrypted, $id]);
                if ($encrypted !== null) {
                    $this->db->prepare('UPDATE sites SET online = NULL, health_error_code = NULL, health_http_status = NULL,
                        deployed_version = NULL, deployed_commit = NULL,
                        latest_release = NULL, latest_commit = NULL, open_issues = NULL, open_prs = NULL,
                        response_time_ms = NULL, last_error = NULL, checked_at = NULL,
                        config_revision = config_revision + 1 WHERE git_token_id = ?')->execute([$id]);
                }
    }
}
