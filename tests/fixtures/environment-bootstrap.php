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
require $root . '/app/bootstrap.php';

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
