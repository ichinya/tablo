<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Only creates explicit browser-test fixtures, never the installation database.
$path = $argv[1] ?? dirname(__DIR__) . '/artifacts/browser.sqlite';
if (file_exists($path)) {
    throw new RuntimeException('Browser fixture already exists; do not overwrite it.');
}
$db = Tablo\Database::connect($path);
$sites = new Tablo\SiteRepository($db);
$token = (new Tablo\GitTokenRepository($db))->save([
    'name' => 'Демонстрационный токен', 'provider' => 'github', 'token' => 'fixture-token',
]);
$rows = [
    ['Storefront', 'https://store.example.com', 'example/storefront', 'release', 1, 1, '1.3.1', 'v1.3.2', 'a1b2c3d', 4, 1, 87],
    ['Portal', 'https://portal.example.com', 'example/portal', 'branch', 1, 1, null, null, 'b2c3d4e', 7, 0, 42],
    ['Documentation', 'https://docs.example.com', 'example/documentation', 'release', 1, 1, '2.0.0', 'v2.0.0', 'c3d4e5f', 12, 2, 63],
    ['Service API', 'https://api.example.com', 'example/service-api', 'release', 1, 0, '0.8.0', 'v0.8.0', 'd4e5f6a', 3, 0, 204],
    ['Demo Project', 'https://demo.example.com', 'example/demo-project', 'branch', 0, null, null, null, null, null, null, null],
];
foreach ($rows as [$name, $url, $repository, $mode, $enabled, $online, $version, $release, $commit, $issues, $prs, $ms]) {
    $id = $sites->save(array_replace(Tablo\SiteRepository::defaults(), ['name' => $name, 'url' => $url,
        'repository' => $repository, 'comparison_mode' => $mode, 'enabled' => $enabled,
        'git_token_id' => $token, 'version_path' => $mode === 'branch' ? '' : '/version']));
    $sites->storeCheck($sites->find($id), ['online' => $online, 'deployed_version' => $version,
        'latest_release' => $release, 'deployed_commit' => $commit,
        'latest_commit' => $commit ? $commit . str_repeat('0', 33) : null,
        'open_issues' => $issues, 'open_prs' => $prs, 'response_time_ms' => $ms,
        'last_error' => $online === 0 ? 'Health: HTTP 503 (пример тестового состояния).' : null,
        'checked_at' => $enabled ? gmdate('Y-m-d\TH:i:s\Z') : null]);
}
echo "Created browser fixtures with synthetic states in " . $path . ".\n";
