<?php
declare(strict_types=1);

namespace Tablo;

final class GitCredentialCurrent
{
    public function __construct(#[\SensitiveParameter] private readonly SiteRepository $sites, #[\SensitiveParameter] private readonly ?array $site, #[\SensitiveParameter] private readonly array $input, #[\SensitiveParameter] private readonly GitResolvedCredential $proof, private readonly bool $worker) {}
    public function matches(): bool
    {
            if ($this->worker) { $this->sites->assertWorkerKeyAvailable(); }
            $id = $this->site['id'] ?? null;
            $fresh = $this->site;
            if ($id !== null) {
                $fresh = SiteProjection::withoutEndpointCredential($this->sites->find((int) $id));
                if (!GitCredentialSnapshot::matches($this->site, $fresh)) { return false; }
            }
            return GitCredentialLiveProof::matches($this->sites,$this->input,$fresh,$this->proof);
    }

}
