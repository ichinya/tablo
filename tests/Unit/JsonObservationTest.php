<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use RuntimeException;

use Tablo\SiteChecker;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class JsonObservationTest extends JsonCheckFixtureCase
{


    #[Test]
    public function reportsUnknownForExtractionErrorsWithoutLeakingResponse(): void
    {
        foreach (['not JSON private-fixture', '{}', '{"value":null}', '{"value":[]}',
            '{"value":{}}', '{"value":1e400}', str_repeat('{"value":', times: 20) . '0' . str_repeat('}', times: 20)] as $body) {
            $state = $this->check($body, ['health_json_operator' => '!=', 'health_json_expected_value' => 'error']);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($state, 'online'), null, 'invalid/missing field is not a passing inequality');
            Assert::true(str_contains(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at($state, 'last_error')), 'Health:') && !str_contains(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at($state, 'last_error')), 'private-fixture'), 'safe health error');
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($state, 'deployed_commit'), 'abcdef1', 'independent version preserved');
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($state, 'open_issues'), 4, 'independent Git metrics preserved');
        }
        foreach (['false', '"not a number"', '"1e400"'] as $value) {
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($this->check('{"value":' . $value . '}', ['health_json_operator' => '>', 'health_json_expected_value' => '0']), 'online'), null);
        }
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($this->check('not JSON', [], status: 503), 'online'), 0, 'HTTP failure takes precedence');
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
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($state, 'deployed_version'), '1.2.3');
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($state, 'deployed_commit'), $commit);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($state, 'online'), 1);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($state, 'last_error'), null);
        }
        foreach (['{}', '{"build":null}', '{"build":false}', '{"build":2}', '{"build":""}',
            '{"build":[]}', '{"build":{}}', '{"build":"' . str_repeat('x', times: 201) . '"}', 'broken private-fixture'] as $body) {
            $state = $this->check('{"value":"ok"}', ['version_json_path' => '$.build'], $body);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($state, 'deployed_version'), null, 'invalid extracted version');
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($state, 'online'), 1, 'bad version did not erase valid health');
            Assert::true(str_contains(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at($state, 'last_error')), 'Version:') && !str_contains(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at($state, 'last_error')), 'private-fixture'));
        }
        $state = $this->check('{"value":"ok"}', [], '{"deployed_version":"1.2.3","deployed_commit":"abcdef1"}');
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($state, 'deployed_version'), '1.2.3', 'legacy aliases');
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($state, 'deployed_commit'), 'abcdef1');
    }
}
