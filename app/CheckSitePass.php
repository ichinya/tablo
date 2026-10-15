<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type SiteRecord from SiteRow */
final class CheckSitePass
{
    public function __construct(private readonly SiteRepository $sites,private readonly GitHubConnection $github,private readonly HttpClient $http,#[\SensitiveParameter] private readonly TokenVault $vault) {}
    /** @param SiteRecord $site */
    public function check(#[\SensitiveParameter] array $site,CheckFormat $format,CheckProgress $progress): int
    {
        $json = $format === CheckFormat::Json;
        $active = &$progress->active; $record = &$progress->record; $storeAttempted = &$progress->storeAttempted;
        $phase = &$progress->phase; $fragments = &$progress->fragments;
                $active = $site;
                $record = null;
                $storeAttempted = false;
                $phase = 'checking';
                if (!$site['enabled']) {
                    CheckSiteFragment::paused($format,$progress,$site);
                    $active = null;
                    return 0;
                }
                $this->sites->assertWorkerKeyAvailable();
                try {
                    $checker = new SiteChecker($this->http,
                        $this->github->provider(SiteProjection::gitCredentials($site)), $this->vault);
                    $state = $checker->check($site);
                    CheckSiteFragment::prepare($format,$progress,$site,$state);
                    $phase = 'checking';
                    $this->sites->assertWorkerKeyAvailable();
                    $phase = 'storing';
                    $storeAttempted = true;
                    $stored = $this->sites->storeCheck($site, $state);
                    $failed = !$stored || $state['online'] !== 1 || $state['last_error'] !== null;
                    CheckSiteFragment::accepted($format,$progress,new CheckSettlementState($stored,$failed));
                } catch (ValidationException) {
                    $failed = true;
                    CheckSiteFragment::invalid($format,$progress,$site);
                }
                if (!$json) { echo $site['id'] . ': ' . ($failed ? 'attention' : 'ok') . PHP_EOL; }
                $result = (int) $failed;
                $active = null;        return $result;
    }
}
