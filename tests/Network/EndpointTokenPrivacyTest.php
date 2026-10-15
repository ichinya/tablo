<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\EndpointPrivacyChild;
use Testo\Assert;
use Testo\Test;

final class EndpointTokenPrivacyTest
{
    #[Test]
    public function actualReturnedTracesAndNativeExceptionsAreBoundedWithRealRedControls(): void
    {
        foreach (['safe', 'native'] as $mode) {
            $result = EndpointPrivacyChild::run($mode);
            Assert::same($result['exit'], 0);
            Assert::false($result['stdout_leaked'] || $result['stderr_leaked']);
            Assert::same($result['stderr_bytes'], 0);
            foreach ($result['records'] as $row) {
                if (!(array_key_exists('leaked', $row))) { continue; }

                    Assert::false($row['leaked']);
                    Assert::false($row['previous']);

            }
            switch (true) {
case ($mode === 'native'):

                $row = $result['records'][0];
                Assert::true($row['typed'] && $row['reason'] === 'check-error');
                Assert::true($row['original_typeerror'] && $row['original_leaked']);
                Assert::true($row['handle_released'] && $row['callback_released']);
                Assert::true($result['records'][1]['external_native_typeerror'] && $result['records'][1]['external_native_leaked']);
                Assert::true($result['records'][2]['typed'] && $result['records'][2]['reason'] === 'check-error');
                Assert::true($result['records'][2]['handle_released'] && $result['records'][2]['callback_released']);
                Assert::true($result['records'][3]['raw_callback_typeerror'] && $result['records'][3]['raw_callback_leaked']);

    break;
default:

                Assert::true($result['records'][8]['raw_control_leaked']);
                Assert::true($result['records'][12]['raw_sql_control_leaked']);
                Assert::true($result['records'][9]['private_previous_control_leaked']);
                Assert::false($result['records'][9]['opaque_complete']);

    break;
}
        }
    }

    #[Test]
    public function fatalStreamsHaveActualChild255AndSafeObservationsOnly(): void
    {
        $fatal = EndpointPrivacyChild::run('fatal');
        Assert::same($fatal['exit'], 255);
        Assert::false($fatal['stdout_leaked'] || $fatal['stderr_leaked']);
        $control = EndpointPrivacyChild::run('fatal-control');
        Assert::same($control['exit'], 255);
        Assert::true($control['stdout_leaked'] || $control['stderr_leaked']);
    }

}
