<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/RuntimeIni.php';
\Tablo\RuntimeIni::set('display_errors', '0');
\Tablo\RuntimeIni::set('log_errors', '0');
set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) { return true; }
    throw new RuntimeException('Database maintenance failed.');
});
try {
    /** @var list<string> $argv */
    $arguments = array_slice($argv, offset: 1);
    if ($arguments === ['--help']) {
        fwrite(STDOUT, data: "Usage: php bin/database.php --init --receipt <private-file>|--migrate|--status\n"); exit(0);
    }
    require dirname(__DIR__) . '/vendor/autoload.php';
    exit(\Tablo\DatabaseMaintenanceCommand::run($arguments));
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof \Tablo\DatabaseFailure ? $error->getMessage() . "\n" : "Database maintenance failed.\n");
    exit(1);
}
