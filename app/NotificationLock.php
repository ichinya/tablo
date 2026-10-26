<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;

final class NotificationLock
{
    private mixed $handle = null;
    private ?ServerLock $serverLock = null;

    public function acquire(PDO $db): bool
    {
        if (DatabaseSession::server($db)) {
            $this->serverLock ??= new ServerLock();
            return $this->serverLock->acquire($db, 'channel');
        }
        $query = \Tablo\SqlStatement::checked($db->query('PRAGMA main.database_list'));
        try { $rows = \Tablo\SqlRows::all($query->fetchAll(), \Tablo\SqlFileNameSeqRow::checked(...)); } finally { $query->closeCursor(); }
        $file = '';
        foreach ($rows as $row) { if (!($row['name'] === 'main')) { continue; }
 $file = $row['file'];  }
        $path = $file === '' ? false : realpath($file);
        if ($path === false) { throw new RuntimeException('Notifications require a persistent local database.'); }
        $handle = null;
        set_error_handler(static function (): never { throw new RuntimeException('Notification lock unavailable.'); });
        try {
            $handle = fopen($path . '.notifications.lock', mode: 'c+b');
            if ($handle === false) { throw new RuntimeException('Notification lock unavailable.'); }
            $blocked = 0;
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
        if ($this->serverLock !== null) { $this->serverLock->close(); $this->serverLock = null; }
        if (is_resource($this->handle)) { fclose($this->handle); }
        $this->handle = null; // Stable inode remains; never unlink a shared dispatch lock.
    }
}
