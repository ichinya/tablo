<?php
declare(strict_types=1);

namespace Tablo;

final class AdministratorCredentialWrite
{
    public function __construct(private readonly \PDO $db) {}
    public function executeCredential(string $sql, #[\SensitiveParameter] array $values): int
    {
        try {
            $statement = \Tablo\SqlStatement::checked($this->db->prepare($sql));
            try {
                $statement->execute($values);
                return $statement->rowCount();
            } finally { $statement->closeCursor(); }
        } catch (\PDOException) {
            // Internal PDO argument traces on supported runtimes may include its
            // bound array. Propagate a fresh safe failure without retaining that trace.
            throw new \PDOException('Credential storage failed.');
        }
    }
}
