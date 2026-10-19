<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;

final class ExternalKeyAuthentication
{
    public static function authenticateExternalKey(PDO $db, #[\SensitiveParameter] TokenVault $vault): void
    {
        $server = DatabaseSession::server($db);
        if ($server) { ServerKeyIdentity::authenticate($db, $vault); }
        $present = false;
        $verified = false;
        foreach ([['sites', 'github_token'], ['sites', 'endpoint_token'], ['git_tokens', 'encrypted_token'], ['notification_settings', 'endpoint_cipher'],
            ['notification_settings', 'bearer_cipher'], ['notification_slots', 'private_cipher']] as [$table, $column]) {
            if (!$server) {
            $statement = \Tablo\SqlStatement::checked($db->prepare("SELECT type FROM main.sqlite_schema WHERE name = ?"));
            try {
                $statement->execute([$table]);
                // sqlite_schema.type is non-null text, with false reserved for no matching object.
                /** @var string|false $type */
                $type = $statement->fetchColumn();
            }
            finally { $statement->closeCursor(); }
            if ($type !== 'table' || !in_array($column, array_column(BaseSchema::columns($db, $table), 'name'), strict: true)) { continue; }
            }
            $statement = \Tablo\SqlStatement::checked($db->query('SELECT ' . $column . ' FROM ' . ($server ? '' : 'main.') . $table . ' WHERE ' . $column . " IS NOT NULL AND " . $column . " <> ''"));
            try {
                // These six credential columns have TEXT affinity and the query excludes SQL NULL.
                /** @var string|false $encrypted */
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
