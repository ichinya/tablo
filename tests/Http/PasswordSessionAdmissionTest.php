<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;


use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class PasswordSessionAdmissionTest extends PasswordHttpFixtureCase
{


    #[Test]
    public function legacyAndHostileMarkersFailClosedAndRotateCsrf(): void
    {
        $web = new WebFixture();
        try {
            $csrf = $web->authenticate();
            foreach ([null, 123, [], false, 'malformed', str_repeat('a', times: 64)] as $marker) {
                $file = self::sessionFile($web);
                $contents = 'authenticated_at|i:' . time() . ';csrf|' . serialize($csrf);
                if ($marker !== null) { $contents .= 'auth_fingerprint|' . serialize($marker); }
                file_put_contents($file, $contents);
                Assert::same($web->request('/setup', ['_csrf' => $csrf])['status'], 404);
                Assert::same($web->request('/')['status'], 303);
                $newCsrf = WebFixture::csrf($web->request('/login'));
                Assert::true($newCsrf !== $csrf);
                Assert::same($web->request('/login', ['_csrf' => $csrf, 'password' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password')])['status'], 419);
                Assert::same($web->request('/login', ['_csrf' => $newCsrf, 'password' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password')])['status'], 303);
                $csrf = WebFixture::csrf($web->request('/'));
            }
            $file = self::sessionFile($web);
            $contents = \Tablo\RequiredFile::bytes(file_get_contents($file));
            file_put_contents($file, preg_replace('/authenticated_at\|i:\d+;/', 'authenticated_at|i:' . (time() - 43_201) . ';', $contents));
            Assert::same($web->request('/')['status'], 303);
            Assert::true(!str_contains(\Tablo\RequiredFile::bytes(file_get_contents(self::sessionFile($web))), 'auth_fingerprint'));
            Assert::same(self::login($web, \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'))['status'], 303);
            $csrf = WebFixture::csrf($web->request('/'));
            Assert::same($web->request('/logout', ['_csrf' => $csrf])['status'], 303);
            Assert::same($web->request('/')['status'], 303);
            Assert::true(!str_contains(\Tablo\RequiredFile::bytes(file_get_contents(self::sessionFile($web))), 'auth_fingerprint'));
        } finally { $web->close(); }
    }
}
