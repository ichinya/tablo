<?php
declare(strict_types=1);

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

use Tablo\Database;
use Tablo\Tests\Support\MigrationPdo;

$arguments = \Tablo\Tests\Support\FixtureValue::row($argv);
$mode = \Tablo\Tests\Support\FixtureValue::at($arguments, 1);
$path = \Tablo\Tests\Support\FixtureValue::at($arguments, 2);
$directory = \Tablo\Tests\Support\FixtureValue::at($arguments, 3);
$name = \Tablo\Tests\Support\FixtureValue::at($arguments, 4);
$stage = 'entry';
$signal = static function (string $suffix) use ($directory, $name, &$stage): void {
    $stage = $suffix;
    file_put_contents($directory . '/' . $name . '.' . $suffix, data: 'ready');
};
$await = static function (string $file) use ($directory): void { \Tablo\Tests\Support\FixtureBarrier::awaitFile($directory, $file); };
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

        $db = new MigrationPdo(\Tablo\Tests\Support\FixtureValue::string($path));
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

        $db = new MigrationPdo(\Tablo\Tests\Support\FixtureValue::string($path));
        $db->beforeBegin = static function () use ($signal): void { $signal('before-begin'); };
        if ($mode === 'migrate-first') {
            $db->afterBegin = static function () use ($signal, $await, $name): void {
                $signal('locked');
                $await($name . '.release');
            };
        }
        Database::migrate($db);

    break;
case (str_starts_with(\Tablo\Tests\Support\FixtureValue::string($mode), 'wal-')):

        // Only the DELETE negative control bypasses the application's connection policy.
        $db = $mode === 'wal-delete-reader' || $mode === 'wal-delete-write'
            ? new MigrationPdo(\Tablo\Tests\Support\FixtureValue::string($path)) : Database::connect($path);
        switch (true) {
case ($mode === 'wal-reader' || $mode === 'wal-delete-reader'):

            $db->exec('BEGIN');
            $details['before'] = (int) \Tablo\SqlStatement::checked($db->query('SELECT count(*) FROM sites'))->fetchColumn();
            $signal('snapshot');
            $await($name . '.release');
            $details['during'] = (int) \Tablo\SqlStatement::checked($db->query('SELECT count(*) FROM sites'))->fetchColumn();
            $db->exec('COMMIT');
            $details['after'] = (int) \Tablo\SqlStatement::checked($db->query('SELECT count(*) FROM sites'))->fetchColumn();

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
            $details['timeout'] = (int) \Tablo\SqlStatement::checked($db->query('PRAGMA busy_timeout'))->fetchColumn();
            if ($mode === 'wal-write') { $signal('ready'); $await($name . '.proceed'); }
            $started = \Tablo\MonotonicTime::integer(hrtime(true));
            try {
                $db->exec("INSERT INTO sites(name,url,repository) VALUES('new','https://example.com','example/project')");
                $details['written'] = true;
            } catch (PDOException $error) {
                $details['written'] = false;
                $details['sqlite_code'] = $error->errorInfo[1] ?? null;
                unset($error);
            }
            $details['elapsed'] = (\Tablo\MonotonicTime::integer(hrtime(true)) - $started) / 1_000_000_000;

    break;
default:

            throw new RuntimeException('Unknown WAL fixture mode');
}

    break;
default:

        throw new RuntimeException('Unknown fixture mode');
}
    $ddl = $db instanceof MigrationPdo ? count(array_filter($db->statements,
        static fn (string $sql): bool => preg_match('/^\s*(CREATE|ALTER)\b/i', $sql) === 1)) : null;
    $result = $details + ['version' => (int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), 'ddl' => $ddl,
        'journal' => \Tablo\SqlStatement::checked($db->query('PRAGMA journal_mode'))->fetchColumn(),
        'foreign_keys' => (int) \Tablo\SqlStatement::checked($db->query('PRAGMA foreign_keys'))->fetchColumn()];
    if ($mode !== 'writer') { $result['users'] = (int) \Tablo\SqlStatement::checked($db->query('SELECT count(*) FROM users'))->fetchColumn(); }
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
