<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;

final class ExternalQuotaAssertions
{
    public function __construct(private readonly \PDO $db) {}

    public function preserved(array $before, #[\SensitiveParameter] string $cipher): void
    {
            $after=\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($this->db->query("SELECT * FROM github_cooldowns WHERE scope LIKE 'credential:v1:%' ORDER BY scope,resource"))->fetchAll(), \Tablo\SqlGithubCooldownsEligibleAtResourceScopeRow::checked(...));
            Assert::same($after,$before, 'original private quota remains; equivalent site/saved handles may be materialized');
            Assert::same(\Tablo\SqlStatement::checked($this->db->query('SELECT github_token FROM sites'))->fetchColumn(),$cipher);

    }
}
