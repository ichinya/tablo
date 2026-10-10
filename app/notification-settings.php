<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class NotificationSettings
{
    private readonly TokenVault $vault;
    public function __construct(private readonly PDO $db, #[\SensitiveParameter] ?TokenVault $vault = null)
    {
        $this->vault = $vault ?? TokenVault::forDatabase($db);
    }

    // Presence only. Neither read nor validation performs DNS, decrypts or sends.
    public function get(): array
    {
        $statement = $this->db->query("SELECT revision, enabled, blocked, unavailable, recovery, version_lag, include_name, include_url,
            endpoint_cipher IS NOT NULL AS endpoint_present, bearer_cipher IS NOT NULL AS bearer_present FROM notification_settings WHERE id = 1");
        try { return $statement->fetch(); } finally { $statement->closeCursor(); }
    }

    public function update(#[\SensitiveParameter] array $input): void
    {
        $revision = $input['revision'] ?? null;
        if (!is_string($revision) || !preg_match('/^(0|[1-9][0-9]{0,15})$/D', $revision)) { self::invalid(); }
        $endpoint = $input['endpoint'] ?? '';
        $bearer = $input['bearer'] ?? '';
        if (!is_string($endpoint) || !is_string($bearer)) { self::invalid(); }
        $flags = [];
        foreach (['enabled','unavailable','recovery','version_lag','include_name','include_url','remove_endpoint','remove_bearer'] as $key) {
            $value = $input[$key] ?? '0';
            if (!is_string($value) || !in_array($value, ['0','1'], true)) { self::invalid(); }
            $flags[$key] = (int) $value;
        }
        if (($flags['remove_endpoint'] && $endpoint !== '') || ($flags['remove_bearer'] && $bearer !== '')) { self::invalid(); }
        if ($endpoint !== '') { WebhookAddress::parts($endpoint); }
        if ($bearer !== '' && !preg_match('/^[\x21-\x7e]{1,512}$/D', $bearer)) { self::invalid(); }
        $lock = new NotificationLock();
        if (!$lock->acquire($this->db)) { throw new ValidationException(['notifications' => 'Доставка выполняется. Повторите сохранение после её завершения.']); }
        try {
            // BEGIN refusal leaves caller-owned work untouched.
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                $statement = $this->db->query('SELECT revision, endpoint_cipher, bearer_cipher FROM notification_settings WHERE id = 1');
                try { $old = $statement->fetch(); } finally { $statement->closeCursor(); }
                if ($old['revision'] !== (int) $revision) { throw new ValidationException(['notifications' => 'Настройки изменились. Обновите страницу.']); }
                $this->vault->assertAvailable(SiteRepository::hasEstablishedKeyState($this->db));
                $endpointCipher = $flags['remove_endpoint'] ? null : ($endpoint === '' ? $old['endpoint_cipher'] : $this->vault->encrypt($endpoint));
                $bearerCipher = $flags['remove_bearer'] ? null : ($bearer === '' ? $old['bearer_cipher'] : $this->vault->encrypt($bearer));
                if ($flags['enabled'] && $endpointCipher === null) { self::invalid(); }
                $statement = $this->db->query('SELECT COALESCE(MAX(id),0) FROM check_history');
                try { $activation = (int) $statement->fetchColumn(); } finally { $statement->closeCursor(); }
                $statement = $this->db->prepare('UPDATE notification_settings SET revision = revision + 1, activation_id = ?,
                    endpoint_cipher = ?, bearer_cipher = ?, enabled = ?, unavailable = ?, recovery = ?, version_lag = ?,
                    include_name = ?, include_url = ? WHERE id = 1 AND revision = ?');
                try { $statement->execute([$activation,$endpointCipher,$bearerCipher,$flags['enabled'],$flags['unavailable'],
                    $flags['recovery'],$flags['version_lag'],$flags['include_name'],$flags['include_url'],(int)$revision]); }
                finally { $statement->closeCursor(); }
                $this->db->exec("UPDATE notification_slots SET status = 'cancelled', claim = NULL, lease_until = NULL WHERE status IN ('pending','inflight')");
                $this->db->exec('COMMIT');
            } catch (\Throwable $error) {
                try { $this->db->exec('ROLLBACK'); } catch (\Throwable) { }
                throw $error;
            }
        } finally { $lock->close(); }
    }

    private static function invalid(): never
    {
        throw new ValidationException(['notifications' => 'Проверьте адрес HTTPS, выбранные события и значения полей.']);
    }
}
