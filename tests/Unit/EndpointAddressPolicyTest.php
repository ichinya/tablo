<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\EndpointTarget;
use Testo\Assert;
use Testo\Test;

final class EndpointAddressPolicyTest extends EndpointPolicyFixtureCase
{


    #[Test]
    public function conservativeAddressPolicyRejectsIanaSpecialMulticastMappedAndAlternateForms(): void
    {
        // Predicate evidence only; these addresses are never contacted.
        foreach (['0.1.2.3', '10.0.0.1', '100.64.0.0', '100.127.255.255', '127.0.0.1', '169.254.1.1',
            '172.16.0.1', '192.0.0.9', '192.0.2.1', '192.31.196.1', '192.52.193.1', '192.88.99.1',
            '192.168.1.1', '192.175.48.1', '198.18.0.1', '198.19.255.255', '198.51.100.1', '203.0.113.1',
            '224.0.0.1', '239.255.255.255', '240.0.0.1', '255.255.255.255', '::', '::1', '::ffff:8.8.8.8',
            '0:0:0:0:0:ffff:808:808', '64:ff9b::808:808', '100::1', '2001::1', '2001:1::1', '2001:db8::1',
            '2002::1', '2620:4f:8000::1', '3fff::1', '5f00::1', 'fc00::1', 'fe80::1', 'ff02::1',
            '127.1', '0177.0.0.1', '2130706433', '0x7f000001', 'fe80::1%eth0'] as $address) {
            Assert::false(EndpointTarget::isPublicAddress($address));
        }
        foreach (['8.8.8.8', '1.1.1.1', '100.63.255.255', '100.128.0.0', '198.17.255.255',
            '198.20.0.0', '2606:4700::1111', '2001:4860:4860::8888'] as $address) {
            Assert::true(EndpointTarget::isPublicAddress($address));
        }
    }
}
