<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Explicit synthetic database only; callers own the environment, key and path.
$path = $argv[1] ?? throw new RuntimeException('An owned browser fixture database path is required.');
if (file_exists($path)) { throw new RuntimeException('Browser fixture already exists; do not overwrite it.'); }
$db = Tablo\Database::connect($path);
\Tablo\Tests\Support\DashboardModesFixture::seed($db, set: 'demo');
$db = null;
echo "Created neutral browser fixtures in " . $path . ".\n";
