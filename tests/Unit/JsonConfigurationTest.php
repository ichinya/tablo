<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use Tablo\Auth;
use Tablo\Database;
use Tablo\GitTokenRepository;

use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\SiteInput;
use Tablo\TokenVault;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class JsonConfigurationTest extends JsonCheckFixtureCase
{


    #[Test]
    public function validatesActiveSettingsAndSkipsDisabledVersion(): void
    {
        $base = array_replace(UnitFixtures::site(), ['health_check_mode' => 'json', 'health_json_path' => '$.value']);
        foreach (['', 'value', '$.', '$..value', '$[*]', '$[01]', '$[-1]', '$[999999999999999999999999999999]',
            '$["unterminated]', '$["bad\z"]', '$.value()', '$.value trailing', '$.' . str_repeat('x', times: 512)] as $path) {
            (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(static fn () => SiteInput::normalize(array_replace($base, ['health_json_path' => $path])))))->verify('bad JSON path');
        }
        foreach ([
            ['health_check_mode' => 'unknown'], ['health_check_mode' => []],
            ['health_json_operator' => '==='], ['health_json_operator' => []], ['health_json_path' => []],
            ['health_json_expected_value' => []], ['health_json_expected_value' => str_repeat('x', times: 513)],
            ['health_json_operator' => '>', 'health_json_expected_value' => 'no'],
            ['health_json_operator' => '<=', 'health_json_expected_value' => '1e400'],
            ['version_json_path' => 'invalid'], ['version_json_path' => []],
        ] as $settings) {
            (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(static fn () => SiteInput::normalize(array_replace($base, $settings)))))->verify('bad settings');
        }
        Assert::same(SiteInput::normalize(array_replace($base, ['version_json_path' => '  ']))['version_json_path'], '', 'blank means automatic');
        $disabled = SiteInput::normalize(array_replace($base, ['version_path' => '', 'version_json_path' => 'invalid']));
        $http = new FakeHttp([UnitFixtures::response(200, '{"value":""}')]);
        $disabled['health_json_expected_value'] = '';
        $state = (new SiteChecker($http, new FakeProvider()))->check($disabled);
        Assert::same(count($http->requests), 1, 'disabled version not requested');
        Assert::same($state['last_error'], null);
        Assert::same($state['online'], 1);
        Assert::same(SiteInput::normalize(array_replace($base, ['health_check_mode' => 'http', 'health_json_path' => 'invalid']))['health_check_mode'], 'http', 'inactive health selector ignored');
    }



    #[Test]
    public function persistsSettingsAndRejectsEveryStaleJsonConfiguration(): void
    {
        $temporary = new TemporaryDirectory('tablo-json-storage-');
        try {
            $db = Database::connect(':memory:');
            $sites = new SiteRepository($db, new TokenVault($temporary->path . '/key'));
            $input = array_replace(UnitFixtures::site(), ['health_check_mode' => 'json', 'health_json_path' => '$.status',
                'health_json_operator' => '==', 'health_json_expected_value' => ' ok ', 'version_json_path' => '$.build.version']);
            $id = $sites->save($input);
            foreach (['health_check_mode', 'health_json_path', 'health_json_operator', 'health_json_expected_value', 'version_json_path'] as $field) {
                Assert::same($sites->find($id)[$field], $input[$field], 'stored ' . $field);
                Assert::same($sites->all()[0][$field], $input[$field], 'listed ' . $field);
            }
            foreach (['health_check_mode' => 'http', 'health_json_path' => '$.other', 'health_json_operator' => '!=',
                'health_json_expected_value' => 'different', 'version_json_path' => '$.other.version'] as $field => $value) {
                $sites->save($input, $id);
                $snapshot = $sites->find($id);
                $sites->save(array_replace($input, [$field => $value]), $id);
                Assert::same($sites->storeCheck($snapshot, ['online' => 1]), false, 'stale ' . $field);
                Assert::same($sites->storeCheck($sites->find($id), ['online' => 1, 'checked_at' => '2026-01-01T00:00:00Z']), true, 'fresh ' . $field);
            }
        } finally {
            unset($sites, $db);
            $temporary->close();
        }
    }



    #[Test]
    public function migratesPreJsonDatabaseWithoutChangingCredentialsOrEndpoints(): void
    {
        $temporary = new TemporaryDirectory('tablo-json-upgrade-');
        try {
            $path = $temporary->path . '/test.sqlite';
            $old = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $schema = file_get_contents(dirname(__DIR__, levels: 2) . '/database/schema.sql');
            $old->exec(preg_replace('/^\s*(?:version_json_path|health_check_mode|health_json_path|health_json_operator|health_json_expected_value) TEXT.*\R/m', replacement: '', subject: $schema));
            (new Auth($old))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            $hash = $old->query('SELECT password_hash FROM users')->fetchColumn();
            $vault = new TokenVault($temporary->path . '/key');
            $cipher = $vault->encrypt(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token'));
            $tokens = new GitTokenRepository($old, $vault);
            $token = $tokens->save(['name' => 'Fixture', 'provider' => 'github', 'token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'shared-fixture-token')]);
            $old->prepare("INSERT INTO sites (name,url,repository,health_path,version_path,github_token) VALUES ('Legacy','https://example.com','fixture/public','/up','/version',?)")->execute([$cipher]);
            $old->prepare("INSERT INTO sites (name,url,repository,git_token_id) VALUES ('Shared','https://example.com','fixture/public',?)")->execute([$token]);
            $keyHash = hash_file('sha256', $temporary->path . '/key');
            unset($tokens, $old);
            $db = Database::connect($path);
            Database::migrate($db);
            $sites = new SiteRepository($db, $vault);
            Assert::same(count($sites->all()), 2);
            foreach ($sites->all() as $site) {
                Assert::same($site['health_check_mode'], 'http');
                Assert::same($site['health_json_operator'], '==');
                foreach (['health_json_path', 'health_json_expected_value', 'version_json_path'] as $field) {
                    Assert::same($site[$field], '');
                }
            }
            Assert::same($sites->find(1)['version_path'], '/version');
            Assert::same($sites->find(1)['github_token'], $cipher);
            Assert::same($sites->tokenFor($sites->find(1)), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token'));
            Assert::same($sites->tokenFor($sites->find(2)), \Tablo\Tests\Support\FixtureCredentials::value(label: 'shared-fixture-token'));
            Assert::same($db->query('SELECT password_hash FROM users')->fetchColumn(), $hash);
            Assert::same(hash_file('sha256', $temporary->path . '/key'), $keyHash);
        } finally {
            unset($old, $tokens, $sites, $db, $vault);
            $temporary->close();
        }
    }
}
