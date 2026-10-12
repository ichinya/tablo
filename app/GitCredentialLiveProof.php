<?php
declare(strict_types=1);

namespace Tablo;

final class GitCredentialLiveProof
{
    public static function matches(#[\SensitiveParameter] SiteRepository $sites,#[\SensitiveParameter] array $input,#[\SensitiveParameter] ?array $fresh,#[\SensitiveParameter] GitResolvedCredential $proof): bool
    {
            try { return hash_equals($proof->token, $sites->resolveToken($input, $fresh))
                && hash_equals($proof->revision, $sites->credentialRevision($input, $fresh)); }
            catch (\PDOException | SharedKeyFailure $error) { throw $error; }
            catch (\Throwable) { return false; }
    }
}
