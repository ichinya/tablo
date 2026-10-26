<?php
declare(strict_types=1);

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

// Test the copied bootstrap without changing the working installation's .env or database.
$root = \Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 1);
if (($argv[2] ?? '') === 'cold-composer') {
    environment_bootstrap_cold_composer($root, $argv[3] ?? '');
    exit;
}
if (getenv('TABLO_DOTENV_TEST_PROCESS') !== 'keep') {
    putenv('TABLO_DB');
    unset($_ENV['TABLO_DB'], $_SERVER['TABLO_DB']);
}
// Windows proc_open omits empty entries in its environment block; set the value in PHP.
if (getenv('TABLO_DOTENV_TEST_EMPTY') === '1') {
    putenv('TABLO_DB=');
    unset($_ENV['TABLO_DB'], $_SERVER['TABLO_DB']);
}
foreach (['TABLO_DOTENV_TEST_DIR', 'TABLO_DOTENV_TEST_VALUE', 'TABLO_DOTENV_TEST_LITERAL'] as $name) {
    putenv($name);
    unset($_ENV[$name], $_SERVER[$name]);
}
chdir($root . '/public');
if (($argv[2] ?? '') === 'external') {
    if (getenv('TABLO_TEST_KEY_MODE') === 'unset') { putenv('TABLO_TOKEN_KEY_FILE'); }
    if (getenv('TABLO_TEST_KEY_MODE') === 'empty') { putenv('TABLO_TOKEN_KEY_FILE'); putenv('TABLO_TOKEN_KEY_FILE='); }
    unset($_ENV['TABLO_TOKEN_KEY_FILE'], $_SERVER['TABLO_TOKEN_KEY_FILE']);
    // Windows removes empty putenv values. Preserve the explicit empty override
    // in dotenv's normal immutable reader too; getenv's absent/empty legacy policy agrees.
    if (getenv('TABLO_TEST_KEY_MODE') === 'empty') { $_ENV['TABLO_TOKEN_KEY_FILE'] = ''; }
}
require $root . '/app/bootstrap.php';

if (($argv[2] ?? '') === 'external') {
    $vault = Tablo\TokenVault::configured();
    $expectedKey = getenv('TABLO_TEST_KEY_MODE') === 'empty' ? '' : getenv('TABLO_TEST_EXPECTED_KEY_PATH');
    $actualKey = getenv('TABLO_TOKEN_KEY_FILE');
    echo json_encode(['matches' => ($actualKey === false ? '' : $actualKey) === $expectedKey,
        'legacy' => $vault === null, 'roundtrip' => $vault === null || $vault->decrypt($vault->encrypt('synthetic')) === 'synthetic']);
    exit;
}

if (($argv[2] ?? '') === 'check') {
    require $root . '/bin/check.php';
    exit;
}

$path = getenv('TABLO_DB');
$file = null;
if ($path !== false && $path !== '') {
    $database = Tablo\Database::connect();
    $file = \Tablo\Tests\Support\FixtureValue::fetched(\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($database->query('PRAGMA database_list'))->fetch(PDO::FETCH_ASSOC), \Tablo\SqlFileNameSeqRow::checked(...)))['file'];
}
echo json_encode(['path' => $path, 'file' => $file, 'value' => getenv('TABLO_DOTENV_TEST_VALUE'),
    'literal' => getenv('TABLO_DOTENV_TEST_LITERAL')], JSON_THROW_ON_ERROR) . PHP_EOL;

/** Exercises the real copied bootstrap in Composer's lexical include scope without opening a database. */
function environment_bootstrap_cold_composer(string $root, string $mode): void
{
    if (!in_array($mode, ['observe', 'inactive', 'missing-mode', 'wrong-argv'], strict: true)) {
        throw new RuntimeException('Finite copied-bootstrap mode refused.');
    }
    $before = class_exists(\Tablo\ColdTransferDiagnostic::class, autoload: false);
    if ($mode === 'missing-mode') { putenv('TABLO_TEST_COLD_R77'); }
    $_SERVER['argv'] = [$root . '/tests/server-child.php', 'transfer-working-data',
        $mode === 'wrong-argv' ? 'different-operation' : 'transfer-working-data'];
    $include = static function (string $fileIdentifier, string $file): bool {
        if ($fileIdentifier !== 'cold-composer') { throw new RuntimeException('Copied-bootstrap include refused.'); }
        $localArgv = ($argv ?? null) !== null;
        require $file;
        return $localArgv;
    };
    $closureArgv = $include(fileIdentifier: 'cold-composer', file: $root . '/app/bootstrap.php');
    $callback = in_array([\Tablo\ColdTransferDiagnostic::class, 'load'], spl_autoload_functions(), strict: true);
    echo json_encode(['before' => $before, 'closureArgv' => $closureArgv,
        'loaded' => class_exists(\Tablo\ColdTransferDiagnostic::class, autoload: false), 'callback' => $callback,
        'sidecar' => is_file($root . '/evidence/cold-r77.json')], JSON_THROW_ON_ERROR);
}
