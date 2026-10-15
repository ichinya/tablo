<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\ClientAddress;
use Testo\Assert;
use Testo\Test;

final class ClientAddressTest
{


    #[Test]
    public function ignoresAllForwardingWithoutImmediatePeerAuthority(): void
    {
        foreach ([false, '', '192.0.2.10'] as $configuration) {
            $resolver = new ClientAddress($configuration);
            foreach ([null, [], '', 'bad', str_repeat('x', times: 4097), \Tablo\Tests\Support\FixtureCredentials::invalidHeader(label: 'header-injection')] as $header) {
                Assert::same($resolver->resolve(['REMOTE_ADDR' => '::FFFF:C000:201',
                    'HTTP_X_FORWARDED_FOR' => $header, 'HTTP_FORWARDED' => 'for=198.51.100.42',
                    'HTTP_X_REAL_IP' => '198.51.100.42', 'HTTP_CLIENT_IP' => '198.51.100.42']), '192.0.2.1');
            }
            foreach ([null, [], '', 'unknown', '127.1', '0127.0.0.1', '192.0.2.1:80', '[::1]', 'fe80::1%eth0', "::1\n"] as $peer) {
                Assert::same($resolver->resolve(['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => '198.51.100.42']), 'unknown');
            }
            Assert::same($resolver->resolve([]), 'unknown');
        }
        Assert::same((new ClientAddress())->resolve(['REMOTE_ADDR' => '2001:0DB8:0:0:0:0:0:0042']), '2001:db8::42');
    }



    #[Test]
    public function selectsByTrustFromTheRightWithoutDroppingRepeatedHops(): void
    {
        $resolver = new ClientAddress('192.0.2.10,192.0.2.20,2001:db8::10');
        $cases = [
            ['192.0.2.10', '198.51.100.42', '198.51.100.42'],
            ['192.0.2.20', '203.0.113.99,198.51.100.42,192.0.2.10', '198.51.100.42'],
            ['192.0.2.20', '192.0.2.10,203.0.113.1,192.0.2.10', '203.0.113.1'],
            ['192.0.2.20', '198.51.100.42,192.0.2.11', '192.0.2.11'],
            ['192.0.2.20', '198.51.100.42,192.0.2.10,192.0.2.10', '198.51.100.42'],
            ['2001:0DB8:0:0:0:0:0:0010', " \t2001:0DB8:0:0:0:0:0:0042\t ,192.0.2.10\t", '2001:db8::42'],
            ['::ffff:c000:214', '::ffff:198.51.100.42,::ffff:c000:20a', '198.51.100.42'],
            ['192.0.2.10', '::192.0.2.1', '::192.0.2.1'],
            ['192.0.2.10', '64:ff9b::c000:201', '64:ff9b::c000:201'],
        ];
        foreach ($cases as [$peer, $header, $expected]) {
            Assert::same($resolver->resolve(['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $header,
                'HTTP_X_REAL_IP' => '203.0.113.123', 'HTTP_FORWARDED' => 'for=203.0.113.123']), $expected);
        }
        Assert::same((new ClientAddress('::ffff:192.0.2.10'))->resolve([
            'REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '::FFFF:C633:642A']), '198.51.100.42');
    }
}
