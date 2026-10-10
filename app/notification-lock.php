<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;

final class NotificationLock
{
    private mixed $handle = null;

    public function acquire(PDO $db): bool
    {
        $query = $db->query('PRAGMA main.database_list');
        try { $rows = $query->fetchAll(); } finally { $query->closeCursor(); }
        $file = '';
        foreach ($rows as $row) { if ($row['name'] === 'main') { $file = $row['file']; } }
        $path = $file === '' ? false : realpath($file);
        if ($path === false) { throw new RuntimeException('Notifications require a persistent local database.'); }
        $handle = null;
        set_error_handler(static function (): never { throw new RuntimeException('Notification lock unavailable.'); });
        try {
            $handle = fopen($path . '.notifications.lock', 'c+b');
            if ($handle === false) { throw new RuntimeException('Notification lock unavailable.'); }
            if (!flock($handle, LOCK_EX | LOCK_NB, $blocked)) {
                fclose($handle); $handle = null;
                if ($blocked === 1) { return false; }
                throw new RuntimeException('Notification lock unavailable.');
            }
            $this->handle = $handle;
            return true;
        } catch (\Throwable) {
            if (is_resource($handle)) { fclose($handle); }
            throw new RuntimeException('Notification lock unavailable.');
        } finally { restore_error_handler(); }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) { fclose($this->handle); }
        $this->handle = null; // Stable inode remains; never unlink a shared dispatch lock.
    }
}
