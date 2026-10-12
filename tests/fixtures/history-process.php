<?php
declare(strict_types=1);

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

use Tablo\Database;
use Tablo\GitTokenRepository;
use Tablo\SiteRepository;
use Tablo\SiteInput;
use Tablo\TokenVault;

$path = \Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 1);
$id = \Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 2);
$action = \Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 3);
$selectedKeyPath = getenv('TABLO_GITHUB_TOKEN_KEY');
$vault = new TokenVault($selectedKeyPath ? $selectedKeyPath : dirname(\Tablo\Tests\Support\FixtureValue::string($path)) . '/github-token.key');
$db = Database::connect($path, $vault);
$sites = new SiteRepository($db, $vault);
$site = $sites->find((int) $id);
$input = array_intersect_key(\Tablo\Tests\Support\FixtureValue::row($site), SiteInput::defaults());
$input['git_token_id'] = $site['git_token_id'] ?? '';
switch (true) {
case ($action === 'field'):

    $input[\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 4)] = json_decode(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 5), associative: true, depth: 8, flags: JSON_THROW_ON_ERROR);
    $sites->save($input, (int) $id);

    break;
case ($action === 'aba'):

    $sites->save(array_replace($input, ['enabled' => 0]), (int) $id);
    $sites->save($input, (int) $id);

    break;
case ($action === 'manual'):

    $sites->save(array_replace($input, ['git_token_id' => '', 'github_token' => bin2hex(random_bytes(24))]), (int) $id);

    break;
case ($action === 'remove'):

    $sites->save(array_replace($input, ['git_token_id' => '', 'remove_github_token' => \Tablo\FormCheckbox::INTEGER_VALUE]), (int) $id);

    break;
case ($action === 'shared' || $action === 'rename'):

    (new GitTokenRepository($db, $vault))->save(['name' => 'Shared ' . $id, 'provider' => 'github',
        'token' => $action === 'shared' ? bin2hex(random_bytes(24)) : ''], \Tablo\Tests\Support\FixtureValue::row($site)['git_token_id']);

    break;
case ($action === 'delete'):

    $sites->delete((int) $id);

    break;
case ($action === 'store'):

    if (!$sites->storeCheck(\Tablo\Tests\Support\FixtureValue::row($site), ['checked_at' => \Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 4), 'online' => 1])) { throw new RuntimeException('Fixture acceptance refused.'); }

    break;
default:
 throw new RuntimeException('Invalid history fixture action.');
}
unset($sites, $site, $db);
echo "History child complete.\n";
