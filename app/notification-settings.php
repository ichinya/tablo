<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
/** @phpstan-import-type Record from SqlNotificationSettingsBearerPresentBlockedEnabledRow as PublicSettings */
final class NotificationSettings
{
    private readonly TokenVault $vault;
    public function __construct(private readonly PDO $db, #[\SensitiveParameter] ?TokenVault $vault = null)
    {
        $this->vault = $vault ?? TokenVault::forDatabase($db);
    }

    // Presence only. Neither read nor validation performs DNS, decrypts or sends.
    /** @return PublicSettings */
    public function get(): array
    {
        $statement = \Tablo\SqlStatement::checked($this->db->query("SELECT revision, enabled, blocked, unavailable, recovery, version_lag, include_name, include_url,
            endpoint_cipher IS NOT NULL AS endpoint_present, bearer_cipher IS NOT NULL AS bearer_present FROM notification_settings WHERE id = 1"));
        try { return \Tablo\SqlRows::required($statement->fetch(), \Tablo\SqlNotificationSettingsBearerPresentBlockedEnabledRow::checked(...)); } finally { $statement->closeCursor(); }
    }

    public function update(#[\SensitiveParameter] array $input): void
    {
        $form = NotificationFormInput::parse($input);
        $revision = $form['revision']; $flags = $form['flags'];
        $store = new NotificationSettingsStore($this->db);
        $lock = new NotificationLock();
        if (!$lock->acquire($this->db)) { throw ValidationException::field(field: 'notifications', message: 'Доставка выполняется. Повторите сохранение после её завершения.'); }
        try {
            // BEGIN refusal leaves caller-owned work untouched.
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                $old = $store->current();
                if ($old['revision'] !== (int) $revision) { throw ValidationException::field(field: 'notifications', message: 'Настройки изменились. Обновите страницу.'); }
                $this->vault->assertAvailable(KeyRequirement::forDatabase($this->db));
                $change = NotificationChange::prepare($old, $form, $this->vault);
                $reconfigure = $change->reconfigure; $metadata = $change->metadata;
                if (!$reconfigure && !$metadata) { $this->db->exec('COMMIT'); return; }
                $activation = $old['activation_id'];
                if ($reconfigure) {
                    $activation = $store->activation($activation);
                }
                $store->write($activation, $change, $form);
                if ($reconfigure) {
                    $store->cancel();
                }
                if (!$reconfigure) { (new NotificationMetadataRefresh($this->db, $this->vault))->refresh($flags,$old['revision']); }
                $this->db->exec('COMMIT');
            } catch (\Throwable $error) {
                try { $this->db->exec('ROLLBACK'); } catch (\Throwable $discardedCleanup) {  unset($discardedCleanup); }
                throw $error;
            }
        } finally { $lock->close(); }
    }




}
