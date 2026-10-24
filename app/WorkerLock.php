<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;

final class WorkerLock
{
    private mixed $handle = null;
    private ?ServerLock $serverLock = null;

    public function acquire(PDO $db): bool
    {
        if (DatabaseSession::server($db)) {
            $this->serverLock ??= new ServerLock();
            return $this->serverLock->acquire($db, 'worker');
        }
        $statement = \Tablo\SqlStatement::checked($db->query('PRAGMA main.database_list'));
        try { $databases = \Tablo\SqlRows::all($statement->fetchAll(PDO::FETCH_ASSOC), \Tablo\SqlFileNameSeqRow::checked(...)); }
        finally { $statement->closeCursor(); }
        $path = '';
        foreach ($databases as $database) {
            if (!($database['name'] === 'main')) { continue; }
 $path = $database['file'];
        }
        $canonical = $path === '' ? false : realpath($path);
        if ($canonical === false) { throw new RuntimeException('Worker requires a persistent local database.'); }
        $handle = null;
        set_error_handler(static function (): never { throw new RuntimeException('Worker lock access failed.'); });
        try {
            $handle = fopen($canonical . '.worker.lock', mode: 'c+b');
            if ($handle === false) { throw new RuntimeException('Worker lock access failed.'); }
            $wouldBlock = 0;
            if (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                fclose($handle);
                if ($wouldBlock === 1) { return false; }
                throw new RuntimeException('Worker lock access failed.');
            }
        } catch (\Throwable) {
            if (is_resource($handle)) { fclose($handle); }
            // Discard filesystem-warning frames; the public error has no private path/previous.
            throw new RuntimeException('Worker lock access failed.');
        } finally {
            restore_error_handler();
        }
        $this->handle = $handle;
        return true;
    }

    public function close(): void
    {
        if ($this->serverLock !== null) { $this->serverLock->close(); $this->serverLock = null; }
        if (is_resource($this->handle)) { fclose($this->handle); }
        $this->handle = null;
        // Never remove or replace the stable lock inode, including on normal stop.
    }
}
