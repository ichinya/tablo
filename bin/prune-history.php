<?php
declare(strict_types=1);

try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    // Explicitly empty/malformed policy refuses before opening or migrating storage.
    $retention = getenv('TABLO_HISTORY_RETENTION_DAYS');
    // Windows putenv may remove an empty value; Dotenv still retains its explicit declaration.
    if ($retention === false && array_key_exists('TABLO_HISTORY_RETENTION_DAYS', $_ENV)) {
        $retention = $_ENV['TABLO_HISTORY_RETENTION_DAYS'];
    }
    $days = Tablo\HistoryRetention::days($retention);
    if ($argc > 2 || (isset($argv[1]) && (!preg_match('/^[1-9][0-9]*$/D', $argv[1])
        || filter_var($argv[1], FILTER_VALIDATE_INT) === false))) {
        throw new InvalidArgumentException('Invalid history arguments.');
    }
    $history = new Tablo\CheckHistoryRepository(Tablo\Database::connect());
    echo json_encode($history->prune($days, isset($argv[1]) ? (int) $argv[1] : null), JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (OutOfBoundsException) {
    fwrite(STDERR, 'History site not found.' . PHP_EOL);
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, 'History prune failed. Check retention and local storage.' . PHP_EOL);
    exit(1);
}
