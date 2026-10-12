<?php
declare(strict_types=1);

namespace Tablo;

/** The same finite ordered loop for legacy text and explicit JSON. */
// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class CheckCommand
{
    public function __construct(private readonly SiteRepository $sites, private readonly GitHubConnection $github,
        private readonly HttpClient $http, #[\SensitiveParameter] private readonly TokenVault $vault,
        private readonly ?NotificationDelivery $notifications = null) {}


    public function run(CheckFormat $format = CheckFormat::Text): int
    {
        $json = $format === CheckFormat::Json;
        $started = gmdate('Y-m-d\TH:i:s\Z');
        $snapshot = []; $progress = new CheckProgress(); $fragments = &$progress->fragments;
        $pass = new CheckSitePass($this->sites,$this->github,$this->http,$this->vault);
        $index = -1;
        $phase = &$progress->phase;
        $failures = 0;
        try {
            $this->sites->assertWorkerKeyAvailable();
            $snapshot = $this->sites->all();
            foreach ($snapshot as $index => $site) {
                $failures += $pass->check($site,$format,$progress);
            }
            CheckNotificationPass::run($this->notifications,$format);
        } catch (\Throwable $error) {
            if (!$json) { throw $error; }
            $reason = $phase === 'serializing' ? 'serialization-failed' : CheckOutput::fatalReason($error);
            CheckAbortProjection::fragments($progress,$reason,$snapshot,$index);
            echo CheckOutput::envelope('fatal', $reason, $started, $fragments);
            CheckOutput::diagnose($error);
            return 1;
        }
        if ($json) { echo CheckOutput::envelope($failures ? 'partial' : 'success', CheckRunReason::reason($failures,$snapshot), $started, $fragments); }
        return $failures ? 1 : 0;
    }
}
