<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Tests\Support\FixtureWireRows;
use Testo\Assert;
use Testo\Test;

final class FixtureWireRowsTest
{
    #[Test]
    public function observedWireValuesAndExtraFieldsAreRetained(): void
    {
        $extra = ['null' => null, 'false' => false, 'zero' => 0, 'empty' => ''];
        $redirect = ['route' => '', 'bearer' => false, 'authorized' => false, 'unexpected_headers' => true,
            'method_get' => true, 'request_line' => ''] + $extra;
        Assert::same(FixtureWireRows::redirect(json_encode($redirect, JSON_THROW_ON_ERROR)), $redirect);
        $endpoint = ['path' => '0', 'present' => false, 'a' => false, 'b' => true, 'git' => false] + $extra;
        Assert::same(FixtureWireRows::endpoint(json_encode($endpoint, JSON_THROW_ON_ERROR)), $endpoint);
        $webhook = ['count' => 0, 'exact' => false, 'authorization' => false, 'same_key' => true, 'redirect_targets' => 0] + $extra;
        Assert::same(FixtureWireRows::webhook(json_encode($webhook, JSON_THROW_ON_ERROR)), $webhook);
    }

    #[Test]
    public function malformedMissingNullAndWrongWireFieldsRefuseAdmission(): void
    {
        foreach ([FixtureWireRows::redirect(...), FixtureWireRows::endpoint(...), FixtureWireRows::webhook(...)] as $read) {
            foreach (['null', '{}', '{', '{"route":false}', '{"path":null}', '{"count":"0"}'] as $json) {
                $caught = null;
                try { $read($json); } catch (\RuntimeException|\JsonException $error) { $caught = $error; }
                Assert::true($caught !== null);
            }
        }
    }
}
