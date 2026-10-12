<?php
declare(strict_types=1);

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

use Tablo\Database;
use Tablo\Tests\Support\MigrationPdo;

[$script, $mode, $path, $directory, $name] = $argv;
$stage = 'entry';
$signal = static function (string $suffix) use ($directory, $name, &$stage): void {
    $stage = $suffix;
    file_put_contents($directory . '/' . $name . '.' . $suffix, data: 'ready');
};
$await = static function (string $file) use ($directory): void {
    $deadline = hrtime(true) + 10_000_000_000;
    do {
        clearstatcache();
        if (is_file($directory . '/' . $file)) { return; }
        usleep(20_000);
    } while (hrtime(true) < $deadline);
    throw new RuntimeException('Fixture barrier timed out: ' . $file);
};
$hold = static function () use ($directory, $name): void {
    // Blocking command input works on Windows anonymous pipes and releases on parent EOF.
    $command = fgets(STDIN);
    if ($command === false) { throw new RuntimeException('WAL holder parent pipe closed'); }
    if ($command !== "release\n") { throw new RuntimeException('WAL holder cancelled'); }
};

$db = null;
$details = [];
try {
    switch (true) {
case ($mode === 'writer' || $mode === 'writer-held'):

        $db = new MigrationPdo($path);
        $db->exec('BEGIN IMMEDIATE');
        $signal('locked');
        switch (true) {
case ($mode === 'writer-held'):
 $hold();
    break;
default:
 $await($name . '.release');
    break;
}
        $db->exec('ROLLBACK');

    break;
case ($mode === 'connect'):

        $signal('ready');
        $await('start');
        $db = Database::connect($path);

    break;
case ($mode === 'migrate-first' || $mode === 'migrate-second'):

        $db = new MigrationPdo($path);
        $db->beforeBegin = static function () use ($signal): void { $signal('before-begin'); };
        if ($mode === 'migrate-first') {
            $db->afterBegin = static function () use ($signal, $await, $name): void {
                $signal('locked');
                $await($name . '.release');
            };
        }
        Database::migrate($db);

    break;
case (str_starts_with($mode, 'wal-')):

        // Only the DELETE negative control bypasses the application's connection policy.
        $db = $mode === 'wal-delete-reader' || $mode === 'wal-delete-write'
            ? new MigrationPdo($path) : Database::connect($path);
        switch (true) {
case ($mode === 'wal-reader' || $mode === 'wal-delete-reader'):

            $db->exec('BEGIN');
            $details['before'] = (int) $db->query('SELECT count(*) FROM sites')->fetchColumn();
            $signal('snapshot');
            $await($name . '.release');
            $details['during'] = (int) $db->query('SELECT count(*) FROM sites')->fetchColumn();
            $db->exec('COMMIT');
            $details['after'] = (int) $db->query('SELECT count(*) FROM sites')->fetchColumn();

    break;
case ($mode === 'wal-holder'):

            $db->exec('BEGIN IMMEDIATE');
            $signal('locked');
            // Only the parent decides when this lock ends; there is no child expiry.
            $hold();
            $db->exec('ROLLBACK');
            $signal('released');

    break;
case ($mode === 'wal-crash'):

            $db->exec('PRAGMA wal_autocheckpoint = 0');
            $db->exec("INSERT INTO sites(name,url,repository) VALUES('committed','https://example.com','example/project')");
            $db->exec('BEGIN IMMEDIATE');
            $db->exec("INSERT INTO sites(name,url,repository) VALUES('uncommitted','https://example.com','example/project')");
            $signal('pending');
            $await($name . '.release');
            $db->exec('ROLLBACK');

    break;
case (in_array($mode, ['wal-write', 'wal-write-short', 'wal-delete-write'], strict: true)):

            if ($mode !== 'wal-write') { $db->exec('PRAGMA busy_timeout = 150'); }
            $details['timeout'] = (int) $db->query('PRAGMA busy_timeout')->fetchColumn();
            if ($mode === 'wal-write') { $signal('ready'); $await($name . '.proceed'); }
            $started = hrtime(true);
            try {
                $db->exec("INSERT INTO sites(name,url,repository) VALUES('new','https://example.com','example/project')");
                $details['written'] = true;
            } catch (PDOException $error) {
                $details['written'] = false;
                $details['sqlite_code'] = $error->errorInfo[1] ?? null;
                unset($error);
            }
            $details['elapsed'] = (hrtime(true) - $started) / 1_000_000_000;

    break;
default:

            throw new RuntimeException('Unknown WAL fixture mode');

    break;
}

    break;
default:

        throw new RuntimeException('Unknown fixture mode');

    break;
}
    $ddl = $db instanceof MigrationPdo ? count(array_filter($db->statements,
        static fn (string $sql): bool => preg_match('/^\s*(CREATE|ALTER)\b/i', $sql) === 1)) : null;
    $result = $details + ['version' => (int) $db->query('PRAGMA user_version')->fetchColumn(), 'ddl' => $ddl,
        'journal' => $db->query('PRAGMA journal_mode')->fetchColumn(),
        'foreign_keys' => (int) $db->query('PRAGMA foreign_keys')->fetchColumn()];
    if ($mode !== 'writer') { $result['users'] = (int) $db->query('SELECT count(*) FROM users')->fetchColumn(); }
    echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    $diagnostic = in_array($error->getMessage(), ['WAL holder cancelled', 'WAL holder parent pipe closed',
        'SQLite WAL is required for file databases; use writable local storage.'], strict: true) ? $error->getMessage() : 'Database fixture failed';
    fwrite(STDERR, json_encode(['mode' => $mode, 'stage' => $stage, 'schema' => null, 'exception' => $error::class,
        'sqlState' => $error instanceof PDOException ? ($error->errorInfo[0] ?? null) : null,
        'sqliteCode' => $error instanceof PDOException ? ($error->errorInfo[1] ?? null) : null,
        'diagnostic' => $diagnostic], JSON_THROW_ON_ERROR) . "\n");
    unset($error, $db);
    exit(1);
} finally {
    unset($db);
}
