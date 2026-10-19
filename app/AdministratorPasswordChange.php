<?php
declare(strict_types=1);

namespace Tablo;

final class AdministratorPasswordChange
{
    public function __construct(private readonly \PDO $db) {}
    public function changePassword(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirmation,
        #[\SensitiveParameter] string $expectedFingerprint): void
    {
        $replacement = PasswordService::hash($password, $confirmation);
        // No transaction is owned if BEGIN fails. PHP 8.2 cannot reliably report
        // raw SQL transactions through PDO::inTransaction().
        $transaction = WriteTransaction::begin($this->db);
        try {
            $current = InstalledAdministrator::existingAdminHash($this->db);
            if (!hash_equals(AdministratorFingerprint::fingerprint($current), $expectedFingerprint)) {
                throw new PasswordConflict('Credential changed. Select the installation again.');
            }
            if (password_verify($password, $current)) {
                throw ValidationException::field(field: 'password', message: 'Новый пароль должен отличаться от текущего.');
            }
            $changed = (new AdministratorCredentialWrite($this->db))->executeCredential('UPDATE users SET password_hash = ? WHERE id = 1 AND password_hash = ?', [$replacement, $current]);
            if ($changed !== 1) { throw new PasswordConflict('Credential changed. Select the installation again.'); }
            if (!hash_equals($replacement, InstalledAdministrator::existingAdminHash($this->db))) {
                throw new PasswordConflict('Credential write conflicted.');
            }
            $transaction->commit();
        } catch (\Throwable $error) {
            $transaction->rollbackPreserving();
            if (DatabaseSession::server($this->db) && $error instanceof \PDOException) { throw DatabaseFailure::fromNative($error); }
            throw $error;
        }
    }
}
