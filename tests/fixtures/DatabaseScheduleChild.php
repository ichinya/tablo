<?php
declare(strict_types=1);

final class DatabaseScheduleChild
{
    public static string $directory;
    public static string $name;
    public static string $kind;
    public static string $lastStage = 'entry';
    public static bool $observed = false;

    public static function stage(string $stage, array $details = []): void
    {
        self::$lastStage = $stage;
        file_put_contents(self::$directory . '/' . self::$name . '.events', json_encode(['stage' => $stage, 'ns' => \Tablo\MonotonicTime::integer(hrtime(true))] + $details,
            JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
        file_put_contents(self::$directory . '/' . self::$name . '.' . $stage, data: 'ready');
    }

    private static function awaitCommand(string $expected): void
    {
        $command = fgets(STDIN);
        if ($command === false) { throw new RuntimeException('Connect schedule parent pipe closed'); }
        if ($command !== $expected . "\n") { throw new RuntimeException('Connect schedule cancelled'); }
    }

    public static function beforeBegin(): void
    {
        self::stage('before-begin');
        if (self::$kind === 'race' && self::$name === 'B') { self::awaitCommand('begin'); }
    }

    public static function afterBegin(): void
    {
        self::stage('after-begin');
        if (self::$kind === 'race' && self::$name === 'B') { self::awaitCommand('release'); }
    }

    public static function beforeWal(): void
    {
        self::stage('after-migrate');
        if (self::$kind === 'race' && self::$name === 'A') { self::awaitCommand('wal'); }
    }

    public static function attempt(?Throwable $error, bool $cleanupFailed, bool $statementRetained): void
    {
        if (self::$name !== 'A' || self::$observed || !$error instanceof PDOException) { return; }
        self::$observed = true;
        self::stage('attempt-finished', ['sqliteCode' => $error->errorInfo[1] ?? null,
            'cleanupFailed' => $cleanupFailed, 'statementRetained' => $statementRetained]);
        self::awaitCommand('retry');
    }
}

require_once __DIR__ . '/../Support/FixtureValue.php';
$kind = \Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 1);
$path = \Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 2);
$directory = \Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 3);
$name = \Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 4);
$source = \Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 5);
DatabaseScheduleChild::$kind = $kind;
DatabaseScheduleChild::$directory = $directory;
DatabaseScheduleChild::$name = $name;
require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($source): void {
    if (!in_array($class,['Tablo\\Database','Tablo\\SchemaUpgrade','Tablo\\WalNegotiation','Tablo\\WalAttempt'],strict: true)) { return; }
    $name = substr($class,offset: 6);
    if (is_file($source.'/app/'.$name.'.php')) { require $source.'/app/'.$name.'.php'; }
}, prepend: true);
$started = \Tablo\MonotonicTime::integer(hrtime(true));
$db = null;
$weak = null;
$code = 0;
try {
    $db = \Tablo\Database::connect($path);
    $weak = WeakReference::create($db);
    $result = ['ok' => true, 'version' => (int) \Tablo\SqlStatement::checked($db->query('PRAGMA main.user_version'))->fetchColumn(),
        'journal' => \Tablo\SqlStatement::checked($db->query('PRAGMA main.journal_mode'))->fetchColumn(),
        'timeout' => (int) \Tablo\SqlStatement::checked($db->query('PRAGMA busy_timeout'))->fetchColumn(),
        'foreignKeys' => (int) \Tablo\SqlStatement::checked($db->query('PRAGMA foreign_keys'))->fetchColumn(),
        'errorMode' => $db->getAttribute(PDO::ATTR_ERRMODE), 'fetchMode' => $db->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE)];
} catch (Throwable $error) {
    $code = 1;
    $result = ['ok' => false, 'exception' => $error::class, 'sqliteCode' => $error instanceof PDOException ? ($error->errorInfo[1] ?? null) : null];
    unset($error);
} finally {
    unset($db);
    gc_collect_cycles();
}
echo json_encode($result + ['stage' => DatabaseScheduleChild::$lastStage, 'elapsedMs' => (\Tablo\MonotonicTime::integer(hrtime(true)) - $started) / 1_000_000,
    'returnedPdoReleased' => $weak !== null ? $weak->get() === null : null], JSON_THROW_ON_ERROR), "\n";
exit($code);
