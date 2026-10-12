<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\EndpointPrivacyChild;
use Testo\Assert;
use Testo\Test;

final class EndpointRedirectPrivacyTest
{
    #[Test]
    public function endpointBoundaryDiscardsRealNativeFaultFramesAndRetainsHostileControls(): void
    {
        foreach (['redirect-safe', 'redirect-native'] as $mode) {
            $result = EndpointPrivacyChild::run($mode);
            Assert::same($result['exit'], 0);
            Assert::false($result['stdout_leaked'] || $result['stderr_leaked']);
            Assert::same($result['stderr_bytes'], 0);
            foreach ($result['records'] as $row) {
                if (!(($row['leaked'] ?? null) !== null)) { continue; }
 $row = \Tablo\Tests\Support\EndpointPrivacyRecords::probe($row);
                    Assert::false($row['leaked']); Assert::false($row['previous']);
            }
            switch (true) {
case ($mode === 'redirect-native'):
                $records = \Tablo\Tests\Support\EndpointPrivacyRecords::native($result['records']);

                foreach ([$records[0], $records[2]] as $record) {
                    $row = \Tablo\Tests\Support\EndpointPrivacyRecords::probe($record);
                    $row['handle_released'] = \Tablo\Tests\Support\FixtureJson::boolean($row, 'handle_released');
                    $row['callback_released'] = \Tablo\Tests\Support\FixtureJson::boolean($row, 'callback_released');
                    Assert::true($row['typed']); Assert::same($row['reason'], 'check-error');
                    Assert::true($row['handle_released'] && $row['callback_released']);
                }
                Assert::true($records[0]['original_typeerror'] && $records[0]['original_leaked']);
                Assert::true($records[3]['raw_callback_leaked']);

    break;
default:
                $records = \Tablo\Tests\Support\EndpointPrivacyRecords::safe($result['records']);

                Assert::true($records[8]['raw_control_leaked']);
                Assert::true($records[9]['private_previous_control_leaked']);
                Assert::false($records[9]['opaque_complete']);

    break;
}
        }
    }

    #[Test]
    public function fatalEndpointTraceIsSecretFreeWithAnActualLeakingControl(): void
    {
        $safe = EndpointPrivacyChild::run('redirect-fatal');
        $control = EndpointPrivacyChild::run('redirect-fatal-control');
        Assert::same($safe['exit'], 255); Assert::same($control['exit'], 255);
        Assert::false($safe['stdout_leaked'] || $safe['stderr_leaked']);
        Assert::true($control['stdout_leaked'] || $control['stderr_leaked']);
    }
}
