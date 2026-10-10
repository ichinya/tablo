<?php
declare(strict_types=1);

// Explicit operation after source review; verification never invokes this script.
require __DIR__ . '/reviewed-dependencies.php';
$root = dirname(__DIR__);
$files = [];
foreach (requiredReviewedDependencies() as $path => $ownership) {
    if (!is_file($root . '/' . $path)) { throw new RuntimeException('Missing reviewed dependency: ' . $path); }
    $files[] = ['path' => $path, ...$ownership, 'fingerprint' => 'sha256:' . hash_file('sha256', $root . '/' . $path)];
}
$written = file_put_contents($root . '/contracts/reviewed-dependencies.json', json_encode([
    'schema' => 'tablo/reviewed-dependencies/v1', 'dependencies' => $files,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
if ($written === false) { throw new RuntimeException('Cannot capture reviewed dependencies.'); }
echo 'Captured ' . count($files) . " reviewed dependencies; review contracts/reviewed-dependencies.json before accepting.\n";
