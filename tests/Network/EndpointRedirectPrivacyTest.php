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
                if (isset($row['leaked'])) { Assert::false($row['leaked']); Assert::false($row['previous']); }
            }
            if ($mode === 'redirect-native') {
                foreach ([0, 2] as $index) {
                    $row = $result['records'][$index];
                    Assert::true($row['typed']); Assert::same($row['reason'], 'check-error');
                    Assert::true($row['handle_released'] && $row['callback_released']);
                }
                Assert::true($result['records'][0]['original_typeerror'] && $result['records'][0]['original_leaked']);
                Assert::true($result['records'][3]['raw_callback_leaked']);
            } else {
                Assert::true($result['records'][8]['raw_control_leaked']);
                Assert::true($result['records'][9]['private_previous_control_leaked']);
                Assert::false($result['records'][9]['opaque_complete']);
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
