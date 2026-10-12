<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\EndpointTarget;
use Tablo\HttpFailure;
use Testo\Assert;
use Testo\Test;

final class EndpointRedirectPolicyTest extends EndpointPolicyFixtureCase
{

    #[Test]
    public function resolvesRfc3986ReferencesWithoutChangingEncodedSeparatorsOrQueryPresence(): void
    {
        $base = EndpointTarget::parse('HTTPS://Example.COM/a/b?old=1');
        foreach (['/up/' => '/up/', 'version' => '/a/version', '../up' => '/up', './version' => '/a/version',
            '?new=1' => '/a/b?new=1', '?' => '/a/b?', '//example.com:443/up' => '/up',
            '../../g' => '/g', '../../../g' => '/g', '/./g' => '/g', '/../g' => '/g',
            'g/./h' => '/a/g/h', 'g/../h' => '/a/h', 'g?y/../x' => '/a/g?y/../x',
            '%2e%2e/%2f' => '/a/%2e%2e/%2f', '/a//b' => '/a//b'] as $reference => $suffix) {
            Assert::same(EndpointTarget::redirect($base, $reference)['url'], 'https://example.com:443' . $suffix);
        }
        Assert::same(EndpointTarget::parse('http://example.com')['origin'], EndpointTarget::parse('HTTP://EXAMPLE.COM:80/')['origin']);
        Assert::same(EndpointTarget::parse('https://[2606:4700:0:0:0:0:0:1111]/')['origin'],
            EndpointTarget::parse('https://[2606:4700::1111]:443/')['origin']);
        Assert::false(EndpointTarget::parse('https://alias.example/')['origin'] === $base['origin']);
    }



    #[Test]
    public function rejectsAmbiguousAuthoritiesControlsFragmentsAndMalformedReferences(): void
    {
        $base = EndpointTarget::parse('https://example.com/a/b');
        foreach (['', '#fragment', '/up#fragment', " /up", "/up\r\nX: value", "/up\0", '/up\\next', '/bad%zz',
            'https://user@example.com/', 'http:relative', '//[::1', '//example.com:0/', '//example.com:65536/',
            '//example.com./', '//%65xample.com/', '//0177.0.0.1/', '//127.1/', '//0x7f000001/', '//2130706433/',
            '//[fe80::1%25eth0]/', '//éxample.com/', str_repeat('a', times: 8193)] as $reference) {
            $reason = null;
            try { EndpointTarget::redirect($base, $reference); } catch (HttpFailure $error) { $reason = $error->reason; }
            Assert::same($reason, 'redirect-location-invalid');
        }
    }
}
