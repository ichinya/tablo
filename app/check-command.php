<?php
declare(strict_types=1);

namespace Tablo;

/** The same finite ordered loop for legacy text and explicit JSON. */
final class CheckCommand
{
    public function __construct(private readonly SiteRepository $sites, private readonly GitHubConnection $github,
        private readonly HttpClient $http, #[\SensitiveParameter] private readonly TokenVault $vault,
        private readonly ?NotificationDelivery $notifications = null) {}

    public function run(bool $json = false): int
    {
        $started = gmdate('Y-m-d\TH:i:s\Z');
        $fragments = $snapshot = [];
        $index = -1;
        $active = $record = null;
        $storeAttempted = false;
        $phase = 'checking';
        $failures = 0;
        try {
            $this->sites->assertWorkerKeyAvailable();
            $snapshot = $this->sites->all();
            foreach ($snapshot as $index => $site) {
                $active = $site;
                $record = null;
                $storeAttempted = false;
                $phase = 'checking';
                if (!$site['enabled']) {
                    if ($json) { $fragments[] = CheckOutput::encode(CheckOutput::site($site, 'paused', 'disabled', null)); }
                    $active = null;
                    continue;
                }
                $this->sites->assertWorkerKeyAvailable();
                try {
                    $checker = new SiteChecker($this->http,
                        $this->github->provider(SiteRepository::withoutEndpointCredential($site)), $this->vault);
                    $state = $checker->check($site);
                    if ($json) {
                        $phase = 'serializing';
                        $record = CheckOutput::site($site, 'checked', 'ok', false, $state);
                        CheckOutput::encode($record); // Validate before any store attempt.
                    }
                    $phase = 'checking';
                    $this->sites->assertWorkerKeyAvailable();
                    $phase = 'storing';
                    $storeAttempted = true;
                    $stored = $this->sites->storeCheck($site, $state);
                    $failed = !$stored || $state['online'] !== 1 || $state['last_error'] !== null;
                    if ($json) {
                        $phase = 'serializing';
                        $record['reason'] = !$stored ? 'stale-result' : ($failed ? 'attention' : 'ok');
                        $record['attention'] = $failed;
                        $record['persistence']['status'] = $stored ? 'accepted' : 'rejected-stale';
                        $record['persistence']['accepted'] = $stored;
                        $record['persistence']['accepted_checked_at'] = $stored ? $record['observation']['checked_at'] : null;
                        $fragments[] = CheckOutput::encode($record);
                    }
                } catch (ValidationException) {
                    $failed = true;
                    if ($json) { $fragments[] = CheckOutput::encode(CheckOutput::site($site, 'failed', 'site-validation-failed', true)); }
                }
                if (!$json) { echo $site['id'] . ': ' . ($failed ? 'attention' : 'ok') . PHP_EOL; }
                $failures += (int) $failed;
                $active = null;
            }
            if ($this->notifications !== null) {
                $delivery = $this->notifications->runOne();
                if (!$json && !in_array($delivery['code'], ['off', 'idle'], true)) {
                    echo 'notification: ' . $delivery['code'] . PHP_EOL;
                }
            }
        } catch (\Throwable $error) {
            if (!$json) { throw $error; }
            $reason = $phase === 'serializing' ? 'serialization-failed' : CheckOutput::fatalReason($error);
            if ($active !== null) {
                try {
                    if ($record !== null) {
                        $record['status'] = 'failed'; $record['reason'] = $reason; $record['attention'] = true;
                        if ($record['persistence']['accepted'] === null) {
                            $record['persistence']['status'] = $storeAttempted ? 'unknown' : 'not-attempted';
                        }
                    } else { $record = CheckOutput::site($active, 'failed', $reason, true); }
                    // The record is the already validated scalar projection. Preserve
                    // actual settlement even if the ordinary fragment encoder failed.
                    $fragments[] = \json_encode($record, JSON_THROW_ON_ERROR);
                } catch (\Throwable) { /* An invalid ID is unrepresentable; earlier safe fragments survive. */ }
            }
            foreach (array_slice($snapshot, $index + 1) as $site) {
                try { $fragments[] = CheckOutput::encode(CheckOutput::site($site, 'skipped', 'run-aborted', null)); }
                catch (\Throwable) { /* Preserve every representable snapshot ID. */ }
            }
            echo CheckOutput::envelope('fatal', $reason, $started, $fragments);
            CheckOutput::diagnose($error);
            return 1;
        }
        if ($json) { echo CheckOutput::envelope($failures ? 'partial' : 'success', $failures ? 'attention' : ($snapshot === [] ? 'empty' : 'complete'), $started, $fragments); }
        return $failures ? 1 : 0;
    }
}
