<?php
declare(strict_types=1);

namespace Tablo;

final class WorkerSiteCheck
{
    /** @param \Closure(string): void $output */
    public function __construct(private readonly SiteRepository $sites,private readonly WorkerStateRepository $state,private readonly HttpClient $http, #[\SensitiveParameter] private readonly TokenVault $vault,private readonly \Closure $output) {}
    public function check(int $id,GitHubConnection $connection): void
    {
            try {
                $this->sites->assertWorkerKeyAvailable();
                $site = $this->sites->find($id);
                if ($site === null || !$site['enabled']) { return; }
                try {
                    $provider = $connection->provider(SiteProjection::gitCredentials($site));
                    $order = (new WorkerFieldOrder($this->state))->fieldOrder($site, $provider);
                } catch (\PDOException | SharedKeyFailure $error) { throw $error; }
                catch (\Throwable) {
                    $provider = new UnavailableRepositoryProvider();
                    $order = WorkerStateRepository::FIELDS;
                    ($this->output)('Worker credentials unavailable.');
                }
                $result = (new SiteChecker($this->http, $provider, $this->vault))->check($site, $order);
                $this->sites->assertWorkerKeyAvailable();
                $stored = $this->state->settle($site, $result);
                ($this->output)($stored ? 'Worker site checked.' : 'Worker site changed.');
            } catch (\PDOException | SharedKeyFailure $error) { throw $error; }
            catch (\Throwable) { ($this->output)('Worker site failed.'); }
    }
}
