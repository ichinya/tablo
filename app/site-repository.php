<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class SiteRepository extends SiteCredentialAccess
{
    private readonly TokenVault $tokens;
    private readonly GitTokenRepository $savedTokens;
    private readonly SiteCheckWriter $writes;

    public function __construct(private readonly PDO $db, #[\SensitiveParameter] ?TokenVault $tokens = null,
        private readonly ?\Closure $notificationClock = null)
    {
        $this->tokens = $tokens ?? TokenVault::forDatabase($db);
        $this->savedTokens = new GitTokenRepository($db, $this->tokens);
        $this->writes = new SiteCheckWriter($db);
        parent::__construct($db, $this->tokens);
    }

    // Settlement must write on the exact connection that owns its transaction.
    public function assertConnection(#[\SensitiveParameter] PDO $db): void
    {
        if ($this->db !== $db) { throw new \LogicException('Settlement repository connection mismatch.'); }
    }



    public function all(): array
    {
        return $this->db->query('SELECT s.*, t.encrypted_token AS selected_token_snapshot
            FROM sites s LEFT JOIN git_tokens t ON t.id = s.git_token_id ORDER BY s.sort_order, s.id')->fetchAll();
    }



    public function enabledIds(): array
    {
        $statement = $this->db->query('SELECT id FROM sites WHERE enabled = 1 ORDER BY sort_order, id');
        try { return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)); }
        finally { $statement->closeCursor(); }
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT s.*, t.encrypted_token AS selected_token_snapshot
            FROM sites s LEFT JOIN git_tokens t ON t.id = s.git_token_id WHERE s.id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ? $row : null;
    }













    // Endpoint credentials never enter Git provider/preview closures or public carriers.




















    public function save(#[\SensitiveParameter] array $input, ?int $id = null): int
    {
        $this->assertWorkerKeyAvailable();
        $data = SiteInput::normalize($input);
        $existing = $id === null ? null : $this->find($id);
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
            $sql = 'INSERT INTO sites (' . implode(', ', $fields) . ') VALUES (' . implode(', ', array_fill(0, count($fields), value: '?')) . ')';
            $this->writes->executeBound($sql, array_values($data));
            return (int) $this->db->lastInsertId();
        }
        $sql = 'UPDATE sites SET ' . implode(', ', array_map(static fn ($f) => "{$f} = ?", $fields)) . ',
            online = NULL, health_error_code = NULL, health_http_status = NULL,
            deployed_version = NULL, deployed_commit = NULL, latest_release = NULL,
            latest_commit = NULL, open_issues = NULL, open_prs = NULL, response_time_ms = NULL,
            last_error = NULL, checked_at = NULL, config_revision = config_revision + 1,
            updated_at = strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\') WHERE id = ?';
        $this->writes->executeBound($sql, [...array_values($data), $id]);
        return $id;
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM sites WHERE id = ?')->execute([$id]);
    }

    public function storeCheck(#[\SensitiveParameter] array $site, array $state): bool
    {
        return (new SiteResultSettlement($this->db,$this->tokens,$this->writes,$this->notificationClock))->persistCheck($site,$state,SettlementKind::Manual);
    }

    // Worker settlement delegates here; neither entry point can bypass transaction ownership.
    public function settleWorkerCheck(#[\SensitiveParameter] array $site, array $state): bool
    {
        return (new SiteResultSettlement($this->db,$this->tokens,$this->writes,$this->notificationClock))->persistCheck($site,$state,SettlementKind::Worker);
    }



    // Private, constant-size projection of the just-accepted row in persistCheck's transaction.
    // No replay/import/reset entry point; consumed IDs are idempotent even after retention.







}
