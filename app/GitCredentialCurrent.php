<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type GitCredentialRecord from SiteCredentialInput as CredentialRecord */
final class GitCredentialCurrent
{
    /** @param CredentialRecord|null $site */
    public function __construct(#[\SensitiveParameter] private readonly SiteRepository $sites, #[\SensitiveParameter] private readonly ?array $site, #[\SensitiveParameter] private readonly array $input, #[\SensitiveParameter] private readonly GitResolvedCredential $proof, private readonly bool $worker) {}
    public function matches(): bool
    {
            if ($this->worker) { $this->sites->assertWorkerKeyAvailable(); }
            $id = SiteSnapshotId::read($this->site);
            $fresh = $this->site;
            if ($id !== null && $this->site !== null) {
                $fresh = SiteProjection::gitCredentials($this->sites->find($id));
                if (!GitCredentialSnapshot::matches($this->site, $fresh)) { return false; }
            }
            return GitCredentialLiveProof::matches($this->sites,$this->input,$fresh,$this->proof);
    }

}
