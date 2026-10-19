<?php
declare(strict_types=1);

namespace Tablo;

final class SavedGitTokenUpdate
{
    public function __construct(private readonly \PDO $db) {}
    public function refuse(#[\SensitiveParameter] \Throwable $error): never
    {
        if (!$error instanceof \PDOException) { throw $error; }
        $server = DatabaseSession::server($this->db);
        $duplicate = $server ? ($error->errorInfo[1] ?? null) === 1062 : $error->getCode() === '23000';
        if ($duplicate) { throw ValidationException::field(field: 'name', message: 'Токен с таким названием уже существует у этого провайдера.'); }
        if ($server) { throw DatabaseFailure::fromNative($error); }
        throw $error;
    }
    public function update(string $name,string $provider,#[\SensitiveParameter] ?string $encrypted,int $id): void
    {
                $sql = 'UPDATE git_tokens SET name = ?, provider = ?, encrypted_token = COALESCE(?, encrypted_token), updated_at = '
                    . ServerDialect::choose($this->db, "strftime('%Y-%m-%dT%H:%M:%SZ', 'now')", '?') . ' WHERE id = ?';
                $values = [$name, $provider, $encrypted];
                if (DatabaseSession::server($this->db)) { $values[] = ServerDialect::utc(); }
                \Tablo\SqlStatement::checked($this->db->prepare($sql))->execute([...$values, $id]);
                if ($encrypted !== null) {
                    \Tablo\SqlStatement::checked($this->db->prepare('UPDATE sites SET online = NULL, health_error_code = NULL, health_http_status = NULL,
                        deployed_version = NULL, deployed_commit = NULL,
                        latest_release = NULL, latest_commit = NULL, open_issues = NULL, open_prs = NULL,
                        response_time_ms = NULL, last_error = NULL, checked_at = NULL,
                        config_revision = config_revision + 1 WHERE git_token_id = ?'))->execute([$id]);
                }
    }
}
