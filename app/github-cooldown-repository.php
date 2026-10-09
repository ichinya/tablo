<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class GitHubCooldownRepository
{
    public function __construct(private readonly PDO $db) {}

    public function eligibleAt(string $resource, string $scope = 'anonymous'): int
    {
        $statement = $this->db->prepare("SELECT MAX(eligible_at) FROM github_cooldowns
            WHERE (scope = ? AND resource = ?) OR (scope = 'shared' AND resource = 'secondary')");
        $statement->execute([$scope, $resource]);
        try { return (int) $statement->fetchColumn(); }
        finally { $statement->closeCursor(); }
    }

    public function defer(string $resource, int $eligibleAt, string $scope = 'anonymous'): void
    {
        // Stable credential handles preserve primary eligibility on rotation; secondary is
        // shared because distinct PATs may belong to one user. No credential or hash is stored.
        $this->db->prepare('INSERT INTO github_cooldowns (scope, resource, eligible_at) VALUES (?, ?, ?)
            ON CONFLICT(scope, resource) DO UPDATE SET eligible_at = MAX(eligible_at, excluded.eligible_at)')
            ->execute([$resource === 'secondary' ? 'shared' : $scope, $resource, $eligibleAt]);
    }
}
