<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseConfiguration;
use Tablo\ServerSchema;
use Tablo\Tests\Support\EngineFixture;
use Testo\Assert;
use Testo\Test;

final class EngineBoundaryAdmissionTest
{
    #[Test]
    public function foreignKeyToAnotherOwnedCatalogRefusesDespiteMatchingTableAndRowIds(): void
    {
        $fixture = new EngineFixture('admission-foreign-catalog'); $peer = new EngineFixture('admission-foreign-peer');
        try {
            $db = $fixture->maintenance(); $other = $peer->database();
            $sites = new \Tablo\SiteRepository($fixture->database(), $fixture->vault);
            $peerSites = new \Tablo\SiteRepository($other, $peer->vault);
            Assert::same($sites->save(\Tablo\Tests\Support\UnitFixtures::site()), 1);
            Assert::same($peerSites->save(\Tablo\Tests\Support\UnitFixtures::site()), 1);
            $db->exec("INSERT INTO check_history (site_id,config_revision,checked_at,online,version_status) VALUES (1,0,'0001-01-01T00:00:00Z',0,'skipped')");
            $catalog = DatabaseConfiguration::fromEnvironment(environment: $peer->environment())->catalog;
            $db->exec('ALTER TABLE check_history DROP FOREIGN KEY check_history_site_id_fk');
            $db->exec('ALTER TABLE check_history ADD CONSTRAINT check_history_site_id_fk FOREIGN KEY (site_id) REFERENCES `' . $catalog . '`.sites(id) ON DELETE CASCADE ON UPDATE RESTRICT');
            Assert::same(ServerSchema::rows($db, "SELECT REFERENCED_TABLE_SCHEMA AS target FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'check_history_site_id_fk'"), [['target' => $catalog]]);
            $rows = ServerSchema::rows($db, 'SELECT * FROM check_history');
            EngineMigrationAdmissionTest::refuses($fixture);
            Assert::same(ServerSchema::rows($db, 'SELECT * FROM check_history'), $rows);
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM sites', $db), 1);
            Assert::same($peer->scalar('SELECT COUNT(*) FROM sites', $other), 1);
            Assert::same((new \Tablo\ServerMigration($db, $fixture->vault))->status()['phase'], 'ready');
        } finally { $fixture->close(); $peer->close(); }
    }

    #[Test]
    public function emptyAndFlagReceiptValuesRefuseBeforeConfigurationKeyOrBootstrap(): void
    { engine_maintenance_receipt_values(); }

}

function engine_maintenance_receipt_values(): void
{
    $directory = new \Tablo\Tests\Support\TemporaryDirectory('tablo-maintenance-options-');
    try {
        $root = $directory->path;
        foreach (['', '--migrate'] as $value) {
            $result = \Tablo\Tests\Support\Subprocess::run([PHP_BINARY, dirname(__DIR__, levels: 2) . '/bin/database.php', '--init', '--receipt', $value],
                $directory, ['TABLO_DB_DRIVER' => 'synthetic-invalid-engine', 'TABLO_DB' => $root . '/database.sqlite', 'TABLO_TOKEN_KEY_FILE' => $root . '/absent-key.bin'],
                new \Tablo\Tests\Support\CommandOptions(timeout: 2, cwd: $root));
            Assert::same($result['exit_code'], 1); Assert::same($result['stdout'], '');
            Assert::same($result['stderr'], "Database maintenance failed.\n");
            $native = \Tablo\Tests\Support\SubprocessReceipt::read($result['files']['receipt']);
            Assert::same([$native['exit_code'], $native['closed'], $native['root_exit_observed']], [1, true, true]);
            foreach ([$root . '/database.sqlite', $root . '/absent-key.bin', $root . '/--migrate'] as $path) { Assert::false(file_exists($path)); }
        }
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { $directory->close(); }
}
