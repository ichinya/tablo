<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;
use Testo\Test;

final class CheckCommandTest extends CheckCommandFixtureCase
{


    #[Test]
    public function emptyDisabledMixedAndStorageFailureHaveDifferentActualResults(): void
    {
        try {
            $this->start();
            $empty = $this->document($this->run(), 'success', 0); Assert::same($empty['run']['reason'], 'empty');
            $disabled = $this->row(['enabled' => '0', 'health_path' => '/forbidden']);
            $paused = $this->document($this->run(), 'success', 0);
            Assert::same($paused['sites'][0]['status'], 'paused'); Assert::same($this->requests(), []);
            Assert::same($this->sites->find($disabled)['checked_at'], null);
            $enabled = $this->row();
            $mixed = $this->document($this->run(), 'success', 0); Assert::same(count($mixed['sites']), 2);
            Assert::same($mixed['sites'][1]['id'], $enabled); Assert::same(count($this->requests()), 6);
            Assert::false(in_array('/forbidden', $this->requests(), strict: true));
            $failed = $this->document($this->run(changes: ['TABLO_DB' => $this->directory->path]), 'fatal', 1);
            Assert::same($failed['sites'], []); Assert::false($failed['run']['reason'] === 'empty');
            Assert::same($this->db->query('SELECT run_id FROM worker_runtime')->fetchColumn(), null);
            Assert::same((int) $this->db->query('SELECT COUNT(*) FROM worker_progress')->fetchColumn(), 0);
        } catch (\Throwable $error) { $this->directory?->retainFailure($error); throw $error; }
        finally { $this->close(); }
    }



    #[Test]
    public function independentFailuresConfirmedNullZeroAndCooldownSurviveProjection(): void
    {
        try {
            $this->start();
            $id = $this->row(['repository' => 'fixture/no-release', 'version_path' => '']);
            $document = $this->document($this->run(), 'success', 0); $observation = $document['sites'][0]['observation'];
            Assert::same($observation['git']['fields']['latest_release']['status'], 'absent');
            Assert::same($observation['git']['fields']['open_issues']['value'], 0);
            Assert::same($observation['version']['status'], 'skipped');
            $this->row(['repository' => 'fixture/commit-fails', 'version_path' => '/bad-version']);
            $document = $this->document($this->run(), 'partial', 1); $observation = $document['sites'][1]['observation'];
            CheckObservationAssertions::independent($observation);
            $this->sites->delete($id); $this->sites->delete(2);
            $this->row(['repository' => 'fixture/core-limited', 'health_path' => '/offline']);
            $document = $this->document($this->run(), 'partial', 1); $observation = $document['sites'][0]['observation'];
            CheckObservationAssertions::limited($observation);
            $before = count(array_filter($this->requests(), static fn (string $path): bool => str_starts_with($path, '/repos/')));
            $document = $this->document($this->run(), 'partial', 1);
            Assert::same(count(array_filter($this->requests(), static fn (string $path): bool => str_starts_with($path, '/repos/'))), $before,
                'rendering and a fresh cooldown process add no provider admission');
            Assert::same($document['sites'][0]['observation']['git']['fields']['open_issues']['value'], 0);
            $document = $this->document($this->run(changes: ['TABLO_ALLOW_PRIVATE_NETWORK' => '0']), 'partial', 1);
            Assert::same($document['sites'][0]['observation']['health']['status'], 'unknown');
            Assert::same($document['sites'][0]['observation']['health']['reason'], 'ssrf');
            Assert::same($document['sites'][0]['observation']['health']['http_status'], null);
        } catch (\Throwable $error) { $this->directory?->retainFailure($error); throw $error; }
        finally { $this->close(); }
    }
}
