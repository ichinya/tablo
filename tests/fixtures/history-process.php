<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Tablo\Database;
use Tablo\GitTokenRepository;
use Tablo\SiteRepository;

[$script, $path, $id, $action] = $argv;
$db = Database::connect($path);
$sites = new SiteRepository($db);
$site = $sites->find((int) $id);
$input = array_intersect_key($site, SiteRepository::defaults());
$input['git_token_id'] = $site['git_token_id'] ?? '';
if ($action === 'field') {
    $input[$argv[4]] = json_decode($argv[5], true, 8, JSON_THROW_ON_ERROR);
    $sites->save($input, (int) $id);
} elseif ($action === 'aba') {
    $sites->save(array_replace($input, ['enabled' => 0]), (int) $id);
    $sites->save($input, (int) $id);
} elseif ($action === 'manual') {
    $sites->save(array_replace($input, ['git_token_id' => '', 'github_token' => bin2hex(random_bytes(24))]), (int) $id);
} elseif ($action === 'remove') {
    $sites->save(array_replace($input, ['git_token_id' => '', 'remove_github_token' => 1]), (int) $id);
} elseif ($action === 'shared' || $action === 'rename') {
    (new GitTokenRepository($db))->save(['name' => 'Shared ' . $id, 'provider' => 'github',
        'token' => $action === 'shared' ? bin2hex(random_bytes(24)) : ''], $site['git_token_id']);
} elseif ($action === 'delete') {
    $sites->delete((int) $id);
} elseif ($action === 'store') {
    if (!$sites->storeCheck($site, ['checked_at' => $argv[4], 'online' => 1])) { throw new RuntimeException('Fixture acceptance refused.'); }
} else { throw new RuntimeException('Invalid history fixture action.'); }
unset($sites, $site, $db);
echo "History child complete.\n";
