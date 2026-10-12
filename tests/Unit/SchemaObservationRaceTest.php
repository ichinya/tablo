<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SchemaLineage;
use Tablo\Tests\Support\SchemaObservationPdo;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class SchemaObservationRaceTest
{
    #[Test]
    public function reclassifiesAProvenCommittedVersionTransition(): void
    {
        $directory = new TemporaryDirectory('tablo-schema-observation-');
        try {
            $db = new SchemaObservationPdo($directory->path . '/race.sqlite');
            Assert::same(SchemaLineage::classify($db), ['version' => Database::CURRENT_SCHEMA_VERSION,
                'endpoint' => true, 'history' => true, 'incidents' => true, 'notifications' => true]);
            Assert::true($db->observations >= 2);
            Assert::false($db->inTransaction());
            Assert::same($db->query('PRAGMA integrity_check')->fetchColumn(), 'ok');
        } finally { unset($db); $directory->close(); }
    }

    #[Test]
    public function preservesTheOriginalPdoFailureDespiteAChangedVersion(): void
    {
        $directory = new TemporaryDirectory('tablo-schema-observation-');
        $failure = new \PDOException('Synthetic metadata failure.');
        try {
            $db = new SchemaObservationPdo($directory->path . '/race.sqlite', $failure);
            $caught = null;
            try { SchemaLineage::classify($db); }
            catch (\Throwable $error) { $caught = $error; }
            Assert::same($caught, $failure);
            Assert::same($db->observations, 1);
        } finally { unset($db); $directory->close(); }
    }
}
