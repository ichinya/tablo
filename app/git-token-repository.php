<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class GitTokenRepository
{
    private readonly TokenVault $vault;

    public function __construct(private readonly PDO $db, #[\SensitiveParameter] ?TokenVault $vault = null)
    {
        $this->vault = $vault ?? TokenVault::forDatabase($db);
    }

    public function all(): array
    {
        // Only metadata is exposed to forms; secrets stay in the vault.
        return $this->db->query('SELECT t.id, t.name, t.provider, t.created_at, t.updated_at,
            (SELECT COUNT(*) FROM sites s WHERE s.git_token_id = t.id) AS site_count
            FROM git_tokens t ORDER BY t.provider, t.name COLLATE NOCASE, t.id')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT id, name, provider, created_at, updated_at FROM git_tokens WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ? $row : null;
    }

    public function tokenFor(int $id, string $provider): string
    {
        $statement = $this->db->prepare('SELECT provider, encrypted_token FROM git_tokens WHERE id = ?');
        $statement->execute([$id]);
        $token = $statement->fetch();
        if (!$token || $token['provider'] !== $provider) {
            throw ValidationException::field(field: 'git_token_id', message: 'Выберите сохранённый токен для этого Git-провайдера.');
        }
        return $this->vault->decrypt($token['encrypted_token']);
    }

    public function save(#[\SensitiveParameter] array $input, ?int $id = null): int
    {
        $this->vault->assertAvailable(KeyRequirement::forDatabase($this->db));
        $existing = $id === null ? null : $this->find($id);
        if ($id !== null && !$existing) { throw new \OutOfBoundsException('Токен не найден.'); }
        [$name,$provider,$value] = SavedGitTokenInput::parse($input,$existing);
        $encrypted = $value !== '' ? $this->vault->encrypt($value) : null;
        $this->db->beginTransaction();
        try {
            if ($id === null) {
                $this->db->prepare('INSERT INTO git_tokens (name, provider, encrypted_token) VALUES (?, ?, ?)')
                    ->execute([$name, $provider, $encrypted]);
                $id = (int) $this->db->lastInsertId();
            }
            if ($existing !== null) {
                (new SavedGitTokenUpdate($this->db))->update($name,$provider,$encrypted,$id);
            }
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof \PDOException && $e->getCode() === '23000') {
                throw ValidationException::field(field: 'name', message: 'Токен с таким названием уже существует у этого провайдера.');
            }
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        try {
            $this->db->prepare('DELETE FROM git_tokens WHERE id = ?')->execute([$id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw ValidationException::field(field: 'token', message: 'Токен используется в проектах. Сначала выберите для них другой токен.');
            }
            throw $e;
        }
    }
}
