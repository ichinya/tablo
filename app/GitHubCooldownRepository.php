<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class GitHubCooldownRepository
{
    public function __construct(private readonly PDO $db) {}

    public function eligibleAt(string $resource, string $scope = 'anonymous', bool $includeSecondary = true): int
    {
        $statement = $this->db->prepare("SELECT MAX(eligible_at) FROM github_cooldowns
            WHERE (scope = ? AND resource = ?) OR (CAST(? AS INTEGER) = 1 AND scope = 'shared' AND resource = 'secondary')");
        $statement->execute([$scope, $resource, (int) $includeSecondary]);
        try { return (int) $statement->fetchColumn(); }
        finally { $statement->closeCursor(); }
    }

    public function defer(string $resource, int $eligibleAt, string $scope = 'anonymous'): void
    {
        // Stable credential handles preserve primary eligibility on rotation; secondary is
        // shared because distinct PATs may belong to one user. Equivalent tokens also use a
        // domain-separated HMAC scope from the private vault, never a raw/unkeyed token hash.
        $this->db->prepare('INSERT INTO github_cooldowns (scope, resource, eligible_at) VALUES (?, ?, ?)
            ON CONFLICT(scope, resource) DO UPDATE SET eligible_at = MAX(eligible_at, excluded.eligible_at)')
            ->execute([$resource === 'secondary' ? 'shared' : $scope, $resource, $eligibleAt]);
    }
}
