<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use RuntimeException;
use Tablo\Auth;
use Tablo\Database;
use Tablo\GitTokenRepository;

use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class JsonChecksTest
{
    private function check(string $body, array $settings = [], string $version = '{"version":"1.2.3","commit":"abcdef1"}', int $status = 200): array
    {
        $site = array_replace(UnitFixtures::site(), ['health_check_mode' => 'json', 'health_json_path' => '$.value',
            'health_json_operator' => '==', 'health_json_expected_value' => 'ok'], $settings);
        return (new SiteChecker(new FakeHttp([UnitFixtures::response($status, $body),
            UnitFixtures::response(200, $version)]), new FakeProvider()))->check($site);
    }

    #[Test]
    public function evaluatesOperatorsBoundariesAndScalarRepresentations(): void
    {
        $cases = [
            ['>', 1, '0', true], ['>', 0, '0', false],
            ['>=', 3, '3', true], ['>=', 2, '3', false],
            ['<', 5, '10', true], ['<', 10, '10', false],
            ['<=', 200, '200', true], ['<=', 201, '200', false],
            ['!=', 'ok', 'error', true], ['!=', 'error', 'error', false],
            ['==', 'ok', 'ok', true], ['==', 'error', 'ok', false],
            ['contains', 'service ready', 'ready', true], ['contains', 'service starting', 'ready', false],
            ['contains', 'service READY', 'ready', false], ['==', 'OK', 'ok', false],
            ['==', 0, '0', true], ['==', false, 'false', true], ['==', true, 'true', true],
            ['==', '', '', true], ['==', ' ', '', false], ['==', ' ok ', ' ok ', true],
            ['==', 1.0, '1', true], ['==', 1, '1.0', false], ['==', 1.25, '1.25', true],
            ['>=', '3', '3', true], ['<', -1.5, '-1', true], ['>', 2, '1e0', true],
            ['>', 9_007_199_254_740_993, '9007199254740992', true],
        ];
        foreach ($cases as [$operator, $value, $expected, $passes]) {
            $state = $this->check(json_encode(['value' => $value], JSON_THROW_ON_ERROR),
                ['health_json_operator' => $operator, 'health_json_expected_value' => $expected]);
            Assert::same($state['online'], (int) $passes, 'condition ' . $operator . ' against ' . $expected);
            Assert::same($state['last_error'] === null, $passes, 'false comparisons report health failure');
            Assert::same($state['deployed_version'], '1.2.3', 'comparison did not stop version');
        }
    }

    #[Test]
    public function selectsRootNestedArrayAndQuotedKeys(): void
    {
        $body = json_encode(['checks' => [['status' => 'ok']], 'service.status' => 'ok',
            'a"b' => 'ok', 'a\b' => 'ok', '0' => 'ok', 'ключ' => 'ok', '' => 'ok'], JSON_THROW_ON_ERROR);
        foreach (['$.checks[0].status', '$["service.status"]', '$["a\"b"]', '$["a\\\\b"]',
            '$["0"]', '$["ключ"]', '$[""]'] as $path) {
            Assert::same($this->check($body, ['health_json_path' => $path])['online'], 1, $path);
        }
        Assert::same($this->check('"ok"', ['health_json_path' => '$'])['online'], 1, 'root scalar');
        Assert::same($this->check('["ok"]', ['health_json_path' => '$[0]'])['online'], 1, 'root array element');
        Assert::same($this->check('{"0":"ok"}', ['health_json_path' => '$[0]'])['online'], null, 'object is not an array');
        Assert::same($this->check('["ok"]', ['health_json_path' => '$["0"]'])['online'], null, 'array is not an object');
    }

    #[Test]
    public function reportsUnknownForExtractionErrorsWithoutLeakingResponse(): void
    {
        foreach (['not JSON private-fixture', '{}', '{"value":null}', '{"value":[]}',
            '{"value":{}}', '{"value":1e400}', str_repeat('{"value":', times: 20) . '0' . str_repeat('}', times: 20)] as $body) {
            $state = $this->check($body, ['health_json_operator' => '!=', 'health_json_expected_value' => 'error']);
            Assert::same($state['online'], null, 'invalid/missing field is not a passing inequality');
            Assert::true(str_contains($state['last_error'], 'Health:') && !str_contains($state['last_error'], 'private-fixture'), 'safe health error');
            Assert::same($state['deployed_commit'], 'abcdef1', 'independent version preserved');
            Assert::same($state['open_issues'], 4, 'independent Git metrics preserved');
        }
        foreach (['false', '"not a number"', '"1e400"'] as $value) {
            Assert::same($this->check('{"value":' . $value . '}', ['health_json_operator' => '>', 'health_json_expected_value' => '0'])['online'], null);
        }
        Assert::same($this->check('not JSON', [], status: 503)['online'], 0, 'HTTP failure takes precedence');
        $site = array_replace(UnitFixtures::site(), ['health_check_mode' => 'json', 'health_json_path' => '$.value']);
        $state = (new SiteChecker(new FakeHttp([new RuntimeException('timeout'), UnitFixtures::response(200, '{"version":"1.2.3"}')]), new FakeProvider()))->check($site);
        Assert::same($state['online'], null, 'transport failures remain unknown');
        Assert::same($state['deployed_version'], '1.2.3');
    }

    #[Test]
    public function extractsVersionByPathAndRetainsLegacyCommit(): void
    {
        foreach ([
            ['{"build":{"version":"1.2.3"},"sha":"ABCDEF1"}', '$.build.version', 'abcdef1'],
            ['{"builds":[{"version":"1.2.3"}]}', '$.builds[0].version', null],
            ['{"build.version":"1.2.3","deployed_commit":"abcdef1"}', '$["build.version"]', 'abcdef1'],
            ['"1.2.3"', '$', null],
        ] as [$body, $path, $commit]) {
            $state = $this->check('{"value":"ok"}', ['version_json_path' => $path], $body);
            Assert::same($state['deployed_version'], '1.2.3');
            Assert::same($state['deployed_commit'], $commit);
            Assert::same($state['online'], 1);
            Assert::same($state['last_error'], null);
        }
        foreach (['{}', '{"build":null}', '{"build":false}', '{"build":2}', '{"build":""}',
            '{"build":[]}', '{"build":{}}', '{"build":"' . str_repeat('x', times: 201) . '"}', 'broken private-fixture'] as $body) {
            $state = $this->check('{"value":"ok"}', ['version_json_path' => '$.build'], $body);
            Assert::same($state['deployed_version'], null, 'invalid extracted version');
            Assert::same($state['online'], 1, 'bad version did not erase valid health');
            Assert::true(str_contains($state['last_error'], 'Version:') && !str_contains($state['last_error'], 'private-fixture'));
        }
        $state = $this->check('{"value":"ok"}', [], '{"deployed_version":"1.2.3","deployed_commit":"abcdef1"}');
        Assert::same($state['deployed_version'], '1.2.3', 'legacy aliases');
        Assert::same($state['deployed_commit'], 'abcdef1');
    }

    #[Test]
    public function validatesActiveSettingsAndSkipsDisabledVersion(): void
    {
        $base = array_replace(UnitFixtures::site(), ['health_check_mode' => 'json', 'health_json_path' => '$.value']);
        foreach (['', 'value', '$.', '$..value', '$[*]', '$[01]', '$[-1]', '$[999999999999999999999999999999]',
            '$["unterminated]', '$["bad\z"]', '$.value()', '$.value trailing', '$.' . str_repeat('x', times: 512)] as $path) {
            UnitFixtures::rejects(static fn () => SiteRepository::normalize(array_replace($base, ['health_json_path' => $path])), 'bad JSON path');
        }
        foreach ([
            ['health_check_mode' => 'unknown'], ['health_check_mode' => []],
            ['health_json_operator' => '==='], ['health_json_operator' => []], ['health_json_path' => []],
            ['health_json_expected_value' => []], ['health_json_expected_value' => str_repeat('x', times: 513)],
            ['health_json_operator' => '>', 'health_json_expected_value' => 'no'],
            ['health_json_operator' => '<=', 'health_json_expected_value' => '1e400'],
            ['version_json_path' => 'invalid'], ['version_json_path' => []],
        ] as $settings) {
            UnitFixtures::rejects(static fn () => SiteRepository::normalize(array_replace($base, $settings)), 'bad settings');
        }
        Assert::same(SiteRepository::normalize(array_replace($base, ['version_json_path' => '  ']))['version_json_path'], '', 'blank means automatic');
        $disabled = SiteRepository::normalize(array_replace($base, ['version_path' => '', 'version_json_path' => 'invalid']));
        $http = new FakeHttp([UnitFixtures::response(200, '{"value":""}')]);
        $disabled['health_json_expected_value'] = '';
        $state = (new SiteChecker($http, new FakeProvider()))->check($disabled);
        Assert::same(count($http->requests), 1, 'disabled version not requested');
        Assert::same($state['last_error'], null);
        Assert::same($state['online'], 1);
        Assert::same(SiteRepository::normalize(array_replace($base, ['health_check_mode' => 'http', 'health_json_path' => 'invalid']))['health_check_mode'], 'http', 'inactive health selector ignored');
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
                Assert::same($sites->storeCheck($sites->find($id), ['online' => 1]), true, 'fresh ' . $field);
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
            (new Auth($old))->setup('fixture-password', 'fixture-password');
            $hash = $old->query('SELECT password_hash FROM users')->fetchColumn();
            $vault = new TokenVault($temporary->path . '/key');
            $cipher = $vault->encrypt('fixture-token');
            $tokens = new GitTokenRepository($old, $vault);
            // Synthetic fixture credentials; never valid for a real service.
            // @mago-expect lint:no-literal-password
            $token = $tokens->save(['name' => 'Fixture', 'provider' => 'github', 'token' => 'shared-fixture-token']);
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
            Assert::same($sites->tokenFor($sites->find(1)), 'fixture-token');
            Assert::same($sites->tokenFor($sites->find(2)), 'shared-fixture-token');
            Assert::same($db->query('SELECT password_hash FROM users')->fetchColumn(), $hash);
            Assert::same(hash_file('sha256', $temporary->path . '/key'), $keyHash);
        } finally {
            unset($old, $tokens, $sites, $db, $vault);
            $temporary->close();
        }
    }
}
