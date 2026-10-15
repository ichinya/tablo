<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SchemaShape;
use Testo\Assert;
use Testo\Test;

final class CompositionIdentityTest extends CompositionSchemaFixtureCase
{




    #[Test]
    public function historySequenceAndIncidentOutboxIdentitiesSurvivePrunedAndPopulatedUpgrades(): void
    {
        foreach (['history5', 'incident6', 'notification7'] as $name) {
            foreach ([true, false] as $pruned) {
                $db = self::fixture($name);
                $db->exec("INSERT INTO check_history(id,site_id,config_revision,checked_at,online,health_error_code,version_status)
                    VALUES(41,1,0,'2026-01-01T00:00:00Z',0,'http','skipped');
                    UPDATE sqlite_sequence SET seq=900 WHERE name='check_history'");
                if ($pruned) { $db->exec('DELETE FROM check_history'); }
                if ($name !== 'history5') {
                    $db->exec("INSERT INTO incidents(id,site_id,config_revision,opened_at,health_error_code) VALUES(41,1,0,'2026-01-01T00:00:00Z','http');
                        INSERT INTO incident_checkpoints VALUES(1,41,0,'2026-01-01T00:00:00Z')");
                }
                if ($name === 'notification7') {
                    $db->exec("INSERT INTO notification_settings(id,installation_id,activation_id,revision,enabled,blocked,endpoint_cipher,bearer_cipher)
                        VALUES(1,'0123456789abcdef0123456789abcdef',900,4,1,1,'endpoint-original','bearer-original');
                        INSERT INTO notification_checkpoints VALUES(1,41,0,'{\"original\":true}');
                        INSERT INTO notification_slots(site_id,event,event_id,source_id,config_revision,channel_revision,payload,private_cipher,
                            created_at,expires_at,due_at,status,attempts,claim,lease_until,last_code,acknowledged_at,coalesced)
                        VALUES(1,'unavailable','original-id',41,0,4,'{\"source\":41}','slot-original',1000,9999,1200,'inflight',2,'original-claim',1500,'original-code',900,3)");
                }
                $before = self::snapshot($db);
                Database::migrate($db);
                foreach (['check_history', 'incidents', 'incident_checkpoints', 'notification_settings', 'notification_checkpoints', 'notification_slots'] as $table) {
                    if (!(($before[$table] ?? null) !== null)) { continue; }
 Assert::same(SchemaShape::rows($db, 'SELECT * FROM ' . $table . ' ORDER BY rowid'), \Tablo\Tests\Support\FixtureValue::at($before, $table), $name);
                }
                Assert::same((int) \Tablo\SqlStatement::checked($db->query("SELECT seq FROM sqlite_sequence WHERE name='check_history'"))->fetchColumn(), 900);
                $db->exec("INSERT INTO check_history(site_id,config_revision,checked_at,version_status) VALUES(1,0,'2026-01-01T00:00:01Z','skipped')");
                Assert::same((int) $db->lastInsertId(), 901);
                Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA foreign_keys'))->fetchColumn(), 1);
            }
        }
    }
}
