<?php
declare(strict_types=1);

namespace Tablo;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class SiteChecker
{
    public function __construct(private readonly HttpClient $http, private readonly RepositoryProvider $provider,
        #[\SensitiveParameter] private readonly ?TokenVault $vault = null) {}

    public function check(#[\SensitiveParameter] array $site, ?array $fieldOrder = null): array
    {
        $state = CheckState::initial($site);
        $errors = [];
        $access = EndpointCheckAccess::forSite($site, $this->vault);
        (new EndpointHealthCheck($this->http))->health($site, $access, $state, $errors);
        (new EndpointVersionCheck($this->http))->version($site, $access, $state, $errors);
        $state['version_status'] = match ($state['version_outcome']['status']) { 'ok' => 'ok', 'skipped' => 'skipped', default => 'error' };
        $state['version_error_code'] = $state['version_status'] === 'error' ? $state['version_outcome']['reason'] : null;
        $state['version_http_status'] = $state['version_outcome']['http_status'];
        unset($access);
        (new GitCheck($this->provider))->git($site['repository'], $site['branch'], $fieldOrder, $state, $errors);
        $state['last_error'] = $errors ? implode(' ', $errors) : null;
        return $state;
    }








}
