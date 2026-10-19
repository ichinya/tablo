<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class GitHubCooldownRepository
{
    public function __construct(private readonly PDO $db) {}

    public function eligibleAt(string $resource, string $scope = 'anonymous', bool $includeSecondary = true): int
    {
        $statement = \Tablo\SqlStatement::checked($this->db->prepare("SELECT MAX(eligible_at) FROM github_cooldowns
            WHERE (scope = ? AND resource = ?) OR (" . ServerDialect::choose($this->db, 'CAST(? AS INTEGER)', 'CAST(? AS SIGNED)') . " = 1 AND scope = 'shared' AND resource = 'secondary')"));
        $statement->execute([$scope, $resource, (int) $includeSecondary]);
        try { return (int) $statement->fetchColumn(); }
        finally { $statement->closeCursor(); }
    }

    public function defer(string $resource, int $eligibleAt, string $scope = 'anonymous'): void
    {
        if (DatabaseSession::server($this->db) && !WriteTransaction::owns($this->db)) {
            WriteTransaction::run($this->db, function () use ($resource, $eligibleAt, $scope): void { $this->defer($resource, $eligibleAt, $scope); }); return;
        }
        // Stable credential handles preserve primary eligibility on rotation; secondary is
        // shared because distinct PATs may belong to one user. Equivalent tokens also use a
        // domain-separated HMAC scope from the private vault, never a raw/unkeyed token hash.
        $values = [$resource === 'secondary' ? 'shared' : $scope, $resource, $eligibleAt];
        if (DatabaseSession::server($this->db)) { $values[] = $eligibleAt; }
        \Tablo\SqlStatement::checked($this->db->prepare(ServerDialect::choose($this->db,
            'INSERT INTO github_cooldowns (scope, resource, eligible_at) VALUES (?, ?, ?) ON CONFLICT(scope, resource) DO UPDATE SET eligible_at = MAX(eligible_at, excluded.eligible_at)',
            'INSERT INTO github_cooldowns (scope, resource, eligible_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE eligible_at = GREATEST(eligible_at, ?)')))->execute($values);
    }
}
