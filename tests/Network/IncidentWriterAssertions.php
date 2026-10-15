<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;

final class IncidentWriterAssertions
{
    public function __construct(private readonly \PDO $db) {}

    public function noResurrection(array $result, int $other): void
    {
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($result, 'exit_code'), 0);
            Assert::true(str_contains(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at($result, 'stdout')), '"result":false'));
            Assert::same(array_column(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($this->db->query('SELECT site_id FROM incidents'))->fetchAll(), \Tablo\SqlIncidentsSiteIdRow::checked(...)), 'site_id'), [$other]);
            Assert::same(array_column(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($this->db->query('SELECT site_id FROM incident_checkpoints'))->fetchAll(), \Tablo\SqlIncidentsSiteIdRow::checked(...)), 'site_id'), [$other]);
            Assert::same(\Tablo\SqlStatement::checked($this->db->query('PRAGMA foreign_key_check'))->fetchAll(), []);

    }
}
