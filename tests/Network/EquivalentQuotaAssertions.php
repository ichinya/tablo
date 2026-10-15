<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;

final class EquivalentQuotaAssertions
{
    public function __construct(private readonly \PDO $db) {}

    public function privateIdentity(#[\SensitiveParameter] string $secret): void
    {
            $rows = \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($this->db->query('SELECT scope,resource,eligible_at FROM github_cooldowns'))->fetchAll(), \Tablo\SqlGithubCooldownsEligibleAtResourceScopeRow::checked(...));
            Assert::same(count($rows), 5, 'four observed handles plus one private identity');
            Assert::false(str_contains(\Tablo\Tests\Support\FixtureValue::string(json_encode($rows)), $secret));
            Assert::false(str_contains(\Tablo\Tests\Support\FixtureValue::string(json_encode($rows)), hash('sha256', $secret)));
            Assert::same((int) \Tablo\SqlStatement::checked($this->db->query("SELECT COUNT(*) FROM github_cooldowns WHERE scope='shared'"))->fetchColumn(), 0);

    }
}
