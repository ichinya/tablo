<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use PDOException;
use SensitiveParameter;
use Throwable;

final class Auth
{
    public function __construct(private readonly PDO $db) {}

    public function needsSetup(): bool
    {
        return !$this->db->query('SELECT 1 FROM users WHERE id = 1')->fetchColumn();
    }

    public function setup(#[SensitiveParameter] string $password, #[SensitiveParameter] string $confirmation): void
    {
        $this->setupSession($password, $confirmation);
    }

    public function setupSession(#[SensitiveParameter] string $password, #[SensitiveParameter] string $confirmation): string
    {
        $hash = PasswordService::hash($password, $confirmation);
        try {
            $this->executeCredential('INSERT INTO users (id, password_hash) VALUES (1, ?)', [$hash]);
        } catch (PDOException $e) {
            if (!$this->needsSetup()) {
                throw new ValidationException(['password' => 'Администратор уже создан. Войдите с существующим паролем.']);
            }
            throw $e;
        }
        return self::fingerprint($hash);
    }

    public function login(#[SensitiveParameter] string $password, string $address): bool
    {
        return $this->loginSession($password, $address) !== null;
    }

    public function loginSession(#[SensitiveParameter] string $password, string $address): ?string
    {
        $key = hash('sha256', $address);
        $now = time();
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $this->db->prepare('DELETE FROM login_limits WHERE window_start < ?')->execute([$now - 900]);
            $statement = $this->db->prepare('SELECT failures FROM login_limits WHERE key = ?');
            $statement->execute([$key]);
            $failures = (int) $statement->fetchColumn();
            $statement->closeCursor();
            if ($failures >= 5) {
                throw new ValidationException(['password' => 'Слишком много попыток. Повторите через 15 минут.']);
            }
            $hash = $this->adminHash();
            $valid = is_string($hash) && !str_contains($password, "\0") && password_verify($password, $hash);
            if ($valid) {
                $this->db->prepare('DELETE FROM login_limits WHERE key = ?')->execute([$key]);
                if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                    $hash = PasswordService::hash($password, $password);
                    $this->executeCredential('UPDATE users SET password_hash = ? WHERE id = 1', [$hash]);
                }
            } else {
                $this->db->prepare('INSERT INTO login_limits (key, failures, window_start) VALUES (?, 1, ?)
                    ON CONFLICT(key) DO UPDATE SET failures = failures + 1')->execute([$key, $now]);
            }
            $this->db->exec('COMMIT');
            return $valid ? self::fingerprint($hash) : null;
        } catch (\Throwable $e) {
            try { $this->db->exec('ROLLBACK'); } catch (Throwable) { /* Preserve original failure. */ }
            throw $e;
        }
    }

    private function adminHash(): ?string
    {
        $statement = $this->db->query('SELECT password_hash FROM users WHERE id = 1');
        try { $hash = $statement->fetchColumn(); }
        finally { $statement->closeCursor(); }
        return is_string($hash) ? $hash : null;
    }

    private static function fingerprint(#[SensitiveParameter] string $hash): string
    {
        return hash('sha256', "tablo/admin-session/v1\0" . $hash);
    }

    public function credentialFingerprint(): ?string
    {
        $hash = $this->adminHash();
        return $hash === null ? null : self::fingerprint($hash);
    }

    public function changePassword(#[SensitiveParameter] string $password, #[SensitiveParameter] string $confirmation,
        #[SensitiveParameter] string $expectedFingerprint): void
    {
        $replacement = PasswordService::hash($password, $confirmation);
        // No transaction is owned if BEGIN fails. PHP 8.2 cannot reliably report
        // raw SQL transactions through PDO::inTransaction().
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $current = Database::existingAdminHash($this->db);
            if (!hash_equals(self::fingerprint($current), $expectedFingerprint)) {
                throw new PasswordConflict('Credential changed. Select the installation again.');
            }
            if (password_verify($password, $current)) {
                throw new ValidationException(['password' => 'Новый пароль должен отличаться от текущего.']);
            }
            $changed = $this->executeCredential('UPDATE users SET password_hash = ? WHERE id = 1 AND password_hash = ?', [$replacement, $current]);
            if ($changed !== 1) { throw new PasswordConflict('Credential changed. Select the installation again.'); }
            if (!hash_equals($replacement, Database::existingAdminHash($this->db))) {
                throw new PasswordConflict('Credential write conflicted.');
            }
            $this->db->exec('COMMIT');
        } catch (Throwable $error) {
            try { $this->db->exec('ROLLBACK'); } catch (Throwable) { /* Preserve original failure. */ }
            throw $error;
        }
    }

    private function executeCredential(string $sql, #[SensitiveParameter] array $values): int
    {
        try {
            $statement = $this->db->prepare($sql);
            try {
                $statement->execute($values);
                return $statement->rowCount();
            } finally { $statement->closeCursor(); }
        } catch (PDOException) {
            // Internal PDO argument traces on supported runtimes may include its
            // bound array. Propagate a fresh safe failure without retaining that trace.
            throw new PDOException('Credential storage failed.');
        }
    }
}
