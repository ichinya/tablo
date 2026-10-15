<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use Testo\Assert;

final class VaultAdmissionAssertions
{
    public function __construct(private readonly PDO $db) {}

    public function unchanged(array $schema, string $cipher): void
    {
                Assert::same((int) $this->db->query('PRAGMA user_version')->fetchColumn(), 0);
                Assert::same($this->db->query('PRAGMA journal_mode')->fetchColumn(), 'delete');
                Assert::same($this->db->query('SELECT sql FROM sqlite_schema ORDER BY name')->fetchAll(), $schema);
                Assert::same($this->db->query('SELECT github_token FROM sites')->fetchColumn(), $cipher);

    }
}
