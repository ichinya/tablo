<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;


use Testo\Assert;
use Testo\Test;

final class JsonChecksTest extends JsonCheckFixtureCase
{


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
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($state, 'online'), (int) $passes, 'condition ' . $operator . ' against ' . $expected);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($state, 'last_error') === null, $passes, 'false comparisons report health failure');
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($state, 'deployed_version'), '1.2.3', 'comparison did not stop version');
        }
    }



    #[Test]
    public function selectsRootNestedArrayAndQuotedKeys(): void
    {
        $body = json_encode(['checks' => [['status' => 'ok']], 'service.status' => 'ok',
            'a"b' => 'ok', 'a\b' => 'ok', '0' => 'ok', 'ключ' => 'ok', '' => 'ok'], JSON_THROW_ON_ERROR);
        foreach (['$.checks[0].status', '$["service.status"]', '$["a\"b"]', '$["a\\\\b"]',
            '$["0"]', '$["ключ"]', '$[""]'] as $path) {
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($this->check($body, ['health_json_path' => $path]), 'online'), 1, $path);
        }
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($this->check('"ok"', ['health_json_path' => '$']), 'online'), 1, 'root scalar');
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($this->check('["ok"]', ['health_json_path' => '$[0]']), 'online'), 1, 'root array element');
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($this->check('{"0":"ok"}', ['health_json_path' => '$[0]']), 'online'), null, 'object is not an array');
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($this->check('["ok"]', ['health_json_path' => '$["0"]']), 'online'), null, 'array is not an object');
    }
}
