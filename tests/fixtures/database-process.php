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
    } else {
        throw new RuntimeException('Unknown fixture mode');
    }
    $ddl = $db instanceof MigrationPdo ? count(array_filter($db->statements,
        static fn (string $sql): bool => preg_match('/^\s*(CREATE|ALTER)\b/i', $sql) === 1)) : null;
    $result = ['version' => (int) $db->query('PRAGMA user_version')->fetchColumn(), 'ddl' => $ddl];
    if ($mode !== 'writer') { $result['users'] = (int) $db->query('SELECT count(*) FROM users')->fetchColumn(); }
    echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    unset($error, $db);
    exit(1);
} finally {
    unset($db);
}
