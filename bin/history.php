<?php
declare(strict_types=1);

try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    if ($argc < 4 || $argc > 6 || !preg_match('/^[1-9][0-9]*$/D', $argv[1])
        || filter_var($argv[1], FILTER_VALIDATE_INT) === false
        || (isset($argv[4]) && (!preg_match('/^[1-9][0-9]{0,2}$/D', $argv[4]) || (int) $argv[4] > 100))) {
        throw new InvalidArgumentException('Invalid history arguments.');
    }
    $history = new Tablo\CheckHistoryRepository(Tablo\Database::connect());
    $page = $history->page((int) $argv[1], $argv[2], $argv[3], isset($argv[4]) ? (int) $argv[4] : 50, $argv[5] ?? null);
    echo json_encode($page, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (OutOfBoundsException) {
    fwrite(STDERR, 'History site not found.' . PHP_EOL);
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, 'History read failed. Check arguments and local storage.' . PHP_EOL);
    exit(1);
}
