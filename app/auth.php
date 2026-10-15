<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use PDOException;
use SensitiveParameter;
use Throwable;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class Auth
{
    public function __construct(private readonly PDO $db) {}

    public function needsSetup(): bool
    {
        return !\Tablo\SqlStatement::checked($this->db->query('SELECT 1 FROM users WHERE id = 1'))->fetchColumn();
    }

    public function setup(#[SensitiveParameter] string $password, #[SensitiveParameter] string $confirmation): void
    {
        $this->setupSession($password, $confirmation);
    }

    public function setupSession(#[SensitiveParameter] string $password, #[SensitiveParameter] string $confirmation): string
    {
        $hash = PasswordService::hash($password, $confirmation);
        try {
            (new AdministratorCredentialWrite($this->db))->executeCredential('INSERT INTO users (id, password_hash) VALUES (1, ?)', [$hash]);
        } catch (PDOException $e) {
            if (!$this->needsSetup()) {
                throw ValidationException::field(field: 'password', message: 'Администратор уже создан. Войдите с существующим паролем.');
            }
            throw $e;
        }
        return AdministratorFingerprint::fingerprint($hash);
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
            \Tablo\SqlStatement::checked($this->db->prepare('DELETE FROM login_limits WHERE window_start < ?'))->execute([$now - 900]);
            $statement = \Tablo\SqlStatement::checked($this->db->prepare('SELECT failures FROM login_limits WHERE key = ?'));
            $statement->execute([$key]);
            $failures = (int) $statement->fetchColumn();
            $statement->closeCursor();
            if ($failures >= 5) {
                throw ValidationException::field(field: 'password', message: 'Слишком много попыток. Повторите через 15 минут.');
            }
            $hash = $this->adminHash();
            $valid = is_string($hash) && !str_contains($password, "\0") && password_verify($password, $hash);
            if ($valid) {
                \Tablo\SqlStatement::checked($this->db->prepare('DELETE FROM login_limits WHERE key = ?'))->execute([$key]);
                if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                    $hash = PasswordService::hash($password, $password);
                    (new AdministratorCredentialWrite($this->db))->executeCredential('UPDATE users SET password_hash = ? WHERE id = 1', [$hash]);
                }
            }
            if (!$valid) {
                \Tablo\SqlStatement::checked($this->db->prepare('INSERT INTO login_limits (key, failures, window_start) VALUES (?, 1, ?)
                    ON CONFLICT(key) DO UPDATE SET failures = failures + 1'))->execute([$key, $now]);
            }
            $this->db->exec('COMMIT');
            return $valid && $hash !== null ? AdministratorFingerprint::fingerprint($hash) : null;
        } catch (\Throwable $e) {
            try { $this->db->exec('ROLLBACK'); } catch (Throwable $discardedCleanup) { /* Preserve original failure. */  unset($discardedCleanup); }
            throw $e;
        }
    }

    private function adminHash(): ?string
    {
        $statement = \Tablo\SqlStatement::checked($this->db->query('SELECT password_hash FROM users WHERE id = 1'));
        try {
            // SQLite scalar result is still admitted by the string check below; no row remains false.
            /** @var string|int|float|null|false $hash */
            $hash = $statement->fetchColumn();
        }
        finally { $statement->closeCursor(); }
        return is_string($hash) ? $hash : null;
    }



    public function credentialFingerprint(): ?string
    {
        $hash = $this->adminHash();
        return $hash === null ? null : AdministratorFingerprint::fingerprint($hash);
    }




    public function changePassword(#[SensitiveParameter] string $password,#[SensitiveParameter] string $confirmation,#[SensitiveParameter] string $expectedFingerprint): void { (new AdministratorPasswordChange($this->db))->changePassword($password,$confirmation,$expectedFingerprint); }
}
