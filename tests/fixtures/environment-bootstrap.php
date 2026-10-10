<?php
declare(strict_types=1);

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

// Test the copied bootstrap without changing the working installation's .env or database.
$root = $argv[1];
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
    $file = $database->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'];
}
echo json_encode(['path' => $path, 'file' => $file, 'value' => getenv('TABLO_DOTENV_TEST_VALUE'),
    'literal' => getenv('TABLO_DOTENV_TEST_LITERAL')], JSON_THROW_ON_ERROR) . PHP_EOL;
