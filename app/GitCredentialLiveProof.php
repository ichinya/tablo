<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type GitCredentialRecord from SiteCredentialInput as CredentialRecord */
final class GitCredentialLiveProof
{
    /** @param CredentialRecord|null $fresh */
    public static function matches(#[\SensitiveParameter] SiteRepository $sites,#[\SensitiveParameter] array $input,#[\SensitiveParameter] ?array $fresh,#[\SensitiveParameter] GitResolvedCredential $proof): bool
    {
            try { return hash_equals($proof->token, $sites->resolveToken($input, $fresh))
                && hash_equals($proof->revision, $sites->credentialRevision($input, $fresh)); }
            catch (\PDOException | DatabaseFailure | SharedKeyFailure $error) { throw $error; }
            catch (\Throwable) { return false; }
    }
}
