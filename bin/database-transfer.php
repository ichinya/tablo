<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/RuntimeIni.php';
\Tablo\RuntimeIni::set('display_errors', '0');
\Tablo\RuntimeIni::set('log_errors', '0');
set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) { return true; }
    throw new RuntimeException('Database transfer failed.');
});
try {
    /** @var list<string> $argv */
    $arguments = array_slice($argv, offset: 1);
    if ($arguments === ['--help']) {
        fwrite(STDOUT, data: "Usage: php bin/database-transfer.php --preflight|--export|--import|--verify --offline --archive <private-directory> --scratch <new-private-file> [--sqlite-target <new-file>]\n");
        exit(0);
    }
    require dirname(__DIR__) . '/vendor/autoload.php';
    $action = array_shift($arguments);
    if (!in_array($action, ['--preflight', '--export', '--import', '--verify'], strict: true) || !in_array('--offline', $arguments, strict: true)) {
        throw new \Tablo\DatabaseFailure('configuration');
    }
    $options = [];
    while ($arguments !== []) {
        $name = array_shift($arguments);
        if ($name === '--offline') { continue; }
        $value = array_shift($arguments);
        if (!in_array($name, ['--archive', '--scratch', '--sqlite-target'], strict: true) || !is_string($value) || array_key_exists($name, $options)) {
            throw new \Tablo\DatabaseFailure('configuration');
        }
        $options[$name] = $value;
    }
    $vault = \Tablo\TokenVault::configured() ?? throw new \Tablo\DatabaseFailure('key');
    $scratch = \Tablo\SqlColumn::string($options, '--scratch');
    $directory = \Tablo\SqlColumn::string($options, '--archive');
    if (array_key_exists('--sqlite-target', $options) && $action !== '--import') { throw new \Tablo\DatabaseFailure('configuration'); }
    $configuration = \Tablo\DatabaseConfiguration::fromEnvironment($action === '--import' ? 'migrator' : 'writer');
    if ($configuration->engine === 'sqlite' && !array_key_exists('--sqlite-target', $options)
        && (!is_string(getenv('TABLO_DB')) || getenv('TABLO_DB') === '')) { throw new \Tablo\DatabaseFailure('configuration'); }
    if ($action === '--import' && array_key_exists('--sqlite-target', $options)) {
        $result = \Tablo\DatabaseTransfer::importSqlite($vault, $directory, $scratch, $options['--sqlite-target'], offline: true);
        fwrite(STDOUT, \Tablo\JsonEncoding::encode(['operation' => $action, 'status' => 'passed', 'manifestOrRows' => $result]) . "\n");
        exit(0);
    }
    if ($action === '--import' && !$configuration->server()) { throw new \Tablo\DatabaseFailure('configuration'); }
    $db = $configuration->server()
        ? \Tablo\DatabaseConnectionFactory::open($configuration, mode: $action === '--import' ? 'maintenance' : 'transfer-source', vault: $vault)
        : \Tablo\ExistingConnection::openExistingConnection(null);
    $result = match ($action) {
        '--preflight' => \Tablo\DatabaseTransfer::preflight($db, $vault, $scratch, offline: true),
        '--export' => \Tablo\DatabaseTransfer::export($db, $vault, $directory, $scratch, offline: true),
        '--import' => \Tablo\DatabaseTransfer::importServer($db, $vault, $directory, $scratch, offline: true),
        default => \Tablo\DatabaseTransfer::verify($db, $vault, $directory, $scratch),
    };
    fwrite(STDOUT, \Tablo\JsonEncoding::encode(['operation' => $action, 'status' => 'passed', 'manifestOrRows' => $result]) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof \Tablo\DatabaseFailure ? $error->getMessage() . "\n" : "Database transfer failed.\n");
    exit(1);
}
