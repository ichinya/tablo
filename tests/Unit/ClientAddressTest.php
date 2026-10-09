<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use RuntimeException;
use Tablo\ClientAddress;
use Tablo\ValidationException;
use Testo\Assert;
use Testo\Test;

final class ClientAddressTest
{
    #[Test]
    public function ignoresAllForwardingWithoutImmediatePeerAuthority(): void
    {
        foreach ([false, '', '192.0.2.10'] as $configuration) {
            $resolver = new ClientAddress($configuration);
            foreach ([null, [], '', 'bad', str_repeat('x', 4097), "a\r\nb"] as $header) {
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

    #[Test]
    public function refusesTheEntireInvalidOrClientlessTrustedChain(): void
    {
        $resolver = new ClientAddress('192.0.2.10');
        $headers = [null, [], '', ' ', "\t", ',', '198.51.100.42,', ',198.51.100.42',
            '198.51.100.42,,192.0.2.10', '999.1.1.1', '127.1', '0127.0.0.1', 'example.test', 'unknown',
            '_hidden', '"198.51.100.42"', '[2001:db8::42]', '198.51.100.42:80', '[::1]:80',
            'fe80::1%eth0', '198.51.100.0/24', "198.51.100.42\0", "198.51.100.42\r\n", "198.51.100.42\v",
            "198.51.100.42\xff", '192.0.2.10', '192.0.2.10,192.0.2.10',
            // Invalid unauthoritative history must not be skipped.
            'bad,198.51.100.42,192.0.2.10', '198.51.100.42' . str_repeat(' ', 4096),
            '198.51.100.42' . str_repeat(',192.0.2.10', 32)];
        foreach ($headers as $header) {
            $rejected = false;
            try {
                $resolver->resolve(['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => $header]);
            } catch (ValidationException $error) {
                $rejected = true;
                Assert::same($error->errors, ['password' => ClientAddress::HEADER_ERROR]);
            }
            Assert::true($rejected, 'Invalid trusted chain accepted');
        }
    }

    #[Test]
    public function validatesAllConfigurationAndExactByteAndHopLimits(): void
    {
        foreach ([' ', "\t", ',', '192.0.2.10,', ',192.0.2.10', '192.0.2.10,bad', '0', '0.0.0.0', '::',
            '::ffff:0.0.0.0', '*', '192.0.2.0/24', 'localhost', 'http://192.0.2.10', '192.0.2.10:80',
            '[::1]', 'fe80::1%eth0', "192.0.2.10\n", str_repeat(' ', 4097), implode(',', array_fill(0, 33, '192.0.2.10'))] as $config) {
            $rejected = false;
            try { new ClientAddress($config); } catch (RuntimeException $error) {
                $rejected = true;
                Assert::same($error->getMessage(), ClientAddress::CONFIG_ERROR);
            }
            Assert::true($rejected, 'Invalid proxy configuration accepted');
        }
        foreach (['192.0.2.10' . str_repeat(' ', 4086), implode(',', array_fill(0, 32, '::ffff:192.0.2.10'))] as $config) {
            $resolver = new ClientAddress($config);
            $header = '198.51.100.42' . str_repeat(' ', 4083);
            Assert::same(strlen($header), 4096);
            Assert::same($resolver->resolve(['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => $header]), '198.51.100.42');
            Assert::same($resolver->resolve(['REMOTE_ADDR' => '192.0.2.10',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.42' . str_repeat(',192.0.2.10', 31)]), '198.51.100.42');
        }
    }
}
