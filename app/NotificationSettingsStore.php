<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Record from SqlNotificationSettingsActivationIdBearerCipherBlockedRow as StoredSettings
 * @phpstan-import-type Form from NotificationFormInput
 */
final class NotificationSettingsStore
{
    public function __construct(private readonly \PDO $db) {}
    /** @return StoredSettings */
    public function current(): array
    {
                $statement = \Tablo\SqlStatement::checked($this->db->query('SELECT * FROM notification_settings WHERE id = 1'));
                try { return \Tablo\SqlRows::required($statement->fetch(), \Tablo\SqlNotificationSettingsActivationIdBearerCipherBlockedRow::checked(...)); } finally { $statement->closeCursor(); }
    }
    public function activation(int $activation): int
    {
                    if (DatabaseSession::server($this->db)) {
                        $statement = SqlStatement::checked($this->db->query('SELECT committed_history_high_watermark FROM tablo_database_state WHERE id = 1'));
                        try { return max($activation, DatabaseInteger::checked($statement->fetchColumn())); } finally { $statement->closeCursor(); }
                    }
                    // Actual v5 history is AUTOINCREMENT. Its committed allocation survives pruning.
                    $statement = \Tablo\SqlStatement::checked($this->db->query("SELECT COALESCE((SELECT seq FROM sqlite_sequence WHERE name='check_history'),0)"));
                    try { return max($activation,(int)$statement->fetchColumn()); } finally { $statement->closeCursor(); }
    }
    /** @param Form $form */
    public function write(int $activation, #[\SensitiveParameter] NotificationChange $change, #[\SensitiveParameter] array $form): void
    {
        $flags = $form['flags']; $revision = $form['revision'];
                $statement = \Tablo\SqlStatement::checked($this->db->prepare('UPDATE notification_settings SET revision = revision + 1, activation_id = ?,
                    endpoint_cipher = ?, bearer_cipher = ?, enabled = ?, unavailable = ?, recovery = ?, version_lag = ?,
                    include_name = ?, include_url = ? WHERE id = 1 AND revision = ?'));
                try { $statement->execute([$activation,$change->endpointCipher,$change->bearerCipher,$flags['enabled'],$flags['unavailable'],
                    $flags['recovery'],$flags['version_lag'],$flags['include_name'],$flags['include_url'],(int)$revision]); }
                finally { $statement->closeCursor(); }
    }
    public function cancel(): void
    {
                    $this->db->exec("UPDATE notification_slots SET status = 'cancelled', claim = NULL, lease_until = NULL,
                        private_cipher = NULL WHERE status IN ('pending','inflight')");
    }
}
