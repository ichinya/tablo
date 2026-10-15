<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;

final class WorkerLock
{
    private mixed $handle = null;

    public function acquire(PDO $db): bool
    {
        $statement = $db->query('PRAGMA main.database_list');
        try { $databases = $statement->fetchAll(PDO::FETCH_ASSOC); }
        finally { $statement->closeCursor(); }
        $path = '';
        foreach ($databases as $database) {
            if ($database['name'] === 'main') { $path = $database['file']; }
        }
        $canonical = $path === '' ? false : realpath($path);
        if ($canonical === false) { throw new RuntimeException('Worker requires a persistent local database.'); }
        $handle = null;
        set_error_handler(static function (): never { throw new RuntimeException('Worker lock access failed.'); });
        try {
            $handle = fopen($canonical . '.worker.lock', 'c+b');
            if ($handle === false) { throw new RuntimeException('Worker lock access failed.'); }
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
        if (is_resource($this->handle)) { fclose($this->handle); }
        $this->handle = null;
        // Never remove or replace the stable lock inode, including on normal stop.
    }
}
