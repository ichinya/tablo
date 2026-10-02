<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Only creates explicit browser-test fixtures, never the installation database.
$path = dirname(__DIR__) . '/artifacts/browser.sqlite';
if (file_exists($path)) {
    throw new RuntimeException('Browser fixture already exists; do not overwrite it.');
}
$db = Tablo\Database::connect($path);
$sites = new Tablo\SiteRepository($db);
$rows = [
    ['Tempalog', 'https://tempalog.example', 'ichinya/tempalog', 'release', 1, 1, '1.3.1', 'v1.3.2', 'a61de82', 4, 1, 87],
    ['Lagomer', 'https://lagomer.example', 'ichinya/lagomer', 'branch', 1, 1, null, null, 'a61de82', 7, 0, 42],
    ['Lekalo', 'https://lekalo.example', 'ichinya/lekalo', 'release', 1, 1, '0.6.3', 'v0.6.3', 'bff005e', 12, 2, 63],
    ['Anthill', 'https://anthill.example', 'ichinya/anthill', 'release', 1, 0, '0.8.0', 'v0.8.0', '832ab3d', 3, 0, 204],
    ['Ariel', 'https://ariel.example', 'ichinya/ariel', 'branch', 0, null, null, null, null, null, null, null],
];
foreach ($rows as [$name, $url, $repository, $mode, $enabled, $online, $version, $release, $commit, $issues, $prs, $ms]) {
    $id = $sites->save(array_replace(Tablo\SiteRepository::defaults(), ['name' => $name, 'url' => $url,
        'repository' => $repository, 'comparison_mode' => $mode, 'enabled' => $enabled, 'version_path' => '/version']));
    $sites->storeCheck($sites->find($id), ['online' => $online, 'deployed_version' => $version,
        'latest_release' => $release, 'deployed_commit' => $commit,
        'latest_commit' => $commit ? $commit . str_repeat('0', 33) : null,
        'open_issues' => $issues, 'open_prs' => $prs, 'response_time_ms' => $ms,
        'last_error' => $online === 0 ? 'Health: HTTP 503 (пример тестового состояния).' : null,
        'checked_at' => $enabled ? gmdate('Y-m-d\TH:i:s\Z') : null]);
}
echo "Created browser fixtures with synthetic states in artifacts/browser.sqlite.\n";
