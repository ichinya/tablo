<?php
declare(strict_types=1);

namespace Tablo;

final class AdministratorCredentialWrite
{
    public function __construct(private readonly \PDO $db) {}
    public function setup(#[\SensitiveParameter] string $hash): void
    {
        $server = DatabaseSession::server($this->db);
        try {
            $this->executeCredential($server ? 'INSERT INTO users (id, password_hash, created_at) VALUES (1, ?, ?)'
                : 'INSERT INTO users (id, password_hash) VALUES (1, ?)', $server ? [$hash, ServerDialect::utc()] : [$hash]);
        } catch (\PDOException|DatabaseFailure $error) {
            if ($error instanceof DatabaseFailure && $error->category !== 'constraint') { throw $error; }
            $statement = SqlStatement::checked($this->db->query('SELECT 1 FROM users WHERE id = 1'));
            try { $exists = $statement->fetchColumn() !== false; } finally { $statement->closeCursor(); }
            if ($exists) { throw ValidationException::field(field: 'password', message: 'Администратор уже создан. Войдите с существующим паролем.'); }
            throw $error;
        }
    }
    public function executeCredential(string $sql, #[\SensitiveParameter] array $values): int
    {
        try {
            if (DatabaseSession::server($this->db) && !WriteTransaction::owns($this->db)) {
                return WriteTransaction::run($this->db, fn (): int => $this->executeCredential($sql, $values));
            }
            $statement = \Tablo\SqlStatement::checked($this->db->prepare($sql));
            try {
                $statement->execute($values);
                return $statement->rowCount();
            } finally { $statement->closeCursor(); }
        } catch (\PDOException $error) {
            if (DatabaseSession::server($this->db)) { throw DatabaseFailure::fromNative($error); }
            // Internal PDO argument traces on supported runtimes may include its
            // bound array. Propagate a fresh safe failure without retaining that trace.
            throw new \PDOException('Credential storage failed.');
        }
    }
}
