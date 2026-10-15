<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;

final class InstalledAdministrator
{
    /** @return array{'admin': int, 'file': string, 'schema': int} */
    public static function installationIdentity(PDO $db): array
    {
        $statement = \Tablo\SqlStatement::checked($db->query('PRAGMA database_list'));
        try { $databases = \Tablo\SqlRows::all($statement->fetchAll(), \Tablo\SqlFileNameSeqRow::checked(...)); }
        finally { $statement->closeCursor(); }
        foreach ($databases as $database) {
            if ($database['name'] !== 'main') { continue; }
            $file = $database['file'];
            $canonical = $file !== '' ? realpath($file) : false;
            if ($canonical !== false && is_file($canonical) && is_writable($canonical)) {
                return ['file' => $canonical, 'admin' => 1, 'schema' => SchemaVersion::schemaVersion($db)];
            }
        }
        throw new InstallationException('Installation unavailable.');
    }
    public static function existingAdminHash(PDO $db): string
    {
        $version = self::validatedVersion($db);
        if ($version !== Database::CURRENT_SCHEMA_VERSION) {
            throw new InstallationException('Installation schema incompatible.');
        }
        if (array_diff(['health_error_code', 'health_http_status'], array_column(BaseSchema::columns($db, 'sites'), 'name')) !== []) {
            throw new InstallationException('Installation schema incompatible.');
        }
        InstalledAdministrator::validateCurrentAdditions($db);
        return SingletonAdministrator::hash($db);
    }

    private static function validatedVersion(PDO $db): int
    {
        try {
            $version = SchemaVersion::schemaVersion($db);
            BaseSchema::validateSchema($db, SchemaCompleteness::Complete);
            SchemaLineage::classify($db);
            return $version;
        } catch (RuntimeException $error) {
            ExistingConnection::refuseExistingFailure($error, 'Installation schema incompatible.');
        }
    }
    public static function validateCurrentAdditions(PDO $db): void
    {
        foreach (['sites' => ['config_revision', 'endpoint_token'], 'github_cooldowns' => ['scope', 'resource', 'eligible_at'],
            'installation_settings' => ['id', 'check_interval_minutes'],
            'worker_runtime' => ['id', 'run_id', 'stop_requested', 'fairness_turn'],
            'worker_progress' => ['site_id', 'config_revision', 'latest_release', 'latest_commit', 'open_issues', 'open_prs'],
            'check_history' => ['id','site_id','config_revision','checked_at'],
            'incidents' => ['id','site_id','config_revision','recovery_history_id','end_reason'],
            'incident_checkpoints' => ['site_id','last_history_id','config_revision','watermark'],
            'notification_settings' => ['id','revision','installation_id','blocked','endpoint_cipher','bearer_cipher'],
            'notification_checkpoints' => ['site_id','last_history_id','config_revision','state'],
            'notification_slots' => ['site_id','event','event_id','source_id','claim','attempts']] as $table => $required) {
            $statement = \Tablo\SqlStatement::checked($db->prepare("SELECT type FROM main.sqlite_schema WHERE name = ?"));
            try {
                $statement->execute([$table]);
                // sqlite_schema.type is non-null text; no matching object returns false.
                /** @var string|false $type */
                $type = $statement->fetchColumn();
            }
            finally { $statement->closeCursor(); }
            if ($type !== 'table' || array_diff($required, array_column(BaseSchema::columns($db, $table), 'name')) !== []) {
                throw new InstallationException('Installation schema incompatible.');
            }
        }
    }

}
