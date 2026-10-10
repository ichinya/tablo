<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class GitTokenRepository
{
    private readonly TokenVault $vault;

    public function __construct(private readonly PDO $db, ?TokenVault $vault = null)
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
        return $statement->fetch() ?: null;
    }

    public function tokenFor(int $id, string $provider): string
    {
        $statement = $this->db->prepare('SELECT provider, encrypted_token FROM git_tokens WHERE id = ?');
        $statement->execute([$id]);
        $token = $statement->fetch();
        if (!$token || $token['provider'] !== $provider) {
            throw new ValidationException(['git_token_id' => 'Выберите сохранённый токен для этого Git-провайдера.']);
        }
        return $this->vault->decrypt($token['encrypted_token']);
    }

    public function save(array $input, ?int $id = null): int
    {
        $existing = $id === null ? null : $this->find($id);
        if ($id !== null && !$existing) { throw new \OutOfBoundsException('Токен не найден.'); }
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        $provider = is_string($input['provider'] ?? null) ? $input['provider'] : '';
        GitProviders::requireSupported($provider);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new ValidationException(['name' => 'Укажите название токена до 80 символов.']);
        }
        try {
            $value = SiteRepository::validateToken($input['token'] ?? '');
        } catch (ValidationException $e) {
            throw new ValidationException(['token' => $e->getMessage()]);
        }
        if ($value === '' && $existing === null) {
            throw new ValidationException(['token' => 'Введите токен доступа.']);
        }
        $encrypted = $value !== '' ? $this->vault->encrypt($value) : null;
        $this->db->beginTransaction();
        try {
            if ($id === null) {
                $this->db->prepare('INSERT INTO git_tokens (name, provider, encrypted_token) VALUES (?, ?, ?)')
                    ->execute([$name, $provider, $encrypted]);
                $id = (int) $this->db->lastInsertId();
            } else {
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
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof \PDOException && $e->getCode() === '23000') {
                throw new ValidationException(['name' => 'Токен с таким названием уже существует у этого провайдера.']);
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
                throw new ValidationException(['token' => 'Токен используется в проектах. Сначала выберите для них другой токен.']);
            }
            throw $e;
        }
    }
}
