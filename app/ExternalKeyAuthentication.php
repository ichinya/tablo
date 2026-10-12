<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;

final class ExternalKeyAuthentication
{
    public static function authenticateExternalKey(PDO $db, #[\SensitiveParameter] TokenVault $vault): void
    {
        $present = false;
        $verified = false;
        foreach ([['sites', 'github_token'], ['sites', 'endpoint_token'], ['git_tokens', 'encrypted_token'], ['notification_settings', 'endpoint_cipher'],
            ['notification_settings', 'bearer_cipher'], ['notification_slots', 'private_cipher']] as [$table, $column]) {
            $statement = $db->prepare("SELECT type FROM main.sqlite_schema WHERE name = ?");
            try { $statement->execute([$table]); $type = $statement->fetchColumn(); }
            finally { $statement->closeCursor(); }
            if ($type !== 'table' || !in_array($column, array_column(BaseSchema::columns($db, $table), 'name'), strict: true)) { continue; }
            $statement = $db->query('SELECT ' . $column . ' FROM main.' . $table . ' WHERE ' . $column . " IS NOT NULL AND " . $column . " <> ''");
            try {
                while (($encrypted = $statement->fetchColumn()) !== false) {
                    $present = true;
                    try { $plaintext = $vault->decrypt($encrypted); $verified = true; unset($plaintext); }
                    catch (SharedKeyFailure $error) { throw $error; }
                    catch (RuntimeException $discardedCleanup) { /* An individual damaged credential is not a global failure. */  unset($discardedCleanup); }
                    finally { unset($encrypted); }
                }
            } finally { $statement->closeCursor(); }
        }
        if ($present && !$verified) {
            throw new SharedKeyFailure(SharedKeyFailure::UNVERIFIED_DIAGNOSTIC, SharedKeyFailure::UNVERIFIED);
        }
    }

}
