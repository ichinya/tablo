<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\JsonField;
use Testo\Assert;
use Testo\Test;

final class JsonNumericTest
{
    #[Test]
    public function preservesNativeIntegerFloatPrecisionAndCanonicalZero(): void
    {
        foreach (['9007199254740993' => 9_007_199_254_740_993, '003' => 3, '+42' => 42,
            ' 42 ' => 42, '-0' => 0, '1e3' => 1000.0, '1.0' => 1.0, '-0.0' => 0.0,
            '1e-300' => 1e-300] as $input => $expected) {
            $actual = JsonField::number((string) $input);
            Assert::same($actual, $expected);
            if (is_float($actual) && $actual === 0.0) { Assert::same(fdiv(1.0, $actual), INF); }
        }
        foreach (['', 'NaN', 'INF', '1e309'] as $input) {
            $refused = false;
            try { JsonField::number($input); }
            catch (\InvalidArgumentException) { $refused = true; }
            Assert::true($refused);
        }
    }
}
