<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
/** @phpstan-import-type Record from SqlGitTokensCreatedAtIdNameRow */
final class GitTokenRepository
{
    private readonly TokenVault $vault;

    public function __construct(private readonly PDO $db, #[\SensitiveParameter] ?TokenVault $vault = null)
    {
        $this->vault = $vault ?? TokenVault::forDatabase($db);
    }

    /** @return list<array{id: int, name: string, provider: string, created_at: string, updated_at: string, site_count: int, ...}> */
    public function all(): array
    {
        // Only metadata is exposed to forms; secrets stay in the vault.
        return SqlRows::all(\Tablo\SqlStatement::checked($this->db->query('SELECT t.id, t.name, t.provider, t.created_at, t.updated_at,
            (SELECT COUNT(*) FROM sites s WHERE s.git_token_id = t.id) AS site_count
            FROM git_tokens t ORDER BY t.provider, ' . ServerDialect::choose($this->db, 't.name COLLATE NOCASE', 't.name_key') . ', t.id'))->fetchAll(), static function (array $row): array {
                $row = SqlGitTokensCreatedAtIdNameRow::checked($row);
                $row['site_count'] = SqlColumn::integer($row, 'site_count');
                return $row;
            });
    }

    /** @return Record|null */
    public function find(int $id): ?array
    {
        $statement = \Tablo\SqlStatement::checked($this->db->prepare('SELECT id, name, provider, created_at, updated_at FROM git_tokens WHERE id = ?'));
        $statement->execute([$id]);
        $row = \Tablo\SqlRows::one($statement->fetch(), \Tablo\SqlGitTokensCreatedAtIdNameRow::checked(...));
        return $row ? $row : null;
    }

    public function tokenFor(int $id, string $provider): string
    {
        $statement = \Tablo\SqlStatement::checked($this->db->prepare('SELECT provider, encrypted_token FROM git_tokens WHERE id = ?'));
        $statement->execute([$id]);
        $token = \Tablo\SqlRows::one($statement->fetch(), \Tablo\SqlGitTokensEncryptedTokenProviderRow::checked(...));
        if (!$token || $token['provider'] !== $provider) {
            throw ValidationException::field(field: 'git_token_id', message: 'Выберите сохранённый токен для этого Git-провайдера.');
        }
        return $this->vault->decrypt($token['encrypted_token']);
    }

    public function save(#[\SensitiveParameter] array $input, ?int $id = null): int
    {
        $save = new ServerTokenSave($this->db, $this, $this->vault);
        return ServerWriterAdmission::single($this->db, static fn (): int => $save->save($input, $id));
    }

    public function delete(int $id): void
    {
        if (DatabaseSession::server($this->db) && !WriteTransaction::owns($this->db)) {
            WriteTransaction::run($this->db, function () use ($id): void { $this->delete($id); }); return;
        }
        try {
            \Tablo\SqlStatement::checked($this->db->prepare('DELETE FROM git_tokens WHERE id = ?'))->execute([$id]);
        } catch (\PDOException $e) {
            if (DatabaseSession::server($this->db) ? ($e->errorInfo[1] ?? null) === 1451 : $e->getCode() === '23000') {
                throw ValidationException::field(field: 'token', message: 'Токен используется в проектах. Сначала выберите для них другой токен.');
            }
            throw $e;
        }
    }
}
