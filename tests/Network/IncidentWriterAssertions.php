<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;

final class IncidentWriterAssertions
{
    public function __construct(private readonly \PDO $db) {}

    public function noResurrection(array $result, int $other): void
    {
            Assert::same($result['exit_code'], 0);
            Assert::true(str_contains($result['stdout'], '"result":false'));
            Assert::same(array_column($this->db->query('SELECT site_id FROM incidents')->fetchAll(), 'site_id'), [$other]);
            Assert::same(array_column($this->db->query('SELECT site_id FROM incident_checkpoints')->fetchAll(), 'site_id'), [$other]);
            Assert::same($this->db->query('PRAGMA foreign_key_check')->fetchAll(), []);

    }
}
