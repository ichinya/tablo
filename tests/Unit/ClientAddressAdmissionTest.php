<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use RuntimeException;
use Tablo\ClientAddress;
use Tablo\ValidationException;
use Testo\Assert;
use Testo\Test;

final class ClientAddressAdmissionTest
{


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
            'bad,198.51.100.42,192.0.2.10', '198.51.100.42' . str_repeat(' ', times: 4096),
            '198.51.100.42' . str_repeat(',192.0.2.10', times: 32)];
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
            '[::1]', 'fe80::1%eth0', "192.0.2.10\n", str_repeat(' ', times: 4097), implode(',', array_fill(0, count: 33, value: '192.0.2.10'))] as $config) {
            $rejected = false;
            try { new ClientAddress($config); } catch (RuntimeException $error) {
                $rejected = true;
                Assert::same($error->getMessage(), ClientAddress::CONFIG_ERROR);
            }
            Assert::true($rejected, 'Invalid proxy configuration accepted');
        }
        foreach (['192.0.2.10' . str_repeat(' ', times: 4086), implode(',', array_fill(0, count: 32, value: '::ffff:192.0.2.10'))] as $config) {
            $resolver = new ClientAddress($config);
            $header = '198.51.100.42' . str_repeat(' ', times: 4083);
            Assert::same(strlen($header), 4096);
            Assert::same($resolver->resolve(['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => $header]), '198.51.100.42');
            Assert::same($resolver->resolve(['REMOTE_ADDR' => '192.0.2.10',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.42' . str_repeat(',192.0.2.10', times: 31)]), '198.51.100.42');
        }
    }
}
