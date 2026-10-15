<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Tablo\Tests\Support\TrustedProxy;
use Testo\Assert;
use Testo\Test;

final class TrustedProxyObservationTest
{
    #[Test]
    public function preservesActualEmptyStringsAndExtraObservationValues(): void
    {
        $row = ['peer' => '', 'xff' => '0', 'outbound_source' => '', 'null' => null, 'false' => false, 'zero' => 0];
        Assert::same(TrustedProxy::observation(json_encode($row, JSON_THROW_ON_ERROR)), $row);
    }

    #[Test]
    public function missingNullFalseAndMalformedFieldsFailAdmission(): void
    {
        foreach (['{}', 'null', '{', '{"peer":null,"xff":"","outbound_source":""}',
            '{"peer":"","xff":false,"outbound_source":""}', '{"peer":"","xff":"","outbound_source":0}'] as $json) {
            $caught = null;
            try { TrustedProxy::observation($json); } catch (\RuntimeException|\JsonException $error) { $caught = $error; }
            Assert::true($caught !== null);
        }
    }
}
