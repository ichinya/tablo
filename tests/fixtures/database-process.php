<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Tablo\Database;
use Tablo\Tests\Support\MigrationPdo;

[$script, $mode, $path, $directory, $name] = $argv;
$signal = static function (string $suffix) use ($directory, $name): void {
    file_put_contents($directory . '/' . $name . '.' . $suffix, 'ready');
};
$await = static function (string $file) use ($directory): void {
    $deadline = microtime(true) + 10;
    do {
        clearstatcache();
        if (is_file($directory . '/' . $file)) { return; }
        usleep(20_000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Fixture barrier timed out: ' . $file);
};

$db = null;
$details = [];
try {
    if ($mode === 'writer') {
        $db = new MigrationPdo($path);
        $db->exec('BEGIN IMMEDIATE');
        $signal('locked');
        $await($name . '.release');
        $db->exec('ROLLBACK');
    } elseif ($mode === 'connect') {
        $signal('ready');
        $await('start');
        $db = Database::connect($path);
    } elseif ($mode === 'migrate-first' || $mode === 'migrate-second') {
        $db = new MigrationPdo($path);
        $db->beforeBegin = static function () use ($signal): void { $signal('before-begin'); };
        if ($mode === 'migrate-first') {
            $db->afterBegin = static function () use ($signal, $await, $name): void {
                $signal('locked');
                $await($name . '.release');
            };
        }
        Database::migrate($db);
    } elseif (str_starts_with($mode, 'wal-')) {
        // Only the DELETE negative control bypasses the application's connection policy.
        $db = $mode === 'wal-delete-reader' || $mode === 'wal-delete-write'
            ? new MigrationPdo($path) : Database::connect($path);
        if ($mode === 'wal-reader' || $mode === 'wal-delete-reader') {
            $db->exec('BEGIN');
            $details['before'] = (int) $db->query('SELECT count(*) FROM sites')->fetchColumn();
            $signal('snapshot');
            $await($name . '.release');
            $details['during'] = (int) $db->query('SELECT count(*) FROM sites')->fetchColumn();
            $db->exec('COMMIT');
            $details['after'] = (int) $db->query('SELECT count(*) FROM sites')->fetchColumn();
        } elseif ($mode === 'wal-holder') {
            $db->exec('BEGIN IMMEDIATE');
            $signal('locked');
            $await($name . '.release');
            $db->exec('ROLLBACK');
        } elseif ($mode === 'wal-crash') {
            $db->exec('PRAGMA wal_autocheckpoint = 0');
            $db->exec("INSERT INTO sites(name,url,repository) VALUES('committed','https://example.com','example/project')");
            $db->exec('BEGIN IMMEDIATE');
            $db->exec("INSERT INTO sites(name,url,repository) VALUES('uncommitted','https://example.com','example/project')");
            $signal('pending');
            $await($name . '.release');
            $db->exec('ROLLBACK');
        } elseif (in_array($mode, ['wal-write', 'wal-write-short', 'wal-delete-write'], true)) {
            if ($mode !== 'wal-write') { $db->exec('PRAGMA busy_timeout = 150'); }
            $details['timeout'] = (int) $db->query('PRAGMA busy_timeout')->fetchColumn();
            $started = microtime(true);
            try {
                $db->exec("INSERT INTO sites(name,url,repository) VALUES('new','https://example.com','example/project')");
                $details['written'] = true;
            } catch (PDOException $error) {
                $details['written'] = false;
                $details['sqlite_code'] = $error->errorInfo[1] ?? null;
                unset($error);
            }
            $details['elapsed'] = microtime(true) - $started;
        } else {
            throw new RuntimeException('Unknown WAL fixture mode');
        }
    } else {
        throw new RuntimeException('Unknown fixture mode');
    }
    $ddl = $db instanceof MigrationPdo ? count(array_filter($db->statements,
        static fn (string $sql): bool => preg_match('/^\s*(CREATE|ALTER)\b/i', $sql) === 1)) : null;
    $result = $details + ['version' => (int) $db->query('PRAGMA user_version')->fetchColumn(), 'ddl' => $ddl,
        'journal' => $db->query('PRAGMA journal_mode')->fetchColumn(),
        'foreign_keys' => (int) $db->query('PRAGMA foreign_keys')->fetchColumn()];
    if ($mode !== 'writer') { $result['users'] = (int) $db->query('SELECT count(*) FROM users')->fetchColumn(); }
    echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    unset($error, $db);
    exit(1);
} finally {
    unset($db);
}
