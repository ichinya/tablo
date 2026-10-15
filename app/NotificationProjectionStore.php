<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationProjectionStore
{
    public function __construct(private readonly \PDO $db) {}
    public function one(string $sql, array $values=[]): array|false
    {
        $query=\Tablo\SqlStatement::checked($this->db->prepare($sql));
        try { $query->execute($values); return SqlRows::one($query->fetch(), static fn (array $row): array => $row); } finally { $query->closeCursor(); }
    }
    public function execute(string $sql, #[\SensitiveParameter] array $values): void
    {
        $query=\Tablo\SqlStatement::checked($this->db->prepare($sql));
        try { $query->execute($values); } finally { $query->closeCursor(); }
    }
}
