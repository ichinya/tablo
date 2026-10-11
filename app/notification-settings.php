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
                $statement = $this->db->query('SELECT * FROM notification_settings WHERE id = 1');
                try { $old = $statement->fetch(); } finally { $statement->closeCursor(); }
                if ($old['revision'] !== (int) $revision) { throw new ValidationException(['notifications' => 'Настройки изменились. Обновите страницу.']); }
                $this->vault->assertAvailable(SiteRepository::hasEstablishedKeyState($this->db));
                $endpointCipher = $flags['remove_endpoint'] ? null : ($endpoint === '' ? $old['endpoint_cipher'] : $this->vault->encrypt($endpoint));
                $bearerCipher = $flags['remove_bearer'] ? null : ($bearer === '' ? $old['bearer_cipher'] : $this->vault->encrypt($bearer));
                if ($flags['enabled'] && $endpointCipher === null) { self::invalid(); }
                $reconfigure = $endpointCipher !== $old['endpoint_cipher'] || $bearerCipher !== $old['bearer_cipher'];
                foreach (['enabled','unavailable','recovery','version_lag'] as $key) {
                    $reconfigure = $reconfigure || $flags[$key] !== $old[$key];
                }
                $metadata = $flags['include_name'] !== $old['include_name'] || $flags['include_url'] !== $old['include_url'];
                if (!$reconfigure && !$metadata) { $this->db->exec('COMMIT'); return; }
                $activation = $old['activation_id'];
                if ($reconfigure) {
                    // Actual v5 history is AUTOINCREMENT. Its committed allocation survives pruning.
                    $statement = $this->db->query("SELECT COALESCE((SELECT seq FROM sqlite_sequence WHERE name='check_history'),0)");
                    try { $activation = max($activation,(int)$statement->fetchColumn()); } finally { $statement->closeCursor(); }
                }
                $statement = $this->db->prepare('UPDATE notification_settings SET revision = revision + 1, activation_id = ?,
                    endpoint_cipher = ?, bearer_cipher = ?, enabled = ?, unavailable = ?, recovery = ?, version_lag = ?,
                    include_name = ?, include_url = ? WHERE id = 1 AND revision = ?');
                try { $statement->execute([$activation,$endpointCipher,$bearerCipher,$flags['enabled'],$flags['unavailable'],
                    $flags['recovery'],$flags['version_lag'],$flags['include_name'],$flags['include_url'],(int)$revision]); }
                finally { $statement->closeCursor(); }
                if ($reconfigure) {
                    $this->db->exec("UPDATE notification_slots SET status = 'cancelled', claim = NULL, lease_until = NULL,
                        private_cipher = NULL WHERE status IN ('pending','inflight')");
                } else {
                    $this->refreshMetadata($flags,$old['revision']);
                }
                $this->db->exec('COMMIT');
            } catch (\Throwable $error) {
                try { $this->db->exec('ROLLBACK'); } catch (\Throwable) { }
                throw $error;
            }
        } finally { $lock->close(); }
    }

    private function refreshMetadata(array $flags, int $revision): void
    {
        // Never rebase an old inflight claim: the next revision must reject its ACK.
        $this->db->exec("UPDATE notification_slots SET status='cancelled',claim=NULL,lease_until=NULL,
            private_cipher=NULL WHERE status='inflight'");
        do {
            $query=$this->db->prepare("SELECT n.site_id,n.event,n.config_revision,s.name,s.url,s.enabled,
                s.config_revision AS current_revision FROM notification_slots n JOIN sites s ON s.id=n.site_id
                WHERE n.status='pending' AND n.channel_revision=? ORDER BY n.site_id,n.event LIMIT 32");
            try { $query->execute([$revision]); $rows=$query->fetchAll(); } finally { $query->closeCursor(); }
            foreach ($rows as $row) {
                $valid=$row['enabled'] && $row['config_revision']===$row['current_revision'];
                $private=[];
                if ($valid && $flags['include_name']) { $private['display_name']=$row['name']; }
                if ($valid && $flags['include_url']) {
                    $parts=parse_url($row['url']);
                    $private['site_origin']=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
                }
                $cipher=$private===[]?null:$this->vault->encrypt(json_encode($private,JSON_THROW_ON_ERROR));
                $query=$this->db->prepare('UPDATE notification_slots SET channel_revision=?,private_cipher=?,status=? WHERE site_id=? AND event=?');
                try { $query->execute([$revision+1,$cipher,$valid?'pending':'cancelled',$row['site_id'],$row['event']]); }
                finally { $query->closeCursor(); }
            }
        } while (count($rows)===32);
        // Preserve debounce/handled identities for this same logical channel, including legacy states.
        $after=0;
        do {
            $query=$this->db->prepare('SELECT site_id,state FROM notification_checkpoints WHERE site_id>? ORDER BY site_id LIMIT 32');
            try { $query->execute([$after]); $rows=$query->fetchAll(); } finally { $query->closeCursor(); }
            foreach ($rows as $row) {
                $after=$row['site_id']; $state=json_decode($row['state'],true,16,JSON_THROW_ON_ERROR);
                if ($state['channel']!==$revision) { continue; }
                $state['channel']=$revision+1;
                $query=$this->db->prepare('UPDATE notification_checkpoints SET state=? WHERE site_id=?');
                try { $query->execute([json_encode($state,JSON_THROW_ON_ERROR),$after]); } finally { $query->closeCursor(); }
            }
        } while (count($rows)===32);
    }

    private static function invalid(): never
    {
        throw new ValidationException(['notifications' => 'Проверьте адрес HTTPS, выбранные события и значения полей.']);
    }
}
