<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\NotificationResult;
use Testo\Assert;
use Testo\Test;

final class NotificationResultTest
{
    #[Test]
    public function validatedFieldsKeepAbsenceNullAndUnknownData(): void
    {
        $extra = new \stdClass();
        foreach ([['code' => 'timeout', 'extra' => $extra], ['code' => 'timeout', 'http_status' => null, 'extra' => $extra],
            ['code' => 'sent', 'http_status' => 204, 'extra' => $extra]] as $result) {
            Assert::same(NotificationResult::checked($result), $result);
        }
        foreach ([['code' => 'sent'], ['code' => 'timeout', 'http_status' => 0],
            ['code' => 'timeout', 'http_status' => false], ['code' => 'timeout', 'http_status' => '204']] as $result) {
            $refused = false;
            try { NotificationResult::checked($result); } catch (\InvalidArgumentException) { $refused = true; }
            Assert::true($refused);
        }
    }
}
