<?php
declare(strict_types=1);

namespace Tablo;

final class ServerSiteSave extends SiteCredentialAccess
{
    public function __construct(private readonly \PDO $db, private readonly SiteRepository $sites,
        #[\SensitiveParameter] private readonly TokenVault $tokens, private readonly SiteCheckWriter $writes)
    {
        parent::__construct($db, $tokens);
    }
    public function save(#[\SensitiveParameter] array $input, ?int $id = null): int
    {
        if ($id !== null && $id < 1) { throw new \OutOfBoundsException('Сайт не найден.'); }
        $this->assertWorkerKeyAvailable();
        $data = SiteInput::normalize($input);
        $existing = $id === null ? null : $this->sites->find($id);
        if ($id !== null && $existing === null) {
            throw new \OutOfBoundsException('Сайт не найден.');
        }
        $token = TokenInput::validateToken($input['github_token'] ?? '');
        $data['git_token_id'] = $this->selectedToken($input, $existing);
        if ($data['git_token_id'] !== null && $token !== '') {
            throw ValidationException::field(field: 'github_token', message: 'Выберите сохранённый токен или введите свой.');
        }
        $data = [...$data, ...SiteCredentialInput::prepare($this->tokens,$input,$existing,$data['git_token_id'],$token)];
        $fields = array_keys($data);
        if ($id === null) {
            if (DatabaseSession::server($this->db)) { $now = ServerDialect::utc(); $data['created_at'] = $now; $data['updated_at'] = $now; $fields = array_keys($data); }
            $sql = 'INSERT INTO sites (' . implode(', ', $fields) . ') VALUES (' . implode(', ', array_fill(0, count($fields), value: '?')) . ')';
            $this->writes->executeBound($sql, array_values($data));
            return DatabaseInteger::positive($this->db->lastInsertId());
        }
        $sql = 'UPDATE sites SET ' . implode(', ', array_map(static fn ($f) => "{$f} = ?", $fields)) . ',
            online = NULL, health_error_code = NULL, health_http_status = NULL,
            deployed_version = NULL, deployed_commit = NULL, latest_release = NULL,
            latest_commit = NULL, open_issues = NULL, open_prs = NULL, response_time_ms = NULL,
            last_error = NULL, checked_at = NULL, config_revision = config_revision + 1,
            updated_at = ' . ServerDialect::choose($this->db, "strftime('%Y-%m-%dT%H:%M:%SZ', 'now')", '?') . ' WHERE id = ?';
        $values = array_values($data);
        if (DatabaseSession::server($this->db)) { $values[] = ServerDialect::utc(); }
        $this->writes->executeBound($sql, [...$values, $id]);
        return $id;
    }
}
