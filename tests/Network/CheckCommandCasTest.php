<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class CheckCommandCasTest extends CheckCommandFixtureCase
{


    #[Test]
    public function configTokenAndDeleteRacesRejectActualWritesWithoutGuessingCause(): void
    {
        foreach (['config', 'token', 'delete'] as $race) {
            $child = null;
            try {
                $this->start(); $id = $this->row(['health_path' => '/barrier', 'github_token' => $this->marker]);
                $previous = '2000-01-01T00:00:00Z';
                $this->db->exec("UPDATE sites SET checked_at = '2000-01-01T00:00:00Z' WHERE id = " . $id);
                $child = new Subprocess($this->command(['--json']), $this->directory, $this->environment()); $child->send('');
                $deadline = microtime(true) + 5;
                while (!is_file($this->directory->path . '/entered') && microtime(true) < $deadline) { clearstatcache(); usleep(10_000); }
                Assert::true(is_file($this->directory->path . '/entered'), 'actual endpoint race barrier entered');
                switch (true) {
case ($race === 'delete'):
 $this->sites->delete($id);
    break;
default:
 $this->sites->save(array_replace(UnitFixtures::site(), ['name' => $this->marker, 'url' => $this->peer->base,
                    'repository' => 'fixture/good', 'branch' => 'main', 'health_path' => '/barrier', 'version_path' => '/version',
                    'github_token' => $race === 'token' ? 'synthetic-rotated-token' : '', 'sort_order' => $race === 'config' ? '7' : '0']), $id);
    break;
}
                file_put_contents($this->directory->path . '/release', data: 'go');
                $result = $child->finish(); $this->record($result, 'actual-race:' . $race);
                $document = $this->document($result, 'partial', 1); $site = $document['sites'][0];
                Assert::same($site['reason'], 'stale-result'); Assert::same($site['persistence']['accepted'], false);
                Assert::same($site['persistence']['previous_checked_at'], $previous); Assert::same($site['persistence']['previous_freshness'], 'stale');
                Assert::same($site['persistence']['accepted_checked_at'], null); Assert::same($site['observation']['health']['status'], 'online');
                Assert::false($site['observation']['checked_at'] === $previous);
                Assert::same($this->db->query('SELECT run_id FROM worker_runtime')->fetchColumn(), null);
            } catch (\Throwable $error) { $this->directory?->retainFailure($error); throw $error; }
            finally { $child?->close(); $child = null; $this->close(); }
        }
    }
}
